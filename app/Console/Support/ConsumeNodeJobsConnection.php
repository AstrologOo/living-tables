<?php

namespace App\Console\Support;

use PhpAmqpLib\Channel\AMQPChannel;

/**
 * What the worker rebuilds on each reconnect.
 */
final class ConsumeNodeJobsConnection
{
    public function __construct(
        public readonly AMQPChannel $channel,
        public readonly AMQPChannel $publishChannel,
        public readonly string $queue,
        public readonly NodeRecalculator $recalculator,
    ) {}
}
