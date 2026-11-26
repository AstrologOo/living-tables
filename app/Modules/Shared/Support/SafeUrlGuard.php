<?php

namespace App\Modules\Shared\Support;

use App\Modules\Shared\Exceptions\HostUnresolvedException;
use App\Modules\Shared\Exceptions\SsrfBlockedException;
use GuzzleHttp\Psr7\Exception\MalformedUriException;
use GuzzleHttp\Psr7\Uri;

/**
 * Blocks requests to internal addresses (SSRF). Returns the address it checked, which the caller must connect to,
 * otherwise DNS rebinding could swap it.
 */
class SafeUrlGuard
{
    private const BLOCKED_CIDRS = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',      // CGNAT
        '127.0.0.0/8',
        '169.254.0.0/16',     // link-local, incl. cloud metadata (169.254.169.254)
        '172.16.0.0/12',
        '192.168.0.0/16',
        '198.18.0.0/15',      // benchmarking
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved, incl. broadcast
        '::/128',             // unspecified
        '::1/128',
        '2002::/16',          // 6to4 — embeds an arbitrary IPv4 address
        '64:ff9b::/96',       // NAT64 — same, translated by the gateway
        '64:ff9b:1::/48',     // NAT64, locally configured prefixes
        'fc00::/7',           // unique local
        'fe80::/10',          // link-local
        '::ffff:0:0/96',      // IPv4-mapped IPv6 — must be checked after unwrapping too
    ];

    /**
     * @return string the checked IP address the request must be sent to
     *                (IPv6 without brackets)
     *
     * @throws SsrfBlockedException
     */
    public function assertAllowed(string $url): string
    {
        // The same parser Guzzle builds the outgoing request with, so the
        // host checked here is the host curl is later handed.
        try {
            $uri = new Uri($url);
        } catch (MalformedUriException) {
            throw new SsrfBlockedException("Refusing to fetch \"{$url}\": not a valid http(s) URL.");
        }

        $host = trim($uri->getHost(), '[]');

        if ($host === '' || ! in_array($uri->getScheme(), ['http', 'https'], true)) {
            throw new SsrfBlockedException("Refusing to fetch \"{$url}\": not a valid http(s) URL.");
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses = [$host];
        } elseif ($this->isPlainHostname($host)) {
            $addresses = $this->resolve($host);
        } else {
            throw new SsrfBlockedException("Refusing to fetch \"{$url}\": host \"{$host}\" is not a plain ASCII hostname or IP address.");
        }

        if ($addresses === []) {
            throw new HostUnresolvedException("Refusing to fetch \"{$url}\": host \"{$host}\" did not resolve to any address.");
        }

        // Every address must pass, not just the one that gets picked.
        foreach ($addresses as $address) {
            if ($this->isBlocked($address)) {
                throw new SsrfBlockedException("Refusing to fetch \"{$url}\": host \"{$host}\" resolves to {$address}, a blocked address range.");
            }
        }

        // IPv4 first: the request is pinned to a single address, so curl
        // can't fall back to another one if the network has no IPv6 route.
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $address;
            }
        }

        return $addresses[0];
    }

    /**
     * Rejects hosts curl might read differently: percent-encoded, non-ASCII or legacy numeric IPv4 forms.
     */
    private function isPlainHostname(string $host): bool
    {
        if (preg_match('/^[a-z0-9._-]+$/i', $host) !== 1) {
            return false;
        }

        $labels = explode('.', rtrim($host, '.'));

        return preg_match('/^(\d+|0x[0-9a-f]*)$/i', end($labels)) !== 1;
    }

    /**
     * @return array<int, string>
     */
    protected function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_unique(array_filter(array_map(
            fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        ))));
    }

    private function isBlocked(string $address): bool
    {
        foreach (self::BLOCKED_CIDRS as $cidr) {
            if ($this->inRange($address, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function inRange(string $address, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $addressBytes = @inet_pton($address);
        $subnetBytes = @inet_pton($subnet);

        if ($addressBytes === false || $subnetBytes === false || strlen($addressBytes) !== strlen($subnetBytes)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $remainderBits = $bits % 8;

        if ($bytes > 0 && substr($addressBytes, 0, $bytes) !== substr($subnetBytes, 0, $bytes)) {
            return false;
        }

        if ($remainderBits === 0) {
            return true;
        }

        $mask = ~(0xFF >> $remainderBits) & 0xFF;

        return (ord($addressBytes[$bytes]) & $mask) === (ord($subnetBytes[$bytes]) & $mask);
    }
}
