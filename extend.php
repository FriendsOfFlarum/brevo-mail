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

use Flarum\Extend;

return [
    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Mail())
        ->driver('brevo', BrevoDriver::class),
];
