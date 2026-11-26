<?php

namespace App\Modules\Graph\Providers;

use App\Modules\Graph\Support\Aggregates\SqliteMedian;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Pdo\Sqlite;

class GraphServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/migrations');

        Event::listen(function (ConnectionEstablished $event): void {
            if ($event->connection->getDriverName() !== 'sqlite') {
                return;
            }

            $pdo = $event->connection->getPdo();

            if ($pdo instanceof Sqlite) {
                SqliteMedian::register($pdo);
            }
        });
    }
}
