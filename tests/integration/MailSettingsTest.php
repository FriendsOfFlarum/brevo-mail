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

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

class MailSettingsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-brevo-mail');
    }

    protected function mailSettings(): array
    {
        $response = $this->send(
            $this->request('GET', '/api/mail/settings', [
                'authenticatedAs' => 1,
            ])
        );

        $body = $response->getBody()->getContents();

        $this->assertEquals(200, $response->getStatusCode(), $body);

        return json_decode($body, true)['data']['attributes'];
    }

    #[Test]
    public function brevo_driver_is_offered_with_its_api_key_field(): void
    {
        $fields = $this->mailSettings()['fields'];

        $this->assertArrayHasKey('brevo', $fields);
        $this->assertEquals(['mail_brevo_api_key' => ''], $fields['brevo']);
    }
}
