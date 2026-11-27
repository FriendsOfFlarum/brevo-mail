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
use Laminas\Diactoros\Uri;
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

        $endpoint = (new Uri($url))->withUserInfo('');
        if ($endpoint->getScheme() !== 'https') {
            throw new RuntimeException('Webhook authentication requires an HTTPS callback URL.');
        }

        $client = $this->apiClient($settings);
        $webhooks = $this->webhooksFor($client, $url);
        $authorization = ['key' => 'Authorization', 'value' => 'Basic '.base64_encode($this->getWebhookSigningSecret($settings))];
        $configuration = [
            'url' => (string) $endpoint,
            'events' => self::WEBHOOK_EVENTS,
            'batched' => false,
            // Keep special characters out of Brevo's notify-URL validation.
            'headers' => [$authorization],
        ];

        if ($webhooks !== []) {
            $first = array_shift($webhooks);
            $headers = [];
            foreach ([$first, ...$webhooks] as $webhook) {
                foreach ($webhook['headers'] as $header) {
                    $key = strtolower($header['key']);
                    if ($key === 'authorization') {
                        continue;
                    }
                    if (isset($headers[$key]) && $headers[$key]['value'] !== $header['value']) {
                        throw new RuntimeException('Existing webhooks have conflicting headers. Reconcile them in Brevo before registering again.');
                    }
                    $headers[$key] = $header;
                }
            }
            $configuration['headers'] = [...array_values($headers), $authorization];
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
     * Ignore credentials so rotating them does not create a second hook.
     *
     * @return list<array{id: int, url: string, headers: list<array{key: string, value: string}>}>
     */
    protected function webhooksFor(Client $client, string $url): array
    {
        $endpoint = fn (string $url) => (string) (new Uri($url))->withUserInfo('');

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
        $expected = $endpoint($url);

        foreach (json_decode((string) $response->getBody(), true)['webhooks'] ?? [] as $webhook) {
            try {
                $matches = $endpoint((string) ($webhook['url'] ?? '')) === $expected;
            } catch (\InvalidArgumentException) {
                continue;
            }
            if ($matches) {
                $found[] = ['id' => (int) $webhook['id'], 'url' => (string) $webhook['url'], 'headers' => $webhook['headers'] ?? []];
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
