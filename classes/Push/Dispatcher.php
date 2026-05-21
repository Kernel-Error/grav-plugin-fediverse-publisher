<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Push;

use Grav\Plugin\FediversePublisher\Keys\KeyStore;
use Grav\Plugin\FediversePublisher\Signature\Clock;
use Grav\Plugin\FediversePublisher\Signature\RequestSigner;
use Grav\Plugin\FediversePublisher\Storage\FollowerStore;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface;

/**
 * Drains the push_queue: claims rows, signs the payload, POSTs to the
 * recipient inbox, classifies the result, reschedules or finalises.
 *
 * Per ADR-003 R2-1 we use a heartbeat-per-item pattern: claimed_at is
 * refreshed immediately before each delivery so a slow-but-alive
 * worker doesn't lose its claim to the reclaim sweep. Reclaim threshold
 * is 2 minutes (longer than a single delivery's 30 s timeout, shorter
 * than a realistic crashed-worker window).
 *
 * Single-actor MVP — the `actor` column on each queue row is just
 * passed through to the signer. Multi-actor selection (different
 * private keys per username) lands when ADR-004 A-6 actually goes
 * multi-actor in v0.3+.
 */
final class Dispatcher
{
    public const HEARTBEAT_RECLAIM_SECONDS = 120;
    public const BATCH_SIZE                = 20;
    public const BATCH_WALLCLOCK_SECONDS   = 5;
    public const CONNECT_TIMEOUT_SECONDS   = 10;
    public const TOTAL_TIMEOUT_SECONDS     = 30;

    public function __construct(
        private readonly OutboundQueue $queue,
        private readonly RequestSigner $signer,
        private readonly KeyStore $keys,
        private readonly FollowerStore $followers,
        private readonly RetryPolicy $retryPolicy,
        private readonly FailureClassifier $classifier,
        private readonly ClientInterface $http,
        private readonly Clock $clock,
        private readonly LoggerInterface $log,
        private readonly string $localActorUrl,
        private readonly string $localKeyUsername,         // matches KeyStore key file
        /** @var list<string> CIDR allow-list for SSRF block bypass (dev only) */
        private readonly array $allowedReservedCidrs = [],
    ) {
    }

    /**
     * Drain at most one tick's worth of work. Safe to call from a
     * scheduler job or a CLI command. Returns counts for observability.
     *
     * @return array{processed:int, success:int, retried:int, dead:int, stale:int}
     */
    public function tick(): array
    {
        $reclaimed = $this->queue->reclaimStuck(self::HEARTBEAT_RECLAIM_SECONDS);
        if ($reclaimed > 0) {
            $this->log->info('reclaimed stuck queue rows', ['count' => $reclaimed]);
        }

        $workerId = $this->workerId();
        $claimed  = $this->queue->claimBatch($workerId, self::BATCH_SIZE);

        $start = \microtime(true);
        $counts = ['processed' => 0, 'success' => 0, 'retried' => 0, 'dead' => 0, 'stale' => 0];

        $keyId = $this->localActorUrl . '#main-key';
        try {
            $pair = $this->keys->load($this->localKeyUsername);
        } catch (\Throwable $e) {
            $this->log->error('cannot load local private key', ['error' => $e->getMessage()]);
            return $counts;
        }

        foreach ($claimed as $id) {
            if (\microtime(true) - $start > self::BATCH_WALLCLOCK_SECONDS) {
                // Leave the rest claimed; the next tick picks them up
                // after the heartbeat threshold or by an explicit
                // reschedule the worker hasn't made — release them.
                $this->queue->reschedule($id, 0, 1, 0, 'walltime exceeded — released');
                continue;
            }
            // Heartbeat BEFORE each delivery — keeps the claim hot
            // even if the previous delivery in this batch was slow.
            if (!$this->queue->heartbeat($id, $workerId)) {
                continue;       // some other worker took it
            }
            $record = $this->queue->loadById($id);
            if ($record === null) {
                continue;
            }
            $counts['processed']++;

            $this->deliverOne($record, $keyId, $pair->privatePem, $counts);
        }

        return $counts;
    }

