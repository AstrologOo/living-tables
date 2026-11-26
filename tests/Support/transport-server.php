<?php

// Router for the `php -S` server in SafeHttpFetcherTransportTest.
switch ($_SERVER['REQUEST_URI']) {
    case '/host':
        echo $_SERVER['HTTP_HOST'];
        break;
    case '/big':
        echo str_repeat('x', 5_000_000);
        break;
    case '/gzip-bomb':
        header('Content-Encoding: gzip');
        echo gzencode(str_repeat('x', 100_000));
        break;
    case '/slow-redirect':
        usleep(700_000);
        header('Location: /trickle', true, 302);
        break;
    case '/trickle':
        // A byte every 200ms: never idle long enough to trip a read timeout.
        for ($i = 0; $i < 30; $i++) {
            echo 'x';
            @ob_flush();
            flush();
            usleep(200_000);
        }
        break;
}
