<?php

namespace App\Modules\Graph\Services;

use App\Modules\Graph\Exceptions\TopologyOutdatedException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;

/**
 * Declares the exchange and the `node.recalc` queue, whose messages only wake the worker. Declaring again is a
 * no-op.
 */
class RabbitMqTopology
{
    private const PRECONDITION_FAILED = 406;

    public function connect(): AMQPChannel
    {
        $connection = new AMQPStreamConnection(
            host: config('rabbitmq.host'),
            port: config('rabbitmq.port'),
            user: config('rabbitmq.login'),
            password: config('rabbitmq.password'),
            vhost: config('rabbitmq.vhost'),
            read_write_timeout: 30.0,
            channel_rpc_timeout: 10.0,
        );

        return $connection->channel();
    }

    /**
     * @throws TopologyOutdatedException if the broker still has the recalc
     *                                   queue from an earlier version
     */
    public function declare(AMQPChannel $channel): void
    {
        $channel->exchange_declare(config('rabbitmq.exchange'), 'topic', false, true, false);

        try {
            $channel->queue_declare(config('rabbitmq.queue'), false, true, false, false, false);
        } catch (AMQPProtocolChannelException $e) {
            if ($e->amqp_reply_code !== self::PRECONDITION_FAILED) {
                throw $e;
            }

            throw new TopologyOutdatedException(
                'Queue "'.config('rabbitmq.queue').'" exists with other arguments: run "php artisan rabbitmq:setup-topology" once.',
                previous: $e,
            );
        }

        $channel->queue_bind(config('rabbitmq.queue'), config('rabbitmq.exchange'), config('rabbitmq.queue'));
    }

    /**
     * Removes queues left by earlier versions and recreates `node.recalc` if its arguments are outdated; returns
     * whether it did. Dropping its messages is safe, as the worker also polls.
     *
     * @return bool whether the recalc queue had to be recreated
     */
    public function upgrade(): bool
    {
        $channel = $this->connect();
        // Taken now: a channel the broker closes forgets its connection.
        $connection = $channel->getConnection();
        $recreated = false;

        try {
            $this->declare($channel);
        } catch (TopologyOutdatedException) {
            // The broker closed the channel along with refusing the declare.
            $channel = new AMQPChannel($connection);
            $channel->queue_delete(config('rabbitmq.queue'));
            $this->declare($channel);
            $recreated = true;
        }

        foreach (['retry', 'dead'] as $suffix) {
            $channel->queue_delete(config('rabbitmq.queue').".{$suffix}");
        }

        $channel->queue_delete('node.invalidation');

        $connection->close();

        return $recreated;
    }
}
