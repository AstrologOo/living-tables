<?php

namespace App\Console\Commands;

use App\Modules\Graph\Services\RabbitMqTopology;
use Illuminate\Console\Command;

class SetupRabbitMqTopology extends Command
{
    protected $signature = 'rabbitmq:setup-topology';

    protected $description = 'Declare the RabbitMQ exchange and queues, upgrading ones from an earlier version (idempotent)';

    public function handle(RabbitMqTopology $topology): int
    {
        if ($topology->upgrade()) {
            $this->warn('Recreated "'.config('rabbitmq.queue').'": it was declared by an earlier version.');
        }

        $this->info('RabbitMQ topology declared: '.implode(', ', [
            config('rabbitmq.exchange'),
            config('rabbitmq.queue'),
        ]));

        return self::SUCCESS;
    }
}
