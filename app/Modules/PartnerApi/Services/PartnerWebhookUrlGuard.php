<?php

namespace App\Modules\PartnerApi\Services;

use App\Modules\PartnerApi\Exceptions\PartnerApiException;
use RuntimeException;

/**
 * The target URL of a webhook is the one piece of this system a partner writes
 * and our servers then fetch. That makes it a server-side request forgery
 * surface, and it is treated as one.
 *
 * Two call sites, deliberately both:
 *
 *  - `assertAcceptable()` at creation and update, so a partner learns
 *    immediately, with a specific code, that their URL is not usable.
 *  - `resolve()` before every single delivery, because DNS is not a property of
 *    the URL. A hostname that answered 203.0.113.5 when the subscription was
 *    created can answer 169.254.169.254 an hour later; checking once would
 *    secure only the first request. The addresses this method returns are the
 *    ones the request is pinned to, so the check and the connection cannot see
 *    different answers.
 *
 * Redirects are not followed at all (see PartnerWebhookDeliverer). A 30x is the
 * cheapest way to turn a validated public URL into a request against an
 * internal one, and no legitimate webhook receiver needs one.
 */
class PartnerWebhookUrlGuard
{
    /**
     * Ranges no outbound webhook may ever reach. Cloud instance metadata is
     * called out separately from the link-local block it lives in because it is
     * the specific thing an attacker is usually after: on the major providers
     * 169.254.169.254 hands out credentials to anything that can make an
     * unauthenticated HTTP request from inside the network.
     *
     * @var list<array{0: string, 1: int, 2: string}>
     */
    private const BLOCKED_V4 = [
        ['0.0.0.0', 8, 'this network'],
        ['10.0.0.0', 8, 'private network'],
        ['100.64.0.0', 10, 'carrier-grade NAT'],
        ['127.0.0.0', 8, 'loopback'],
        ['169.254.0.0', 16, 'link-local / cloud instance metadata'],
        ['172.16.0.0', 12, 'private network'],
        ['192.0.0.0', 24, 'IETF protocol assignments'],
        ['192.0.2.0', 24, 'documentation'],
        ['192.168.0.0', 16, 'private network'],
        ['198.18.0.0', 15, 'benchmarking'],
        ['198.51.100.0', 24, 'documentation'],
        ['203.0.113.0', 24, 'documentation'],
        ['224.0.0.0', 4, 'multicast'],
        ['240.0.0.0', 4, 'reserved'],
    ];

    /**
     * Hostnames refused before DNS is even consulted. Resolution would catch
     * most of them anyway; refusing by name gives the partner a clearer error
     * and costs nothing.
     *
     * @var list<string>
     */
    private const BLOCKED_HOST_SUFFIXES = [
        'localhost',
        '.localhost',
        '.local',
        '.internal',
        '.localdomain',
    ];

    /**
     * Validate a partner-supplied URL at the moment they supply it.
     *
     * Throws the partner-facing exception, so a bad URL is a 400 with a code
     * they can branch on rather than a validation message they have to read.
     */
    public function assertAcceptable(string $url): void
    {
        try {
            $this->resolve($url);
        } catch (RuntimeException $e) {
            throw PartnerApiException::invalidRequest(
                'webhook_url_not_allowed',
                $e->getMessage(),
                ['url' => $url],
            );
        }
    }

    /**
     * Re-validate immediately before a delivery and return the IPs the request
     * must be pinned to.
     *
     * @return array{host: string, ips: list<string>}
     *
     * @throws RuntimeException when the target is not safe to call
     */
    public function resolve(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'], $parts['scheme'])) {
            throw new RuntimeException('The webhook URL could not be parsed. Provide an absolute https:// URL.');
        }

