<?php

declare(strict_types=1);

namespace OffsiteBackups\Support;

/**
 * Keeps admin supplied endpoints from pointing the panel at its own network.
 */
final class Hosts
{
    /** The reason the URL may not be used, or null when it is fine. */
    public static function urlProblem(string $url, bool $allowPrivate): ?string
    {
        $parts = parse_url($url);
        $scheme = mb_strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        if ($host === '' || ! in_array($scheme, $allowPrivate ? ['https', 'http'] : ['https'], true)) {
            return $allowPrivate ? 'Use an http or https URL.' : 'Use an https URL.';
        }

        return self::hostProblem($host, $allowPrivate);
    }

    /** The reason the host may not be used, or null when it is fine. */
    public static function hostProblem(string $host, bool $allowPrivate): ?string
    {
        $host = mb_trim($host, '[]');

        if ($host === '' || preg_match('/[\s\/?#@]/', $host)) {
            return 'Enter a host name or IP address.';
        }

        if ($allowPrivate) {
            return null;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::lookup($host);

        if ($addresses === []) {
            return "{$host} could not be found.";
        }

        foreach ($addresses as $address) {
            if (! self::isPublic($address)) {
                return "{$host} points to a private address. Allow private destinations in the settings to use it.";
            }
        }

        return null;
    }

    public static function isPublic(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** @return list<string> */
    private static function lookup(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $addresses = array_values(array_filter(array_map(static fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null, $records)));

        return $addresses !== [] ? $addresses : (@gethostbynamel($host) ?: []);
    }
}
