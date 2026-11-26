<?php

it('serves Swagger UI pointed at the spec', function () {
    $this->get('/docs')->assertOk()->assertSee('/docs/openapi.yaml', false);
});

it('serves the spec from the repository root', function () {
    $response = $this->get('/docs/openapi.yaml')->assertOk();

    expect($response->baseResponse->getFile()->getRealPath())->toBe(base_path('openapi.yaml'));
    $response->assertHeader('Content-Type', 'application/yaml');
});