        $this->assertScheme(strtolower($parts['scheme']));

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException(
                'The webhook URL must not carry credentials. Use the signature to authenticate us instead.'
            );
        }

        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
        $this->assertPort((int) $port);

        $host = strtolower(trim($parts['host'], '[]'));
        $this->assertHostname($host);

        // The off-production escape hatch skips resolution outright rather than
        // resolving and then permitting everything. A developer pointing a
        // subscription at a container name has no DNS answer we could check,
        // and failing on "does not resolve" would make the hatch useless.
        if ($this->privateNetworksAllowed()) {
            return ['host' => $host, 'ips' => []];
        }

        $ips = $this->addressesFor($host);

        if ($ips === []) {
            throw new RuntimeException("The webhook host '{$host}' does not resolve to any address.");
        }

        foreach ($ips as $ip) {
            $this->assertAddressAllowed($ip, $host);
        }

        return ['host' => $host, 'ips' => $ips];
    }

    /**
     * True when the URL is currently deliverable. Used by the read endpoints to
     * report a target that has since become unreachable without failing the
     * request that asked.
     */
    public function isAcceptable(string $url): bool
    {
        try {
            $this->resolve($url);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function assertScheme(string $scheme): void
    {
        if ($scheme === 'https') {
            return;
        }

        // The escape hatch is off in production regardless of configuration.
        // A production webhook carrying order and offer data over plaintext is
        // not a decision an environment variable gets to make.
        if ($scheme === 'http' && $this->insecureAllowed()) {
            return;
        }

        throw new RuntimeException("Webhook URLs must use https. The scheme '{$scheme}' is not accepted.");
    }

    private function assertPort(int $port): void
    {
        /** @var list<int> $allowed */
        $allowed = config('partner_api.webhooks.allowed_ports', [443]);

        if (! in_array($port, $allowed, true)) {
            throw new RuntimeException(
                "Port {$port} is not accepted for webhook targets. Allowed: ".implode(', ', $allowed).'.'
            );
        }
    }

    private function assertHostname(string $host): void
    {
        if ($host === '') {
            throw new RuntimeException('The webhook URL has no host.');
        }

        if ($this->privateNetworksAllowed()) {
            return;
        }

        foreach (self::BLOCKED_HOST_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, $suffix)) {
                throw new RuntimeException("The webhook host '{$host}' is not publicly routable.");
            }
        }

        // A bare label with no dot is either a container name or an internal
        // DNS shortcut; neither belongs in a public integration.
        if (! str_contains($host, '.') && ! str_contains($host, ':') && ! filter_var($host, FILTER_VALIDATE_IP)) {
            throw new RuntimeException("The webhook host '{$host}' is not a fully qualified domain name.");
        }
    }

    /**
     * @return list<string>
     */
    private function addressesFor(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];

        // Both families. Resolving only A records would leave a host whose AAAA
        // record points at ::1 completely unchecked.
        foreach ([DNS_A, DNS_AAAA] as $type) {
            $records = @dns_get_record($host, $type) ?: [];

            foreach ($records as $record) {
                $addresses[] = $record['ip'] ?? $record['ipv6'] ?? null;
            }
        }

        return array_values(array_unique(array_filter(
            $addresses,
            fn (?string $ip) => $ip !== null && filter_var($ip, FILTER_VALIDATE_IP) !== false,
        )));
    }

    private function assertAddressAllowed(string $ip, string $host): void
    {
        if ($this->privateNetworksAllowed()) {
            return;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $this->assertV6Allowed($ip, $host);

            return;
        }

        foreach (self::BLOCKED_V4 as [$network, $bits, $label]) {
            if ($this->inV4Range($ip, $network, $bits)) {
                throw new RuntimeException(
                    "The webhook host '{$host}' resolves to {$ip}, which is in a blocked range ({$label})."
                );
            }
        }
    }

    private function assertV6Allowed(string $ip, string $host): void
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            throw new RuntimeException("The webhook host '{$host}' resolves to an address we cannot evaluate.");
        }

        $expanded = strtolower(bin2hex($packed));
        $first = hexdec(substr($expanded, 0, 2));

        $blocked = match (true) {
            // ::, ::1
            $expanded === str_repeat('0', 32) => 'unspecified',
            $expanded === str_repeat('0', 31).'1' => 'loopback',
            // fe80::/10 link-local, fec0::/10 site-local
            $first === 0xFE => 'link-local or site-local',
            // fc00::/7 unique local
            ($first & 0xFE) === 0xFC => 'unique local',
            // ff00::/8 multicast
            $first === 0xFF => 'multicast',
            // ::ffff:0:0/96 — an IPv4 address wearing an IPv6 costume
            str_starts_with($expanded, str_repeat('0', 20).'ffff') => 'IPv4-mapped',
            default => null,
        };

        if ($blocked !== null) {
            throw new RuntimeException(
                "The webhook host '{$host}' resolves to {$ip}, which is in a blocked range ({$blocked})."
            );
        }
    }

    private function inV4Range(string $ip, string $network, int $bits): bool
    {
        $address = ip2long($ip);
        $subnet = ip2long($network);

        if ($address === false || $subnet === false) {
            return false;
        }

        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;

        return ($address & $mask) === ($subnet & $mask);
    }

    private function insecureAllowed(): bool
    {
        return ! app()->environment('production')
            && (bool) config('partner_api.webhooks.allow_insecure', false);
    }

    private function privateNetworksAllowed(): bool
    {
        return ! app()->environment('production')
            && (bool) config('partner_api.webhooks.allow_private_networks', false);
    }
}
