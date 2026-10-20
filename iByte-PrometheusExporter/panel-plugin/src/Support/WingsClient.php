<?php

namespace IByte\PrometheusExporter\Support;

/** Fixed GET-only HTTPS transport using each node's configured FQDN and daemon port. */
final class WingsClient
{
    public const MAX_BYTES = 2097152;

    /**
     * Test-only DNS map from IBYTE_PROMETHEUS_RESOLVE, e.g. "fixture.test:18499=>127.0.0.1".
     * Production never sets it; the local transport fixture needs an IP override because
     * ".test" names do not resolve in a disposable environment without real DNS.
     * @return array<string>
     */
    private static function overrides(): array
    {
        $raw = getenv('IBYTE_PROMETHEUS_RESOLVE') ?: '';
        if ($raw === '') return [];
        $entries = [];
        foreach (explode(',', $raw) as $pair) {
            if (preg_match('/\A([a-zA-Z0-9.-]+):(\d+)=>(\d{1,3}(?:\.\d{1,3}){3})\z/', trim($pair), $m)) {
                $entries[] = "{$m[1]}:{$m[2]}:{$m[3]}";
            }
        }
        return $entries;
    }

    /** @return array<int, array{body: ?string, duration: float}> */
    public static function collect(iterable $nodes, int $timeout, int $concurrency): array
    {
        if (!extension_loaded('curl')) throw new \RuntimeException('PHP cURL is required.');
        $results = [];
        $queue = is_array($nodes) ? array_values($nodes) : iterator_to_array($nodes, false);
        $timeout = max(1, min(4, $timeout));
        $concurrency = max(1, min(10, $concurrency));
        // Chunking bounds open sockets and total response memory (2 MiB per node).
        foreach (array_chunk($queue, $concurrency) as $chunk) {
            $multi = curl_multi_init();
            $active = [];
            $bodies = [];
            try {
                foreach ($chunk as $node) {
                    $id = (int) $node->id;
                    $host = $node->fqdn;
                    $port = (int) $node->daemon_connect;
                    $results[$id] = ['body' => null, 'duration' => 0.0];
                    // Skip nodes that do not describe a stock HTTPS Wings endpoint;
                    // they simply report no data until an administrator fixes them.
                    if ($node->scheme !== 'https' || !is_string($host) ||
                        !preg_match('/\A[a-zA-Z0-9.-]+\z/D', $host) ||
                        $port < 1 || $port > 65535) {
                        continue;
                    }
                    $bodies[$id] = '';
                    $handle = curl_init('https://'.$host.':'.$port.'/api/servers');
                    $options = [
                        CURLOPT_HTTPGET => true,
                        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer '.$node->daemon_token],
                        CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                        CURLOPT_PROXY => '',
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_SSL_VERIFYHOST => 2,
                        CURLOPT_CONNECTTIMEOUT_MS => 1000,
                        CURLOPT_TIMEOUT_MS => $timeout * 1000,
                        CURLOPT_WRITEFUNCTION => static function ($handle, string $data) use (&$bodies, $id): int {
                            if (strlen($bodies[$id]) + strlen($data) > self::MAX_BYTES) return 0;
                            $bodies[$id] .= $data;
                            return strlen($data);
                        },
                    ];
                    $resolve = self::overrides();
                    if ($resolve !== []) $options[CURLOPT_RESOLVE] = $resolve;
                    curl_setopt_array($handle, $options);
                    curl_multi_add_handle($multi, $handle);
                    $active[$id] = $handle;
                }
                // Canonical curl_multi loop: drain CURLM_CALL_MULTI_PERFORM, wait only
                // while handles are still running, and finish only when running === 0
                // after the transfer messages are read. A naive exec/select loop can
                // exit while fresh handles are still connecting (running briefly 0).
                do {
                    $running = 0;
                    do {
                        $status = curl_multi_exec($multi, $running);
                    } while ($status === CURLM_CALL_MULTI_PERFORM);
                    if ($status !== CURLM_OK) throw new \RuntimeException('Transport failure');
                    if ($running !== 0) curl_multi_select($multi, 0.1);
                } while ($running !== 0);
                $completion = [];
                while ($message = curl_multi_info_read($multi)) {
                    $completion[spl_object_id($message['handle'])] = $message['result'];
                }
                foreach ($active as $id => $handle) {
                    $ok = ($completion[spl_object_id($handle)] ?? -1) === CURLE_OK &&
                        curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 200;
                    $results[$id] = ['body' => $ok ? ($bodies[$id] ?? '') : null,
                        'duration' => curl_getinfo($handle, CURLINFO_TOTAL_TIME)];
                }
            } finally {
                foreach ($active as $handle) curl_multi_remove_handle($multi, $handle);
                curl_multi_close($multi);
            }
        }
        return $results;
    }
}
