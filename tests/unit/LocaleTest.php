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

use Flarum\Testing\unit\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;

class LocaleTest extends TestCase
{
    #[Test]
    public function mail_page_keys_are_provided_in_the_namespace_core_reads(): void
    {
        $email = Yaml::parseFile(__DIR__.'/../../locale/en.yml')['core']['admin']['email'];

        $this->assertArrayHasKey('brevo_heading', $email);
        $this->assertArrayHasKey('brevo_description', $email);
        $this->assertArrayHasKey('mail_brevo_api_key_label', $email);
        $this->assertArrayHasKey('mail_brevo_api_key_help', $email);
    }
}
