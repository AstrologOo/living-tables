<?php

namespace App\Modules\Graph\Services;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;

class NodeEventPublisher
{
    public function __construct(private readonly AMQPChannel $channel) {}

    /**
     * Sends the worker a wake-up signal for a node that became `stale`.
     */
    public function publishRecalcTask(string $nodeId): void
    {
        $this->channel->basic_publish(
            new AMQPMessage(json_encode(['node_id' => $nodeId])),
            config('rabbitmq.exchange'),
            config('rabbitmq.queue'),
        );
    }
}
