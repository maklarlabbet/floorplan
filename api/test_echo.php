<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login_api();

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
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Expect:'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 30,
    ]);
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
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);
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
