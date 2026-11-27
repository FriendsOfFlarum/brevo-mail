<?php

/*
 * This file is part of fof/brevo-mail.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\BrevoMail\Tests\integration;

use Flarum\Mail\EmailBounceEvent;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

class WebhookTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('fof-brevo-mail');
        $this->setting('mail_driver', 'brevo');
        $this->setting('mail_brevo_api_key', 'xkeysib-test');
        $this->setting('mail_webhook_username', 'forum-webhooks');
        $this->setting('mail_webhook_password', 'forum-specific-password');
        $this->prepareDatabase(['users' => [$this->normalUser()]]);
    }

    private function payload(string $event = 'hard_bounce', string $email = 'normal@machine.local'): array
    {
        return ['event' => $event, 'email' => $email, 'message-id' => 'message-1',
            'ts_event' => time(), 'reason' => 'Delivery failed', 'tags' => ['forum']];
    }

    private function post(array $payload, ?string $credentials = 'forum-webhooks:forum-specific-password', string $ip = '192.0.2.10'): ResponseInterface
    {
        $body = new Stream('php://temp', 'wb+');
        $body->write(json_encode($payload));
        $body->rewind();
        $request = (new ServerRequest(['REMOTE_ADDR' => $ip], [], '/mail/webhook/brevo', 'POST'))
            ->withHeader('Content-Type', 'application/json')->withBody($body)->withParsedBody($payload);
        if ($credentials !== null) {
            $request = $request->withHeader('Authorization', 'Basic '.base64_encode($credentials));
        }

        return $this->send($request);
    }

    #[Test]
    public function authentication_works_behind_a_proxy_and_rejects_other_accounts(): void
    {
        foreach ([null, 'other:password'] as $credentials) {
            $this->assertEquals(403, $this->post($this->payload(), $credentials, '1.179.112.1')->getStatusCode());
            $this->assertFalse(User::find(2)->hasBouncedEmail());
            $this->assertEquals(0, EmailBounceEvent::count());
        }
        $this->assertEquals(200, $this->post($this->payload())->getStatusCode());
        $this->assertTrue(User::find(2)->hasBouncedEmail());
    }

    #[Test]
    public function colons_in_usernames_leave_webhooks_unconfigured(): void
    {
        $this->setting('mail_webhook_username', 'bad:user');
        $this->assertEquals(404, $this->post($this->payload())->getStatusCode());
    }

    #[Test]
    public function special_characters_work_in_credentials_and_are_escaped_in_the_url(): void
    {
        $this->setting('mail_webhook_username', '2hooks');
        $this->setting('mail_webhook_password', 'p@ss:word/with%chars');
        $url = $this->app()->getContainer()->make(\Flarum\Mail\MailWebhooks::class)->url();
        $this->assertSame('http://2hooks:p%40ss%3Aword%2Fwith%25chars@localhost/mail/webhook/brevo', $url);
        $this->assertEquals(200, $this->post($this->payload(), '2hooks:p@ss:word/with%chars')->getStatusCode());
        $this->assertTrue(User::find(2)->hasBouncedEmail());
    }

    #[Test]
    public function missing_credentials_leave_webhooks_unconfigured(): void
    {
        $this->setting('mail_webhook_password', '');
        $this->assertEquals(404, $this->post($this->payload())->getStatusCode());
        $this->assertEquals(0, EmailBounceEvent::count());
    }

    public static function events(): array
    {
        return [['hard_bounce', true], ['invalid_email', true], ['spam', true],
            ['soft_bounce', false], ['error', false], ['blocked', false]];
    }

    #[Test]
    #[DataProvider('events')]
    public function only_permanent_recipient_failures_and_complaints_flag_users(string $event, bool $flags): void
    {
        $response = $this->post($this->payload($event));
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame($flags, User::find(2)->hasBouncedEmail());
        $this->assertEquals($flags ? 1 : 0, EmailBounceEvent::count());
        if ($event === 'spam') {
            $this->assertSame('complaint', EmailBounceEvent::firstOrFail()->type);
        }
    }

    #[Test]
    public function retries_do_not_reflag_a_cleared_user(): void
    {
        $payload = $this->payload();
        $this->assertEquals(200, $this->post($payload)->getStatusCode());
        $user = User::find(2);
        $user->clearEmailBounce()->save();
        $this->assertEquals(200, $this->post($payload)->getStatusCode());
        $this->assertFalse(User::find(2)->hasBouncedEmail());
        $this->assertEquals(1, EmailBounceEvent::count());
    }
}
