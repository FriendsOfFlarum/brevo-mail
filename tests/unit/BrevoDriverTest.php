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

use FoF\BrevoMail\BrevoDriver;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\unit\TestCase;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidatorFactory;
use Mockery as m;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoApiTransport;

class BrevoDriverTest extends TestCase
{
    private BrevoDriver $driver;
    private ValidatorFactory $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new BrevoDriver();
        $this->validator = new ValidatorFactory(new Translator(new ArrayLoader(), 'en'));
    }

    private function settings(array $values): SettingsRepositoryInterface
    {
        $settings = m::mock(SettingsRepositoryInterface::class);
        $settings->shouldReceive('all')->andReturn($values);
        $settings->shouldReceive('get')->andReturnUsing(fn ($key) => $values[$key] ?? null);

        return $settings;
    }

    #[Test]
    public function it_offers_an_api_key_text_field(): void
    {
        $this->assertSame(['mail_brevo_api_key' => ''], $this->driver->availableSettings());
    }

    #[Test]
    public function it_can_send(): void
    {
        $this->assertTrue($this->driver->canSend());
    }

    #[Test]
    public function validation_fails_without_an_api_key(): void
    {
        $errors = $this->driver->validate($this->settings([]), $this->validator);

        $this->assertTrue($errors->has('mail_brevo_api_key'));
    }

    #[Test]
    public function validation_fails_when_the_api_key_contains_whitespace(): void
    {
        $errors = $this->driver->validate($this->settings(['mail_brevo_api_key' => 'xkeysib-abc 123']), $this->validator);

        $this->assertTrue($errors->has('mail_brevo_api_key'));
    }

    #[Test]
    public function it_builds_the_brevo_api_transport(): void
    {
        $transport = $this->driver->buildTransport($this->settings(['mail_brevo_api_key' => 'xkeysib-abc123']));

        $this->assertInstanceOf(BrevoApiTransport::class, $transport);
        $this->assertSame('brevo+api://api.brevo.com', (string) $transport);
    }
}
