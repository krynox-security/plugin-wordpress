<?php

/**
 * Mock Krynox data plane, served by `php -S` for the integration tests.
 *
 * Every request is appended (JSON-lines) to <state>/requests.log so tests can
 * assert exactly what the plugin sent. Tests may pre-load <state>/queue.json
 * with scripted responses ([{"status":500,"body":{...}}, ...]) — each request
 * consumes one; when the queue is empty the plane falls back to token-based
 * behavior: response === "valid-token" verifies, anything else fails.
 */

$state = getenv('KRYNOX_MOCK_STATE');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);

file_put_contents(
    $state.'/requests.log',
    json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'body' => $body])."\n",
    FILE_APPEND | LOCK_EX
);

header('Content-Type: application/json');

$queueFile = $state.'/queue.json';
if (is_file($queueFile)) {
    $queue = json_decode((string) file_get_contents($queueFile), true);
    if (is_array($queue) && $queue !== []) {
        $next = array_shift($queue);
        file_put_contents($queueFile, json_encode($queue), LOCK_EX);
        http_response_code((int) $next['status']);
        echo is_string($next['body']) ? $next['body'] : json_encode($next['body']);
        return;
    }
}

if ($path === '/siteverify') {
    if (($body['response'] ?? '') === 'valid-token') {
        echo json_encode([
            'success' => true,
            'score' => 0.05,
            'risk' => 'low',
            'hostname' => 'example.test',
            'challenge_ts' => '2026-07-27T00:00:00Z',
            'error-codes' => [],
            'reasons' => [],
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error-codes' => ['invalid-input-response'],
        ]);
    }

    return;
}

if ($path === '/feedback') {
    echo json_encode(['ok' => true]);

    return;
}

http_response_code(404);
echo json_encode(['error' => 'not-found']);
