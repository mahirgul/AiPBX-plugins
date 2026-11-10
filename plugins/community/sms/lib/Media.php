<?php

declare(strict_types=1);

/** Downloads untrusted provider media without allowing access to the PBX's network. */
final class SmsMedia
{
    /** @return array{0: string, 1: list<string>} host and validated public addresses */
    public static function destination(string $url): array
    {
        $p = parse_url($url);
        if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])
            || isset($p['user']) || isset($p['pass']) || (int) ($p['port'] ?? 443) !== 443
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new RuntimeException('Invalid media URL');
        }
        $host = trim($p['host'], '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = [$host];
        } else {
            if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/iD', $host)) {
                throw new RuntimeException('Invalid media host');
            }
            $records = dns_get_record($host, DNS_A | DNS_AAAA);
            $addresses = [];
            foreach ($records ?: [] as $record) {
                if (isset($record['ip'])) { $addresses[] = $record['ip']; }
                if (isset($record['ipv6'])) { $addresses[] = $record['ipv6']; }
            }
        }
        if ($addresses === []) {
            throw new RuntimeException('Media host has no addresses');
        }
        foreach ($addresses as $ip) {
            // IPv6 transition/mapped ranges must not bypass the IPv4 policy.
            $packed = inet_pton($ip);
            $v6 = str_contains($ip, ':');
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)
                || (!$v6 && $packed !== false && ord($packed[0]) >= 224)
                || ($v6 && ($packed === false || (ord($packed[0]) & 0xe0) !== 0x20
                    || substr($packed, 0, 2) === "\x20\x02"
                    || substr($packed, 0, 4) === "\x20\x01\x00\x00"))) {
                throw new RuntimeException('Media host must be a public address');
            }
        }
        return [$host, array_values(array_unique($addresses))];
    }

    /** @return array{0: string, 1: string} content type and body */
    public static function download(SmsProvider $provider, string $url, int $limit): array
    {
        $deadline = microtime(true) + 8;
        for ($hop = 0; $hop <= 3; $hop++) {
            [$host, $ips] = self::destination($url);
            $remaining = (int) (($deadline - microtime(true)) * 1000);
            if ($remaining <= 0) { throw new RuntimeException('Media timeout'); }
            $body = '';
            $ch = curl_init($url);
            $ip = str_contains($ips[0], ':') ? '[' . $ips[0] . ']' : $ips[0];
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_PROXY => '', // A proxy could resolve the host again outside this policy.
                CURLOPT_RESOLVE => filter_var($host, FILTER_VALIDATE_IP) ? [] : [$host . ':443:' . $ip],
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT_MS => $remaining, CURLOPT_CONNECTTIMEOUT_MS => min(3000, $remaining),
                CURLOPT_HTTPHEADER => $provider->mediaHeaders($url),
                CURLOPT_MAXFILESIZE => $limit,
                CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, $limit): int {
                    if (strlen($body) + strlen($chunk) > $limit) { return 0; }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($ok === false) { throw new RuntimeException('Media download failed or exceeded limit'); }
            if (in_array($code, [301, 302, 303, 307, 308], true)) {
                $url = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
                // Start a new request: revalidate DNS/IP and recompute provider credentials.
                continue;
            }
            if ($code !== 200) { throw new RuntimeException("HTTP {$code}"); }
            return [(string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream', $body];
        }
        throw new RuntimeException('Too many media redirects');
    }
}
