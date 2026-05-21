<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Inbox;

use Grav\Plugin\FediversePublisher\Inbox\Activities\FollowHandler;
use Grav\Plugin\FediversePublisher\Inbox\Activities\UndoFollowHandler;
use Grav\Plugin\FediversePublisher\Signature\MediaType;
use Grav\Plugin\FediversePublisher\Signature\Verifier;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * POST /activitypub/inbox
 *
 * Order of operations:
 *   1. Bounded body read (R3-4) — 1 MiB + 1 byte cap, reject 413.
 *      `Content-Length` is treated as a hint only.
 *   2. Strict Content-Type parse — must be AP-flavoured. Reject 415.
 *   3. JSON decode — reject 400 on parse failure.
 *   4. Verifier::verify — runs the 9-step signature pipeline. Returns
 *      VerificationResult with the final HTTP status.
 *   5. On verified+fresh: dispatch by activity.type.
 *      v0.1 acts on `Follow` and `Undo` (of Follow). Anything else is
 *      silently 202.
 */
final class InboxController
{
    public const MAX_BODY = 1_048_576;   // 1 MiB

    public function __construct(
        private readonly Verifier $verifier,
        private readonly FollowHandler $followHandler,
        private readonly UndoFollowHandler $undoHandler,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $contentType = $request->getHeaderLine('Content-Type');
        if (!MediaType::isActivityPubJson($contentType)) {
            return $this->jsonError(415, 'unsupported media type');
        }

        $body = $this->readCapped($request->getBody());
        if ($body === null) {
            return $this->jsonError(413, 'payload too large');
        }

        $activity = json_decode($body, true);
        if (!\is_array($activity)) {
            return $this->jsonError(400, 'malformed json');
        }

        $result = $this->verifier->verify($request, $body, $activity);
        if ($result->status === 202 && $result->activity === null) {
            return new Response(202, [], '');     // duplicate — silently drop
        }
        if ($result->status !== 200) {
            return $this->jsonError($result->status, 'inbox rejected');
        }

        $type = strtolower((string) ($result->activity['type'] ?? ''));
        return match ($type) {
            'follow' => $this->followHandler->handle($result->activity, $result->verifiedKey),
            'undo'   => $this->undoHandler->handle($result->activity, $result->verifiedKey),
            default  => new Response(202, [], ''),
        };
    }

    /**
     * Read up to MAX_BODY bytes. Returns null if the body would
     * overflow (so the caller can 413 without exposing the actual
     * size).
     */
    private function readCapped(StreamInterface $body): ?string
    {
        try {
            $body->rewind();
        } catch (\Throwable) {
            // Non-rewindable stream — read from where we are; PSR-7
            // streams from PHP-FPM are usually rewindable, but be
            // tolerant.
        }
        $payload = '';
        while (!$body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk === '') {
                break;
            }
            $payload .= $chunk;
            if (\strlen($payload) > self::MAX_BODY) {
                return null;
            }
        }
        return $payload;
    }

    private function jsonError(int $status, string $message): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/activity+json; charset=utf-8'],
            (string) json_encode(['error' => $message]),
        );
    }
}
