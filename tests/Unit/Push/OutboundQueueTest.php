<?php

declare(strict_types=1);

namespace Grav\Plugin\FediversePublisher\Tests\Unit\Push;

use Grav\Plugin\FediversePublisher\Push\OutboundQueue;
use PDO;
use PHPUnit\Framework\TestCase;

final class OutboundQueueTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new OutboundQueue($this->pdo))->migrate();
    }

    public function testEnqueueIsIdempotentOnActivityRecipientPair(): void
    {
        $q = new OutboundQueue($this->pdo);
        $a = ['id' => 'https://blog.local/x#create-1', 'type' => 'Create'];
        $q->enqueue($a, 'https://peer.example/inbox', 'https://blog.local/actor');
        $q->enqueue($a, 'https://peer.example/inbox', 'https://blog.local/actor');

        self::assertSame(1, $this->countRows());
    }

    public function testClaimBatchReturnsPendingRows(): void
    {
        $q = $this->seedQueue(3);
        $ids = $q->claimBatch('w-1', 10);

        self::assertCount(3, $ids);
        foreach ($ids as $id) {
            $status = $this->pdo->query("SELECT status FROM push_queue WHERE id = $id")->fetchColumn();
            self::assertSame('processing', $status);
        }
    }

    public function testClaimBatchSkipsAlreadyClaimedRows(): void
    {
        $q = $this->seedQueue(3);
        $first  = $q->claimBatch('w-1', 1);
        $second = $q->claimBatch('w-2', 10);

        self::assertCount(1, $first);
        self::assertCount(2, $second);
        self::assertEmpty(\array_intersect($first, $second));
    }

    public function testClaimBatchRespectsLimit(): void
    {
        $q = $this->seedQueue(5);
        self::assertCount(2, $q->claimBatch('w-1', 2));
    }

    public function testHeartbeatRefreshesClaimedAt(): void
    {
        $q = $this->seedQueue(1);
        $ids = $q->claimBatch('w-1', 1);

        // simulate time-passing by directly setting claimed_at backwards
        $this->pdo->exec("UPDATE push_queue SET claimed_at = claimed_at - 1000 WHERE id = " . $ids[0]);
        $before = $this->pdo->query("SELECT claimed_at FROM push_queue WHERE id = " . $ids[0])->fetchColumn();

        \sleep(1);
        self::assertTrue($q->heartbeat($ids[0], 'w-1'));
        $after = $this->pdo->query("SELECT claimed_at FROM push_queue WHERE id = " . $ids[0])->fetchColumn();

        self::assertGreaterThan((int) $before, (int) $after);
    }

    public function testHeartbeatFailsForDifferentWorker(): void
    {
        $q = $this->seedQueue(1);
        $ids = $q->claimBatch('w-1', 1);
        self::assertFalse($q->heartbeat($ids[0], 'w-2'));
    }

    public function testReclaimStuckMovesRowsBackToPending(): void
    {
        $q = $this->seedQueue(1);
        $ids = $q->claimBatch('w-1', 1);
        $this->pdo->exec("UPDATE push_queue SET claimed_at = claimed_at - 999 WHERE id = " . $ids[0]);

        $reclaimed = $q->reclaimStuck(120);
        self::assertSame(1, $reclaimed);

        $status = $this->pdo->query("SELECT status FROM push_queue WHERE id = " . $ids[0])->fetchColumn();
        self::assertSame('pending', $status);
    }

    public function testMarkDoneSetsDoneStatus(): void
    {
        $q = $this->seedQueue(1);
        $ids = $q->claimBatch('w-1', 1);

        $q->markDone($ids[0]);
        self::assertSame('done', $this->pdo->query("SELECT status FROM push_queue WHERE id = " . $ids[0])->fetchColumn());
    }

    public function testMarkDeadSetsDeadStatus(): void
    {
        $q = $this->seedQueue(1);
        $ids = $q->claimBatch('w-1', 1);

        $q->markDead($ids[0], 410, 'gone');
        self::assertSame('dead', $this->pdo->query("SELECT status FROM push_queue WHERE id = " . $ids[0])->fetchColumn());
        self::assertSame(410,    (int) $this->pdo->query("SELECT last_http_status FROM push_queue WHERE id = " . $ids[0])->fetchColumn());
    }

    public function testRescheduleBumpsAttemptCountAndDelay(): void
    {
        $q = $this->seedQueue(1);
        $ids = $q->claimBatch('w-1', 1);

        $q->reschedule($ids[0], 3, 60, 503, 'transient');
        $row = $this->pdo->query("SELECT status, attempt_count, next_attempt_at FROM push_queue WHERE id = " . $ids[0])->fetch(PDO::FETCH_ASSOC);

        self::assertSame('pending', $row['status']);
        self::assertSame('3',       (string) $row['attempt_count']);
        self::assertGreaterThan(\time() + 30, (int) $row['next_attempt_at']);
    }

    private function seedQueue(int $n): OutboundQueue
    {
        $q = new OutboundQueue($this->pdo);
        for ($i = 0; $i < $n; $i++) {
            $q->enqueue(
                ['id' => "https://blog.local/x#create-$i", 'type' => 'Create'],
                "https://peer.example/inbox$i",
                'https://blog.local/actor',
            );
        }
        return $q;
    }

    private function countRows(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM push_queue")->fetchColumn();
    }
}
