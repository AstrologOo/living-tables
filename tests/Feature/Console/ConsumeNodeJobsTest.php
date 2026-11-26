<?php

use App\Models\User;
use App\Modules\Graph\Services\GraphService;
use App\Modules\Graph\Services\RabbitMqTopology;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Channel\AMQPChannel;

uses(RefreshDatabase::class);

afterEach(function () {
    foreach ([SIGTERM, SIGINT, SIGQUIT, SIGALRM] as $signal) {
        pcntl_signal($signal, SIG_DFL);
    }
    pcntl_alarm(0);
});

it('survives a failed iteration, reconnects, and stops cleanly on a signal', function (int $signal) {
    config(['rabbitmq.error_pause_seconds' => 0]);
    Log::spy();

    // The first connection attempt fails like an unreachable service would;
    // the second succeeds and asks the worker to stop.
    $topology = new class($signal) extends RabbitMqTopology
    {
        public int $attempts = 0;

        public function __construct(private readonly int $signal) {}

        public function connect(): AMQPChannel
        {
            if (++$this->attempts === 1) {
                throw new RuntimeException('service unavailable');
            }

            posix_kill(posix_getpid(), $this->signal);

            return parent::connect();
        }
    };
    $this->app->instance(RabbitMqTopology::class, $topology);

    $this->artisan('graph:consume-node-jobs')->assertSuccessful();

    expect($topology->attempts)->toBe(2);
    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message) => str_contains($message, 'service unavailable'))
        ->once();
})->with(['SIGTERM' => SIGTERM, 'SIGQUIT' => SIGQUIT]);

it('picks up a node that became ready later, with no message about it', function () {
    $channel = (new RabbitMqTopology)->connect();
    (new RabbitMqTopology)->declare($channel);
    $channel->queue_purge(config('rabbitmq.queue'));
    $channel->getConnection()->close();

    // Not claimable at startup, so only a later sweep can find it.
    $node = (new GraphService)->createNode([
        'user_id' => User::factory()->create()->id, 'type' => 'source', 'name' => 'A',
        'dsl_config' => [['op' => 'fetch', 'url' => 'https://x.test/a', 'format' => 'json']],
    ]);
    $node->update(['retry_at' => now()->addSeconds(2)]);

    Http::fake(['*' => function () {
        posix_kill(posix_getpid(), SIGTERM); // one run is all this test needs

        return Http::response(['v' => 1]);
    }]);
    // Ends the worker if the sweep never comes, instead of hanging the suite.
    pcntl_signal(SIGALRM, fn () => posix_kill(posix_getpid(), SIGTERM));
    pcntl_alarm(20);

    $this->artisan('graph:consume-node-jobs')->assertSuccessful();

    expect($node->fresh()->status)->toBe('fresh');
});
