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
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Support\MessageBag;
use Symfony\Component\Mailer\Bridge\Brevo\RemoteEvent\BrevoPayloadConverter;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Bridge\Brevo\Webhook\BrevoRequestParser;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerDeliveryEvent;
use Symfony\Component\Webhook\Client\RequestParserInterface;

class BrevoDriver implements DriverInterface, HandlesWebhooks, ProvisionsWebhooks
{
    protected const WEBHOOK_EVENTS = ['hardBounce', 'invalid', 'blocked', 'spam'];

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
        return new BrevoWebhookParser(new BrevoRequestParser(new BrevoPayloadConverter()));
    }

    public function getWebhookSigningSecret(SettingsRepositoryInterface $settings): ?string
    {
        return null;
    }

    public function webhookConfigured(SettingsRepositoryInterface $settings): bool
    {
        return true;
    }

    public function webhooksAvailable(SettingsRepositoryInterface $settings): bool
    {
        return true;
    }

    public function isPermanentFailure(MailerDeliveryEvent $event): bool
    {
        return in_array($event->getPayload()['event'] ?? null, ['hard_bounce', 'invalid_email', 'blocked'], true);
    }

    public function registerWebhook(SettingsRepositoryInterface $settings, string $url): void
    {
        $client = $this->apiClient($settings);

        foreach ($this->webhooksFor($client, $url) as $webhook) {
            if ($webhook['url'] === $url) {
                return;
            }

            $client->request('DELETE', 'webhooks/'.$webhook['id']);
        }

        $client->request('POST', 'webhooks', [
            'json' => [
                'url' => $url,
                'description' => 'Flarum bounce handling',
                'events' => self::WEBHOOK_EVENTS,
                'type' => 'transactional',
            ],
        ]);
    }

    public function unregisterWebhook(SettingsRepositoryInterface $settings, string $url): void
    {
        $client = $this->apiClient($settings);

        foreach ($this->webhooksFor($client, $url) as $webhook) {
            $client->request('DELETE', 'webhooks/'.$webhook['id']);
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

        $response = $client->request('GET', 'webhooks', ['query' => ['type' => 'transactional']]);
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
            'headers' => [
                'api-key' => $settings->get('mail_brevo_api_key'),
                'accept' => 'application/json',
            ],
        ]);
    }
}
