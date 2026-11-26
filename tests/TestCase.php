<?php

namespace Tests;

use App\Modules\Shared\Support\SafeUrlGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;
use Tests\Support\Fakes\FakeSafeUrlGuard;
use Tests\Support\RabbitMqTestVhost;

abstract class TestCase extends BaseTestCase
{
    /**
     * Runs before RefreshDatabase can drop tables: a server database must be named as a test one.
     */
    protected function setUpTraits()
    {
        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if (config("database.connections.{$connection}.driver") !== 'sqlite' && ! str_ends_with($database, '_test')) {
            throw new RuntimeException(
                "Refusing to run tests against database \"{$database}\": its name must end with \"_test\"."
            );
        }

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        RabbitMqTestVhost::ensure();

        // Faked "*.test" hosts don't resolve.
        $this->app->instance(SafeUrlGuard::class, new FakeSafeUrlGuard);
    }
}
