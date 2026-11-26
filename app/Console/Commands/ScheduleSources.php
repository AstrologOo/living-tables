<?php

namespace App\Console\Commands;

use App\Modules\Graph\Models\Node;
use App\Modules\Graph\Services\NodeEventPublisher;
use App\Modules\Graph\Services\RabbitMqTopology;
use Cron\CronExpression;
use Illuminate\Console\Command;
use Throwable;

/**
 * Marks fresh source nodes `stale` when their cron schedule is due and wakes the worker.
 */
class ScheduleSources extends Command
{
    protected $signature = 'graph:schedule-sources';

    protected $description = 'Mark fresh source nodes whose cron schedule is due as stale and wake the worker';

    public function handle(RabbitMqTopology $topology): int
    {
        $channel = $topology->connect();
        $topology->declare($channel);
        $eventPublisher = new NodeEventPublisher($channel);

        $due = Node::query()
            ->where('type', 'source')
            ->where('status', 'fresh')
            ->whereNotNull('schedule')
            ->get()
            ->filter(fn (Node $node) => $this->isDue($node->schedule));

        $marked = 0;

        foreach ($due as $node) {
            // Only if still fresh, so a claim made meanwhile survives.
            if (Node::query()->whereKey($node->id)->where('status', 'fresh')->update(['status' => 'stale']) === 0) {
                continue;
            }

            $eventPublisher->publishRecalcTask($node->id);
            $marked++;
        }

        $channel->getConnection()->close();

        $this->info("Marked {$marked} source node(s) stale.");

        return self::SUCCESS;
    }

    private function isDue(string $schedule): bool
    {
        try {
            return (new CronExpression($schedule))->isDue();
        } catch (Throwable) {
            return false; // Skip a malformed cron expression.
        }
    }
}
