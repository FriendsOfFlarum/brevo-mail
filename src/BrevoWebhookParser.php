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

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Client\RequestParserInterface;

/**
 * symfony/brevo-mailer, up to 7.4.13, 8.0.13 and 8.1.0, rejects the "spam" event
 * of transactional webhooks, so it is renamed to "complaint", which it accepts.
 */
class BrevoWebhookParser implements RequestParserInterface
{
    public function __construct(
        protected RequestParserInterface $parser
    ) {
    }

    public function parse(Request $request, #[\SensitiveParameter] string $secret): RemoteEvent|array|null
    {
        $payload = json_decode($request->getContent(), true);

        if (is_array($payload) && ($payload['event'] ?? null) === 'spam') {
            $payload['event'] = 'complaint';

            $request = new Request(
                $request->query->all(),
                $request->request->all(),
                $request->attributes->all(),
                $request->cookies->all(),
                $request->files->all(),
                $request->server->all(),
                (string) json_encode($payload)
            );
        }

        return $this->parser->parse($request, $secret);
    }

    public function createSuccessfulResponse(): Response
    {
        return $this->parser->createSuccessfulResponse();
    }

    public function createRejectedResponse(string $reason): Response
    {
        return $this->parser->createRejectedResponse($reason);
    }
}
