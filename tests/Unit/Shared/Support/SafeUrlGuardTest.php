<?php

use App\Modules\Shared\Exceptions\HostUnresolvedException;
use App\Modules\Shared\Exceptions\SsrfBlockedException;
use App\Modules\Shared\Support\SafeUrlGuard;
use Tests\Support\Fakes\StubDnsSafeUrlGuard;

it('allows a public IP literal', function () {
    (new SafeUrlGuard)->assertAllowed('http://93.184.216.34/');
})->throwsNoExceptions();

dataset('blocked ip literals', [
    'loopback' => ['http://127.0.0.1/'],
    'private class A' => ['http://10.1.2.3/'],
    'private class B' => ['http://172.16.0.1/'],
    'private class C' => ['http://192.168.1.1/'],
    'link-local / cloud metadata' => ['http://169.254.169.254/'],
    'CGNAT' => ['http://100.64.0.1/'],
    'IPv6 loopback' => ['http://[::1]/'],
    'IPv6 unique local' => ['http://[fc00::1]/'],
    'IPv6 link-local' => ['http://[fe80::1]/'],
]);

it('blocks private/loopback/link-local IP literals', function (string $url) {
    (new SafeUrlGuard)->assertAllowed($url);
})->with('blocked ip literals')->throws(SsrfBlockedException::class);

it('rejects a non-http(s) scheme', function () {
    (new SafeUrlGuard)->assertAllowed('file:///etc/passwd');
})->throws(SsrfBlockedException::class);

it('rejects a URL with no host', function () {
    (new SafeUrlGuard)->assertAllowed('not-a-url');
})->throws(SsrfBlockedException::class);

it('rejects a hostname that does not resolve', function () {
    // .invalid is reserved by RFC 2606 to never resolve to anything.
    (new SafeUrlGuard)->assertAllowed('http://definitely-not-a-real-host.invalid/');
})->throws(HostUnresolvedException::class);

dataset('blocked ipv6 ranges', [
    'unspecified' => ['http://[::]/'],
    '6to4' => ['http://[2002:7f00:1::]/'],
    'NAT64' => ['http://[64:ff9b::a9fe:a9fe]/'],
]);

it('blocks the unspecified, 6to4 and NAT64 ranges', function (string $url) {
    (new SafeUrlGuard)->assertAllowed($url);
})->with('blocked ipv6 ranges')->throws(SsrfBlockedException::class, 'blocked address range');

it('allows a public IPv6 literal and returns it without brackets', function () {
    expect((new SafeUrlGuard)->assertAllowed('http://[2606:2800:220:1:248:1893:25c8:1946]/'))
        ->toBe('2606:2800:220:1:248:1893:25c8:1946');
});

it('returns the checked address of a hostname, preferring IPv4', function () {
    $guard = new StubDnsSafeUrlGuard(['source.example' => ['2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34']]);

    expect($guard->assertAllowed('https://source.example/'))->toBe('93.184.216.34');
});

it('rejects a hostname if any of its addresses is blocked', function () {
    (new StubDnsSafeUrlGuard(['source.example' => ['93.184.216.34', '10.0.0.5']]))
        ->assertAllowed('https://source.example/');
})->throws(SsrfBlockedException::class);

it('rejects hosts curl could read differently, even if DNS would answer', function (string $host) {
    (new StubDnsSafeUrlGuard([$host => ['93.184.216.34']]))->assertAllowed("http://{$host}/");
})->with(['127.1', '0x7f000001', '2130706433', 'loc%61lhost', 'пример.рф'])
    ->throws(SsrfBlockedException::class);

it('blocks multicast, reserved, benchmarking and local NAT64 ranges', function (string $url) {
    (new SafeUrlGuard)->assertAllowed($url);
})->with([
    'multicast' => 'http://224.0.0.1/',
    'multicast, upper end' => 'http://239.255.255.250/',
    'reserved' => 'http://240.0.0.1/',
    'broadcast' => 'http://255.255.255.255/',
    'benchmarking' => 'http://198.18.0.1/',
    'benchmarking, upper end' => 'http://198.19.255.254/',
    'local NAT64' => 'http://[64:ff9b:1::a00:1]/',
])->throws(SsrfBlockedException::class, 'blocked address range');

it('still allows the public addresses next to those ranges', function (string $url) {
    (new SafeUrlGuard)->assertAllowed($url);
})->with(['http://198.17.255.255/', 'http://198.20.0.1/', 'http://223.255.255.254/'])
    ->throwsNoExceptions();
