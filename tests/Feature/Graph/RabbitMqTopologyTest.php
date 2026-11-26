<?php

use App\Modules\Graph\Exceptions\TopologyOutdatedException;
use App\Modules\Graph\Services\RabbitMqTopology;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;

function queueExists(string $name): bool
{
    $channel = (new RabbitMqTopology)->connect();
    $connection = $channel->getConnection();

    try {
        $channel->queue_declare($name, true);

        return true;
    } catch (AMQPProtocolChannelException) {
        return false;
    } finally {
        $connection->close();
    }
}

/**
 * A queue as an earlier version declared it, under a test-only name.
 */
function declareEarlierTopology(AMQPChannel $channel, string $queue): void
{
    $channel->queue_declare($queue, false, true, false, false, false, [
        'x-dead-letter-exchange' => ['S', ''],
        'x-dead-letter-routing-key' => ['S', "{$queue}.retry"],
    ]);
    $channel->queue_declare("{$queue}.retry", false, true, false, false, false);
    $channel->queue_declare("{$queue}.dead", false, true, false, false, false);
    $channel->queue_declare('node.invalidation', false, true, false, false, false);
}

it('declares the exchange and the recalc queue only, and is safe to run twice', function () {
    config(['rabbitmq.queue' => 'node.recalc.declare-test']);
    $topology = new RabbitMqTopology;
    $channel = $topology->connect();
    $channel->queue_delete('node.invalidation');

    $topology->declare($channel);
    $topology->declare($channel); // idempotent — must not throw
    $channel->exchange_declare('nodes', 'topic', true, true, false); // passive: throws if missing

    expect(queueExists('node.recalc.declare-test'))->toBeTrue()
        ->and(queueExists('node.invalidation'))->toBeFalse()
        ->and(queueExists('node.recalc.declare-test.retry'))->toBeFalse()
        ->and(queueExists('node.recalc.declare-test.dead'))->toBeFalse();

    $channel->queue_delete('node.recalc.declare-test');
    $channel->getConnection()->close();
});

it('says what to run when the broker has the earlier queue, and the command upgrades it', function () {
    $queue = 'node.recalc.upgrade-test';
    config(['rabbitmq.queue' => $queue]);
    $topology = new RabbitMqTopology;
    declareEarlierTopology($topology->connect(), $queue);

    expect(fn () => $topology->declare($topology->connect()))
        ->toThrow(TopologyOutdatedException::class, 'rabbitmq:setup-topology');

    $this->artisan('rabbitmq:setup-topology')->expectsOutputToContain('Recreated')->assertSuccessful();

    $topology->declare($topology->connect()); // no longer refused
    expect(queueExists("{$queue}.retry"))->toBeFalse()
        ->and(queueExists("{$queue}.dead"))->toBeFalse()
        ->and(queueExists('node.invalidation'))->toBeFalse();

    $this->artisan('rabbitmq:setup-topology')->doesntExpectOutputToContain('Recreated')->assertSuccessful();

    $topology->connect()->queue_delete($queue);
});
