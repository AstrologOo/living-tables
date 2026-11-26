<?php

namespace App\Console\Commands;

use App\Console\Support\ConsumeNodeJobsConnection;
use App\Console\Support\NodeRecalculator;
use App\Modules\Execution\ExecutionContext;
use App\Modules\Execution\PipelineRunner;
use App\Modules\Graph\Services\NodeEventPublisher;
use App\Modules\Graph\Services\RabbitMqTopology;
use App\Modules\Graph\Services\ResultStore;
use App\Modules\Graph\Services\SchedulerService;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

/**
 * The long-running worker. A `node.recalc` message only says that some node is ready; failures are recorded on
 * the node, not in the queue.
 */
class ConsumeNodeJobs extends Command
{
    protected $signature = 'graph:consume-node-jobs';

    protected $description = 'Consume node.recalc signals and recalculate ready nodes until stopped';

    private bool $shouldStop = false;

    public function handle(RabbitMqTopology $topology, ExecutionContext $ctx): int
    {
        pcntl_async_signals(false);

        // SIGQUIT is the php-fpm image's default stop signal.
        foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
            pcntl_signal($signal, function () {
                $this->shouldStop = true;
            });
        }

        // Also checked between nodes, so stopping doesn't wait for the whole backlog.
        $stopRequested = function (): bool {
            pcntl_signal_dispatch();

            return $this->shouldStop;
        };

        $conn = null;

        while (! $stopRequested()) {
            try {
                if ($conn === null) {
                    $conn = $this->connect($topology, $ctx);

                    // Nodes may have become ready while no worker was listening.
                    $conn->recalculator->drainReadyNodes($ctx, $stopRequested);
                }

                try {
                    $conn->channel->wait(null, false, 5.0);
                } catch (AMQPTimeoutException) {
                    // Nothing arrived; check for a stop request and wait again.
                }

                // Polls too: a signal can be lost when SKIP LOCKED briefly hides a ready node, and delayed retries send none.
                $conn->recalculator->drainReadyNodes($ctx, $stopRequested);
            } catch (Throwable $e) {
                // An unavailable broker, database or Redis must not end the process: reconnect after a pause.
                Log::error("Worker iteration failed, reconnecting: {$e->getMessage()}", ['exception' => $e::class]);

                $this->disconnect($conn);
                $conn = null;

                // A connection broken mid-transaction doesn't recover by itself.
                if ($e instanceof PDOException) {
                    DB::purge();
                }

                $this->pause($stopRequested);
            }
        }

        $this->disconnect($conn);

        return self::SUCCESS;
    }

    private function disconnect(?ConsumeNodeJobsConnection $conn): void
    {
        try {
            $conn?->channel->getConnection()->close();
        } catch (Throwable) {
            // Already broken — nothing to clean up.
        }
    }

    /**
     * @param  Closure(): bool  $stopRequested
     */
    private function pause(Closure $stopRequested): void
    {
        $until = microtime(true) + (float) config('rabbitmq.error_pause_seconds');

        while (microtime(true) < $until && ! $stopRequested()) {
            usleep(100_000);
        }
    }

    private function connect(RabbitMqTopology $topology, ExecutionContext $ctx): ConsumeNodeJobsConnection
    {
        $channel = $topology->connect();
        $topology->declare($channel);

        // Publishing and consuming use separate channels.
        $publishChannel = new AMQPChannel($channel->getConnection());

        $eventPublisher = new NodeEventPublisher($publishChannel);
        $recalculator = new NodeRecalculator(
            new SchedulerService,
            new ResultStore,
            new PipelineRunner,
            $eventPublisher,
        );

        $conn = new ConsumeNodeJobsConnection(
            $channel,
            $publishChannel,
            config('rabbitmq.queue'),
            $recalculator,
        );

        $channel->basic_consume(
            $conn->queue,
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $message) use ($conn, $ctx) {
                $this->handleMessage($message, $conn->recalculator, $ctx);
            },
        );

        return $conn;
    }

    private function handleMessage(
        AMQPMessage $message,
        NodeRecalculator $recalculator,
        ExecutionContext $ctx,
    ): void {
        // Always acknowledged: the outcome is recorded on the node.
        $recalculator->claimAndProcess($ctx);

        $message->ack();
    }
}
