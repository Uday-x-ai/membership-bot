<?php

declare(strict_types=1);

/**
 * One-time setup helper.
 *
 * Run from the CLI or visit in a browser (once):
 *
 *   php setup.php [--register-webhook] [--delete-webhook] [--info]
 *
 * Or via HTTP: https://your-server.example.com/setup.php?action=register
 *
 * ⚠️  Delete or restrict access to this file after setup is complete.
 */

require_once __DIR__ . '/src/TelegramAPI.php';
require_once __DIR__ . '/src/Database.php';

use MembershipBot\Database;
use MembershipBot\TelegramAPI;

$config = require __DIR__ . '/config.php';

// Determine action (CLI args or query string)
$isCli   = PHP_SAPI === 'cli';
$action  = 'info';

if ($isCli) {
    $opts   = getopt('', ['register-webhook', 'delete-webhook', 'info', 'init-db']);
    if (isset($opts['register-webhook'])) {
        $action = 'register';
    } elseif (isset($opts['delete-webhook'])) {
        $action = 'delete';
    } elseif (isset($opts['init-db'])) {
        $action = 'init-db';
    }
} else {
    $action = $_GET['action'] ?? 'info';
}

$out = [];

try {
    $api = new TelegramAPI($config['bot_token']);

    // Always initialise the database schema
    $db = new Database($config['db_path']);
    $out[] = '✅ Database initialised at: ' . $config['db_path'];

    if ($action === 'register') {
        $result = $api->setWebhook($config['webhook_url']);
        $out[]  = '✅ Webhook registered: ' . json_encode($result);
    }

    if ($action === 'delete') {
        $result = $api->deleteWebhook();
        $out[]  = '✅ Webhook deleted: ' . json_encode($result);
    }

    // Always show current webhook info
    $info   = $api->getWebhookInfo();
    $out[]  = '🔎 Webhook info: ' . json_encode($info, JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    $out[] = '❌ Error: ' . $e->getMessage();
}

if ($isCli) {
    echo implode(PHP_EOL, $out) . PHP_EOL;
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo implode("\n", $out) . "\n";
}
