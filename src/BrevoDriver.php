<?php

/*
 * This file is part of fof/brevo-mail.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\BrevoMail;

use Flarum\Mail\DriverInterface;
use Flarum\Mail\HandlesWebhooks;
use Flarum\Mail\ProvisionsWebhooks;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Support\MessageBag;
use RuntimeException;
use Symfony\Component\Mailer\Bridge\Brevo\RemoteEvent\BrevoPayloadConverter;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Bridge\Brevo\Webhook\BrevoRequestParser;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerDeliveryEvent;
use Symfony\Component\Webhook\Client\RequestParserInterface;

class BrevoDriver implements DriverInterface, HandlesWebhooks, ProvisionsWebhooks
{
    protected const WEBHOOK_EVENTS = ['hardBounce', 'invalid', 'spam'];

    public function availableSettings(): array
    {
        return [
            'mail_brevo_api_key' => '',
        ];
    }

    public function validate(SettingsRepositoryInterface $settings, Factory $validator): MessageBag
    {
        return $validator->make($settings->all(), [
            'mail_brevo_api_key' => ['required', 'regex:/^\S+$/'],
        ])->errors();
    }

    public function canSend(): bool
    {
        return true;
    }

    public function buildTransport(SettingsRepositoryInterface $settings): TransportInterface
    {
        return (new BrevoTransportFactory())->create(new Dsn(
            'brevo+api',
            'default',
            $settings->get('mail_brevo_api_key')
        ));
    }

    public function getWebhookRequestParser(SettingsRepositoryInterface $settings): RequestParserInterface
    {
        return new BrevoRequestParser(new BrevoPayloadConverter(), allowedIPs: []);
    }

    public function getWebhookSigningSecret(SettingsRepositoryInterface $settings): ?string
    {
        if (! $this->webhookConfigured($settings)) {
            return null;
        }

        return $settings->get('mail_webhook_username').':'.$settings->get('mail_webhook_password');
    }

    public function webhookConfigured(SettingsRepositoryInterface $settings): bool
    {
        $username = (string) $settings->get('mail_webhook_username');
        $password = (string) $settings->get('mail_webhook_password');

        return $username !== '' && $password !== '' && ! str_contains($username, ':');
    }

    public function webhooksAvailable(SettingsRepositoryInterface $settings): bool
    {
        return true;
    }

    public function isPermanentFailure(MailerDeliveryEvent $event): bool
    {
        return in_array($event->getPayload()['event'] ?? null, ['hard_bounce', 'invalid_email'], true);
    }

    public function registerWebhook(SettingsRepositoryInterface $settings, string $url): void
    {
        if (! $this->webhookConfigured($settings)) {
            throw new RuntimeException('Configure webhook authentication before registering the webhook.');
        }

        $client = $this->apiClient($settings);
        $webhooks = $this->webhooksFor($client, $url);
        $configuration = [
            'url' => (string) (new Uri($url))->withUserInfo(''),
            'events' => self::WEBHOOK_EVENTS,
            'batched' => false,
            // Keep special characters out of Brevo's notify-URL validation.
            'headers' => [['key' => 'Authorization', 'value' => 'Basic '.base64_encode($this->getWebhookSigningSecret($settings))]],
        ];

        if ($webhooks !== []) {
            $first = array_shift($webhooks);
            $client->request('PUT', 'webhooks/'.$first['id'], ['json' => $configuration]);
            foreach ($webhooks as $webhook) {
                $this->deleteWebhook($client, $webhook['id']);
            }

            return;
        }

        $client->request('POST', 'webhooks', [
            'json' => $configuration + ['description' => 'Flarum bounce handling', 'type' => 'transactional'],
        ]);
    }

    public function unregisterWebhook(SettingsRepositoryInterface $settings, string $url): void
    {
        $client = $this->apiClient($settings);

        foreach ($this->webhooksFor($client, $url) as $webhook) {
            $this->deleteWebhook($client, $webhook['id']);
        }
    }

    protected function deleteWebhook(Client $client, int $id): void
    {
        try {
            $client->request('DELETE', 'webhooks/'.$id);
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() !== 404) {
                throw $e;
            }
        }
    }

    /**
     * The transactional webhooks sending to this forum, whatever credentials their URLs carry.
     *
     * @return list<array{id: int, url: string}>
     */
    protected function webhooksFor(Client $client, string $url): array
    {
        $endpoint = fn (string $url) => preg_replace('#^([a-z][a-z0-9+.-]*://)[^/@]*@#i', '$1', $url);

        try {
            $response = $client->request('GET', 'webhooks', ['query' => ['type' => 'transactional']]);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            // Brevo reports an empty collection as 400 rather than an empty list.
            if ($response->getStatusCode() === 400 && (json_decode((string) $response->getBody(), true)['code'] ?? null) === 'document_not_found') {
                return [];
            }

            throw $e;
        }
        $found = [];

        foreach (json_decode((string) $response->getBody(), true)['webhooks'] ?? [] as $webhook) {
            if ($endpoint((string) ($webhook['url'] ?? '')) === $endpoint($url)) {
                $found[] = ['id' => (int) $webhook['id'], 'url' => (string) $webhook['url']];
            }
        }

        return $found;
    }

    protected function apiClient(SettingsRepositoryInterface $settings): Client
    {
        return new Client([
            'base_uri' => 'https://api.brevo.com/v3/',
            // Bound admin requests when the provider is unavailable.
            'connect_timeout' => 5,
            'timeout' => 15,
            'headers' => [
                'api-key' => $settings->get('mail_brevo_api_key'),
                'accept' => 'application/json',
            ],
        ]);
    }
}
