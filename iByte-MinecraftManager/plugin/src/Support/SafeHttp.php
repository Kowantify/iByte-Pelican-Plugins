<?php

declare(strict_types=1);

namespace IByte\MinecraftManager\Support;

use IByte\MinecraftManager\Catalog\JsonTransport;
use JsonException;

final class SafeHttp implements JsonTransport
{
    private const HOSTS = ['modrinth' => 'api.modrinth.com', 'curseforge' => 'api.curseforge.com', 'mcjars' => 'mcjars.app'];

    private array $clients = [];

    public function __construct(private readonly Settings $settings) {}

    public function get(string $provider, string $path, array $query = []): array
    {
        return $this->request($provider, $path, $query);
    }

    public function post(string $provider, string $path, array $body): array
    {
        return $this->request($provider, $path, [], $body);
    }

    private function request(string $provider, string $path, array $query, ?array $payload = null): array
    {
        $host = self::HOSTS[$provider] ?? throw new Failure('unsupported_provider');
        if (!str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, '?') || str_contains($path, '#')) {
            throw new Failure('invalid_provider_path');
        }
        $url = 'https://' . $host . $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        UrlPolicy::host($url, [$host]);
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];
        foreach ($records ?: [] as $record) {
            if (isset($record['ip']) || isset($record['ipv6'])) {
                $addresses[] = $record['ip'] ?? $record['ipv6'];
            }
        }
        $ip = UrlPolicy::pinnedAddress(array_values(array_unique($addresses)));
        $headers = ['Accept: application/json'];
        if ($provider === 'curseforge') {
            $key = $this->settings->credential('curseforge');
            if (!$key) {
                throw new Failure('credentials_required');
            }
            $headers[] = 'x-api-key: ' . $key;
        }
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $body = '';
        $limit = min(4194304, max(65536, (int) config('ibyte-minecraft-manager.max_json_bytes', 4194304)));
        // Keep provider connections alive during large manifest resolution.
        // Separate handles prevent credentials crossing provider boundaries.
        $curl = $this->clients[$provider] ??= curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'iByte-Minecraft-Manager/0.1.0-preview.27',
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            // Resolve once, validate all answers, and bind the actual TLS request to this IP.
            CURLOPT_RESOLVE => [$host . ':443:' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $limit): int {
                if (strlen($body) + strlen($chunk) > $limit) {
                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        if ($payload !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
            curl_setopt($curl, CURLOPT_POST, true);
        } else {
            curl_setopt($curl, CURLOPT_HTTPGET, true);
        }
        $ok = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($curl);
        if ($ok === false) {
            throw new Failure($error === CURLE_WRITE_ERROR ? 'response_too_large' : 'provider_unavailable');
        }
        if ($status !== 200) {
            throw new Failure(match (true) {
                $status === 429 => 'rate_limited',
                $status === 401 => 'credentials_rejected',
                $status === 403 => 'provider_forbidden',
                $status === 404 => 'exact_release_unavailable',
                $status >= 300 && $status < 400 => 'redirect_refused',
                default => 'provider_unavailable',
            });
        }
        try {
            $json = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new Failure('provider_schema_changed');
        }
        if (!is_array($json)) {
            throw new Failure('provider_schema_changed');
        }

        return $json;
    }
}
