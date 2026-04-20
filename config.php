<?php

/**
 * Membership Bot Configuration
 *
 * Copy this file, fill in your values, then delete or rename
 * config.example.php if you want to keep a clean template.
 *
 * NEVER commit real tokens / secrets to version control.
 */

return [

    // -----------------------------------------------------------------------
    // Telegram Bot
    // -----------------------------------------------------------------------

    // Bot token from @BotFather
    'bot_token' => getenv('BOT_TOKEN') ?: 'YOUR_BOT_TOKEN_HERE',

    // Payment provider token from @BotFather  (Stripe test: use "PROVIDER_TOKEN")
    // Leave empty to disable Telegram Payments and use manual/external payments
    'payment_provider_token' => getenv('PAYMENT_PROVIDER_TOKEN') ?: '',

    // -----------------------------------------------------------------------
    // Channel
    // -----------------------------------------------------------------------

    // The channel the bot sells access to.
    // Use a numeric ID (e.g. -1001234567890) or a public username (@mychannel).
    'channel_id' => getenv('CHANNEL_ID') ?: '@your_channel',

    // -----------------------------------------------------------------------
    // Admins
    // -----------------------------------------------------------------------

    // Telegram user IDs that may use admin commands (/admin, /stats, /plans …)
    'admin_ids' => array_filter(array_map(
        'intval',
        explode(',', getenv('ADMIN_IDS') ?: '0')
    )),

    // -----------------------------------------------------------------------
    // Database
    // -----------------------------------------------------------------------

    // Absolute path to the SQLite database file.
    'db_path' => getenv('DB_PATH') ?: __DIR__ . '/data/membership.db',

    // -----------------------------------------------------------------------
    // Membership plans
    // -----------------------------------------------------------------------

    // Each plan has:
    //   name          – displayed to the user
    //   description   – short description shown in the invoice / plan list
    //   duration_days – how many days of access are granted after payment
    //   price         – amount in the smallest currency unit
    //                   (e.g. cents for USD: 999 = $9.99)
    //   currency      – ISO 4217 code (USD, EUR, …)
    'plans' => [
        [
            'id'            => 'monthly',
            'name'          => '1 Month Membership',
            'description'   => 'Full access to the channel for 30 days.',
            'duration_days' => 30,
            'price'         => 999,   // $9.99
            'currency'      => 'USD',
        ],
        [
            'id'            => 'quarterly',
            'name'          => '3 Month Membership',
            'description'   => 'Full access to the channel for 90 days.',
            'duration_days' => 90,
            'price'         => 2499,  // $24.99
            'currency'      => 'USD',
        ],
        [
            'id'            => 'yearly',
            'name'          => '1 Year Membership',
            'description'   => 'Full access to the channel for 365 days.',
            'duration_days' => 365,
            'price'         => 7999,  // $79.99
            'currency'      => 'USD',
        ],
    ],

    // -----------------------------------------------------------------------
    // Webhook
    // -----------------------------------------------------------------------

    // Public HTTPS URL where Telegram will POST updates (set during setup.php)
    'webhook_url' => getenv('WEBHOOK_URL') ?: 'https://your-server.example.com/index.php',

    // -----------------------------------------------------------------------
    // Logging
    // -----------------------------------------------------------------------

    // Set to true to write incoming updates + errors to the log file below
    'debug'    => (bool)(getenv('BOT_DEBUG') ?: false),

    // Absolute path to the log file (used by both index.php and the bot)
    'log_path' => getenv('LOG_PATH') ?: __DIR__ . '/data/bot.log',
];
