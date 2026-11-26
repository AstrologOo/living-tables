<?php

use Illuminate\Support\Facades\Log;

it('keeps test errors out of the application\'s log file', function () {
    $log = storage_path('logs/laravel.log');
    $size = fn () => is_file($log) ? filesize($log) : 0;
    clearstatcache();
    $before = $size();

    Log::error('written by the test suite');

    clearstatcache();
    expect($size())->toBe($before);
});
