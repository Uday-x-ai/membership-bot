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

    // Photo sent with the /start welcome message.
    // Set to a Telegram file_id, a public HTTPS image URL, or leave empty to
    // fall back to a plain text welcome message.
    'welcome_photo' => getenv('WELCOME_PHOTO') ?: '',

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

    // -----------------------------------------------------------------------
    // UPI / Paytm deposit
    // -----------------------------------------------------------------------

    // Paytm merchant ID used when verifying payment status
    'paytm_mid' => getenv('PAYTM_MID') ?: 'YOUR_PAYTM_MID_HERE',

    // Cloudflare Worker (or any proxy) URL that wraps the Paytm status API.
    // The payment_id will be appended as "&id={payment_id}" at runtime.
    // Example: https://paytm.example.workers.dev/?mid=MERCHANT_ID
    'paytm_api_base' => getenv('PAYTM_API_BASE') ?: 'https://paytm.udayscriptsx.workers.dev/?mid=YOUR_PAYTM_MID_HERE',

    // UPI payment address (pa field in the UPI deep-link)
    'upi_pa' => getenv('UPI_PA') ?: 'paytmqr1aictmo962@paytm',

    // Payee display name (pn field)
    'upi_pn' => getenv('UPI_PN') ?: 'Paytm',

    // Transaction note shown to the payer (tn field)
    'upi_tn' => getenv('UPI_TN') ?: 'UdayScripts',

    // How many seconds a pending deposit remains valid before timing out (default 5 min)
    'deposit_timeout_seconds' => (int)(getenv('DEPOSIT_TIMEOUT') ?: 300),
];
