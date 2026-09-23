<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/**
 * Standalone probe used only by TezkorClaimPostgresRaceTest. Boots a FRESH
 * Laravel application in its own OS process (not a fork of the test runner)
 * and submits exactly one Tezkor claim, so several of these running at once
 * are genuinely concurrent — separate processes, separate DB connections —
 * the same way separate PHP-FPM workers would be in production.
 *
 * argv: [orderId, bearerToken, outputFile]
 */

require __DIR__.'/../../vendor/autoload.php';

[, $orderId, $token, $outputFile] = $argv;

$app = require __DIR__.'/../../bootstrap/app.php';
/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);

$request = Request::create(
    "/api/v1/agent/orders/{$orderId}/offers",
    'POST',
    [],
    [],
    [],
    [
        'HTTP_AUTHORIZATION' => "Bearer {$token}",
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
    ],
    '{}',
);

$response = $kernel->handle($request);

file_put_contents($outputFile, json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode($response->getContent() ?: 'null', true),
]));

$kernel->terminate($request, $response);
