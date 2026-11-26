<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;

/**
 * Creates the tests' own RabbitMQ vhost through the management API. Only a name ending in "_test" is created.
 */
final class RabbitMqTestVhost
{
    private static bool $ready = false;

    public static function ensure(): void
    {
        $vhost = (string) config('rabbitmq.vhost');

        if (self::$ready || ! str_ends_with($vhost, '_test')) {
            return;
        }

        $user = (string) config('rabbitmq.login');
        $api = 'http://'.config('rabbitmq.host').':'.env('RABBITMQ_MANAGEMENT_PORT', 15672).'/api';
        // A new request each time: a body set on one would be sent by the next.
        $client = fn () => Http::withBasicAuth($user, (string) config('rabbitmq.password'))->timeout(10);

        // Both calls are no-ops when there is nothing to change.
        $client()->withBody('{}')->put("{$api}/vhosts/".rawurlencode($vhost))->throw();
        $client()->put("{$api}/permissions/".rawurlencode($vhost).'/'.rawurlencode($user), [
            'configure' => '.*',
            'write' => '.*',
            'read' => '.*',
        ])->throw();

        self::$ready = true;
    }
}
