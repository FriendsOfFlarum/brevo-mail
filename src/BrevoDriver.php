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
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Support\MessageBag;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

class BrevoDriver implements DriverInterface
{
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
}
