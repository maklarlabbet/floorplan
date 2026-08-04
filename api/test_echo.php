<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login_api();

// Give this diagnostic a bit more room than PHP's default 30s execution limit, in case the
// host's outbound network is fully blocking rather than just corrupting requests (in which
// case both attempts below will need to hit their own connect timeouts before this returns).
if (function_exists('set_time_limit')) {
    @set_time_limit(45);
}

/**
 * This test has nothing to do with Anthropic. It POSTs a small JSON payload to a public
 * echo service (httpbin.org) and checks whether what comes back matches exactly what was
 * sent. If THIS also comes back empty/corrupted, the problem is not specific to Anthropic's
 * API or Cloudflare — it's something in this server's own network path (a firewall, DLP/
 * security appliance, or similar) interfering with outbound HTTPS POST bodies in general,
 * and only your hosting provider's network team can resolve that. If this test passes while
 * calls to Anthropic still fail, the problem is specific to reaching api.anthropic.com.
 */

$test_payload = json_encode(['diagnostic' => 'floorplan-studio-echo-test', 'ts' => time()]);

function echo_test_via_curl($payload) {
    $ch = curl_init('https://httpbin.org/post');
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Expect:'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
    ];
    if (defined('OUTBOUND_PROXY') && OUTBOUND_PROXY !== '') {
        $opts[CURLOPT_PROXY] = OUTBOUND_PROXY;
        if (defined('OUTBOUND_PROXY_AUTH') && OUTBOUND_PROXY_AUTH !== '') {
            $opts[CURLOPT_PROXYUSERPWD] = OUTBOUND_PROXY_AUTH;
        }
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($response === false) {
        return ['ok' => false, 'error' => 'curl request to httpbin.org failed: ' . $err];
    }
    $decoded = json_decode($response, true);
    $echoed = $decoded['data'] ?? null;
    return ['ok' => true, 'echoed' => $echoed, 'matches' => ($echoed === $payload)];
}

function echo_test_via_streams($payload) {
    $http_context = [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $payload,
        'timeout' => 15,
        'ignore_errors' => true,
    ];
    if (defined('OUTBOUND_PROXY') && OUTBOUND_PROXY !== '') {
        $http_context['proxy'] = 'tcp://' . OUTBOUND_PROXY;
        $http_context['request_fulluri'] = true;
        if (defined('OUTBOUND_PROXY_AUTH') && OUTBOUND_PROXY_AUTH !== '') {
            $http_context['header'] .= "Proxy-Authorization: Basic " . base64_encode(OUTBOUND_PROXY_AUTH) . "\r\n";
        }
    }
    $context = stream_context_create(['http' => $http_context]);
    $response = @file_get_contents('https://httpbin.org/post', false, $context);
    if ($response === false) {
        $err = error_get_last();
        return ['ok' => false, 'error' => 'streams request to httpbin.org failed: ' . ($err['message'] ?? 'unknown')];
    }
    $decoded = json_decode($response, true);
    $echoed = $decoded['data'] ?? null;
    return ['ok' => true, 'echoed' => $echoed, 'matches' => ($echoed === $payload)];
}

$curl_result = echo_test_via_curl($test_payload);
$streams_result = echo_test_via_streams($test_payload);

json_response([
    'ok' => true,
    'sent' => $test_payload,
    'curl' => $curl_result,
    'streams' => $streams_result,
]);