    /**
     * @param array{processed:int, success:int, retried:int, dead:int, stale:int} $counts
     */
    private function deliverOne(QueueRecord $record, string $keyId, string $privatePem, array &$counts): void
    {
        $factory = new Psr17Factory();
        $body    = (string) \json_encode($record->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $request = $factory->createRequest('POST', $record->recipientInbox)
            ->withHeader('Content-Type', 'application/activity+json')
            ->withBody($factory->createStream($body));

        try {
            $signed = $this->signer->sign($request, $keyId, $privatePem);
        } catch (\Throwable $e) {
            $this->queue->markDead($record->id, 0, 'sign error: ' . $e->getMessage());
            $counts['dead']++;
            $this->log->error('signing failed', ['queue_id' => $record->id, 'error' => $e->getMessage()]);
            return;
        }

        try {
            $response = $this->http->send($signed, [
                'http_errors'     => false,
                'allow_redirects' => false,
                'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
                'timeout'         => self::TOTAL_TIMEOUT_SECONDS,
            ]);
            $status = $response->getStatusCode();
            $outcome = $this->classifier->fromStatus($status);
        } catch (GuzzleException $e) {
            $status  = 0;
            $outcome = $this->classifier->fromException();
        }

        $this->finalise($record, $outcome, $status, $counts);
    }

    /**
     * @param array{processed:int, success:int, retried:int, dead:int, stale:int} $counts
     */
    private function finalise(QueueRecord $record, DeliveryOutcome $outcome, int $status, array &$counts): void
    {
        switch ($outcome) {
            case DeliveryOutcome::Success:
                $this->queue->markDone($record->id);
                // If the recipient was pending_accept and this was the
                // Accept push, flip them to accepted. We treat any 2xx
                // on any push to them as confirmation of an alive inbox.
                $this->followers->markAccepted($this->ownerFromInbox($record->recipientInbox));
                $counts['success']++;
                $this->log->info('push delivered', [
                    'queue_id' => $record->id,
                    'inbox'    => $record->recipientInbox,
                    'status'   => $status,
                ]);
                return;

            case DeliveryOutcome::GoneForever:
                $this->queue->markDone($record->id);
                $this->followers->remove($this->ownerFromInbox($record->recipientInbox));
                $counts['stale']++;
                $this->log->info('recipient gone (410), follower removed', [
                    'inbox' => $record->recipientInbox,
                ]);
                return;

            case DeliveryOutcome::Permanent:
                $this->queue->markDead($record->id, $status, 'permanent http ' . $status);
                $counts['dead']++;
                $this->log->warning('push permanent fail', [
                    'queue_id' => $record->id,
                    'inbox'    => $record->recipientInbox,
                    'status'   => $status,
                ]);
                return;

            case DeliveryOutcome::Transient:
                $newAttempts = $record->attemptCount + 1;
                $delay = $this->retryPolicy->nextDelaySeconds($newAttempts);
                if ($delay === null) {
                    $this->queue->markDead($record->id, $status, 'max attempts reached');
                    $counts['dead']++;
                    return;
                }
                $this->queue->reschedule($record->id, $newAttempts, $delay, $status, 'transient http ' . $status);
                $counts['retried']++;
                $this->log->info('push transient fail, rescheduled', [
                    'queue_id'    => $record->id,
                    'inbox'       => $record->recipientInbox,
                    'status'      => $status,
                    'next_in_sec' => $delay,
                ]);
                return;

            case DeliveryOutcome::Exhausted:
                // Reserved for explicit signalling; falls through to
                // dead-letter via the transient handler in practice.
                $this->queue->markDead($record->id, $status, 'exhausted');
                $counts['dead']++;
                return;
        }
    }

    /**
     * Derive owner URL from inbox URL. Trim trailing `/inbox`. Used
     * to map back to the followers row for status updates.
     */
    private function ownerFromInbox(string $inboxUrl): string
    {
        if (\str_ends_with($inboxUrl, '/inbox')) {
            return \substr($inboxUrl, 0, -6);
        }
        return $inboxUrl;
    }

    private function workerId(): string
    {
        return \sprintf(
            '%s:%d:%s',
            \gethostname() ?: 'unknown',
            \getmypid() ?: 0,
            \bin2hex(\random_bytes(4)),
        );
    }
}
