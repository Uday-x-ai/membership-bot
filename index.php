<?php

declare(strict_types=1);

/**
 * Webhook entry point.
 *
 * Telegram will POST every update as JSON to this URL.
 * The script must respond with HTTP 200 within ~5 seconds;
 * heavy work should be deferred or handled asynchronously.
 */

require_once __DIR__ . '/src/TelegramAPI.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/MembershipBot.php';

use MembershipBot\Database;
use MembershipBot\MembershipBot;
use MembershipBot\TelegramAPI;

// -------------------------------------------------------------------------
// Bootstrap
// -------------------------------------------------------------------------

$config = require __DIR__ . '/config.php';

// Always respond 200 quickly so Telegram does not retry
http_response_code(200);
header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'description' => 'Only POST allowed']);
    exit;
}

$body = file_get_contents('php://input');
if ($body === false || $body === '') {
    echo json_encode(['ok' => false, 'description' => 'Empty body']);
    exit;
}

$update = json_decode($body, true);
if (!is_array($update)) {
    echo json_encode(['ok' => false, 'description' => 'Invalid JSON']);
    exit;
}

// -------------------------------------------------------------------------
// Handle update
// -------------------------------------------------------------------------

try {
    $api = new TelegramAPI($config['bot_token']);
    $db  = new Database($config['db_path']);
    $bot = new MembershipBot($api, $db, $config);

    $bot->handleUpdate($update);

    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    // Log error without leaking details in the response body
    if ($config['debug'] ?? false) {
        $logPath = $config['log_path'] ?? (dirname($config['db_path']) . '/bot.log');
        $line    = '[' . date('Y-m-d H:i:s') . '] FATAL: ' . $e->getMessage()
                 . ' in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
        file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
    }
    echo json_encode(['ok' => false, 'description' => 'Internal error']);
}
