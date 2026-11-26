<?php

use Symfony\Component\Yaml\Yaml;

it('lets a second copy of the stack choose its ports and image name, with the same defaults', function () {
    $compose = Yaml::parseFile(__DIR__.'/../../docker-compose.yml')['services'];
    $env = file_get_contents(__DIR__.'/../../.env.example');
    $ports = array_merge(...array_values(array_map(fn (array $service) => $service['ports'] ?? [], $compose)));

    expect($ports)->toBe([
        '${FORWARD_APP_PORT:-8080}:80',
        '${FORWARD_REVERB_PORT:-8081}:8080',
        '${FORWARD_DB_PORT:-5432}:5432',
        '${FORWARD_REDIS_PORT:-6379}:6379',
        '${FORWARD_RABBITMQ_PORT:-5672}:5672',
        '${FORWARD_RABBITMQ_UI_PORT:-15672}:15672',
    ]);

    foreach (['app', 'worker', 'scheduler', 'reverb'] as $service) {
        expect($compose[$service]['image'])->toBe('${APP_IMAGE:-graph-backend-app:local}');
    }

    foreach (['APP_IMAGE', 'FORWARD_APP_PORT', 'FORWARD_REVERB_PORT', 'FORWARD_DB_PORT', 'FORWARD_REDIS_PORT', 'FORWARD_RABBITMQ_PORT', 'FORWARD_RABBITMQ_UI_PORT'] as $name) {
        expect($env)->toContain("# {$name}=");
    }
});
