<?php

// Without CORS headers a browser frontend on another origin can't use the API.

it('answers a CORS preflight request for a protected route', function () {
    $this->call('OPTIONS', '/api/graph', server: [
        'HTTP_ORIGIN' => 'http://localhost:5173',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Authorization',
    ])
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin')
        ->assertHeader('Access-Control-Allow-Methods');
});

it('includes Access-Control-Allow-Origin even on a 401, not just a success response', function () {
    // Needed on errors too, or the browser hides the status.
    $this->call('GET', '/api/graph', server: ['HTTP_ORIGIN' => 'http://localhost:5173'])
        ->assertStatus(401)
        ->assertHeader('Access-Control-Allow-Origin');
});
