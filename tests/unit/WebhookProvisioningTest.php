<?php

/*
 * This file is part of fof/brevo-mail.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\BrevoMail\Tests\unit;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\unit\TestCase;
use FoF\BrevoMail\BrevoDriver;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Mockery as m;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class WebhookProvisioningTest extends TestCase
{
    private const URL = 'https://forum:secret@forum.example/mail/webhook/brevo';
    private const OLD = 'https://old:password@forum.example/mail/webhook/brevo';
    private const OTHER = 'https://other.example/webhook';

    private array $history = [];

    private function driver(Response ...$responses): BrevoDriver
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new class($stack) extends BrevoDriver {
            public function __construct(protected HandlerStack $stack)
            {
            }

            protected function apiClient(SettingsRepositoryInterface $settings): Client
            {
                return new Client(['handler' => $this->stack, 'base_uri' => 'https://api.brevo.com/v3/']);
            }
        };
    }

    private function settings(bool $configured = true): SettingsRepositoryInterface
    {
        $values = ['mail_webhook_username' => 'forum', 'mail_webhook_password' => $configured ? 'secret' : ''];
        $settings = m::mock(SettingsRepositoryInterface::class);
        $settings->shouldReceive('get')->andReturnUsing(fn ($key) => $values[$key] ?? null);

        return $settings;
    }

    private function hooks(array ...$hooks): Response
    {
        return new Response(200, [], json_encode(['webhooks' => $hooks]));
    }

    private function requests(): array
    {
        return array_map(fn ($entry) => $entry['request']->getMethod().' '.$entry['request']->getUri()->getPath(), $this->history);
    }

    #[Test]
    public function percent_credentials_use_an_authorization_header_on_create_and_update(): void
    {
        $username = 'forum%user';
        $password = 'pass%40@:/# word';
        $endpoint = 'https://forum.example:8443/community/mail/webhook/brevo?source=mail';
        $url = 'https://'.rawurlencode($username).':'.rawurlencode($password).'@'.substr($endpoint, 8);
        $settings = m::mock(SettingsRepositoryInterface::class);
        $values = ['mail_webhook_username' => $username, 'mail_webhook_password' => $password];
        $settings->shouldReceive('get')->andReturnUsing(fn ($key) => $values[$key] ?? null);
        $driver = $this->driver($this->hooks(), new Response(201), $this->hooks(['id' => 1, 'url' => $endpoint]), new Response(204));

        $driver->registerWebhook($settings, $url);
        $driver->registerWebhook($settings, $url);

        $this->assertSame(['GET /v3/webhooks', 'POST /v3/webhooks', 'GET /v3/webhooks', 'PUT /v3/webhooks/1'], $this->requests());
        foreach ([1, 3] as $index) {
            $payload = json_decode((string) $this->history[$index]['request']->getBody(), true);
            $this->assertSame($endpoint, $payload['url']);
            $this->assertSame([['key' => 'Authorization', 'value' => 'Basic '.base64_encode($username.':'.$password)]], $payload['headers']);
        }
    }

    #[Test]
    public function creating_a_hook_keeps_other_applications_and_disables_batching(): void
    {
        $driver = $this->driver($this->hooks(['id' => 9, 'url' => self::OTHER]), new Response(201));
        $driver->registerWebhook($this->settings(), self::URL);
        $this->assertSame(['GET /v3/webhooks', 'POST /v3/webhooks'], $this->requests());
        $this->assertSame('type=transactional', $this->history[0]['request']->getUri()->getQuery());
        $payload = json_decode((string) $this->history[1]['request']->getBody(), true);
        $this->assertSame('https://forum.example/mail/webhook/brevo', $payload['url']);
        $this->assertSame([['key' => 'Authorization', 'value' => 'Basic '.base64_encode('forum:secret')]], $payload['headers']);
        $this->assertSame(['hardBounce', 'invalid', 'spam'], $payload['events']);
        $this->assertFalse($payload['batched']);
        $this->assertSame('transactional', $payload['type']);
    }

    #[Test]
    public function registration_creates_the_first_hook_when_brevo_reports_no_records(): void
    {
        $driver = $this->driver(
            new Response(400, [], '{"code":"document_not_found","message":"Webhook record does not exist"}'),
            new Response(201)
        );
        $driver->registerWebhook($this->settings(), self::URL);
        $this->assertSame(['GET /v3/webhooks', 'POST /v3/webhooks'], $this->requests());
    }

    #[Test]
    public function unregistering_with_no_records_is_harmless(): void
    {
        $driver = $this->driver(new Response(400, [], '{"code":"document_not_found","message":"Webhook record does not exist"}'));
        $driver->unregisterWebhook($this->settings(), self::URL);
        $this->assertSame(['GET /v3/webhooks'], $this->requests());
    }

    #[Test]
    public function other_listing_errors_do_not_create_a_hook(): void
    {
        foreach ([
            new Response(400, [], '{"code":"invalid_parameter"}'),
            new Response(401, [], '{"code":"document_not_found"}'),
            new Response(404, [], '{"code":"document_not_found"}'),
            new Response(400, [], 'not json'),
        ] as $response) {
            $this->history = [];
            try {
                $this->driver($response)->registerWebhook($this->settings(), self::URL);
                $this->fail('The provider error must reach the caller.');
            } catch (ClientException $e) {
                $this->assertSame($response, $e->getResponse());
                $this->assertSame(['GET /v3/webhooks'], $this->requests());
            }
        }
    }

    #[Test]
    public function an_existing_hook_gets_current_credentials_subscriptions_and_format(): void
    {
        $hooks = [['id' => 9, 'url' => self::OTHER], ['id' => 1, 'url' => self::OLD, 'events' => ['delivered'], 'batched' => true]];
        $driver = $this->driver($this->hooks(...$hooks), new Response(204), $this->hooks(...$hooks), new Response(204));
        $driver->registerWebhook($this->settings(), self::URL);
        $driver->registerWebhook($this->settings(), self::URL);
        $this->assertSame(['GET /v3/webhooks', 'PUT /v3/webhooks/1', 'GET /v3/webhooks', 'PUT /v3/webhooks/1'], $this->requests());
        $this->assertSame('type=transactional', $this->history[0]['request']->getUri()->getQuery());
        $payload = json_decode((string) $this->history[1]['request']->getBody(), true);
        $this->assertSame([
            'url' => 'https://forum.example/mail/webhook/brevo',
            'events' => ['hardBounce', 'invalid', 'spam'],
            'batched' => false,
            'headers' => [['key' => 'Authorization', 'value' => 'Basic '.base64_encode('forum:secret')]],
        ], $payload);
    }

    #[Test]
    public function a_failed_update_does_not_delete_the_existing_hook(): void
    {
        $driver = $this->driver($this->hooks(['id' => 1, 'url' => self::OLD]), new Response(500));
        try {
            $driver->registerWebhook($this->settings(), self::URL);
            $this->fail('The provider error must reach the caller.');
        } catch (ServerException) {
            $this->assertSame(['GET /v3/webhooks', 'PUT /v3/webhooks/1'], $this->requests());
        }
    }

    #[Test]
    public function duplicates_are_removed_only_after_the_primary_hook_is_updated(): void
    {
        $driver = $this->driver($this->hooks(['id' => 1, 'url' => self::OLD], ['id' => 2, 'url' => self::URL]), new Response(204), new Response(204));
        $driver->registerWebhook($this->settings(), self::URL);
        $this->assertSame(['GET /v3/webhooks', 'PUT /v3/webhooks/1', 'DELETE /v3/webhooks/2'], $this->requests());
    }

    #[Test]
    public function unregistering_keeps_other_applications_and_missing_hooks_are_harmless(): void
    {
        $driver = $this->driver($this->hooks(['id' => 9, 'url' => self::OTHER], ['id' => 1, 'url' => self::OLD]), new Response(204), $this->hooks());
        $driver->unregisterWebhook($this->settings(), self::URL);
        $driver->unregisterWebhook($this->settings(), self::URL);
        $this->assertSame(['GET /v3/webhooks', 'DELETE /v3/webhooks/1', 'GET /v3/webhooks'], $this->requests());
    }

    #[Test]
    public function unregistering_a_hook_deleted_by_another_client_is_harmless(): void
    {
        $driver = $this->driver($this->hooks(['id' => 1, 'url' => self::OLD]), new Response(404));
        $driver->unregisterWebhook($this->settings(), self::URL);
        $this->assertSame(['GET /v3/webhooks', 'DELETE /v3/webhooks/1'], $this->requests());
    }

    #[Test]
    public function missing_credentials_refuse_registration_before_an_api_call(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Configure webhook authentication');
        $this->driver()->registerWebhook($this->settings(false), self::URL);
    }
}
