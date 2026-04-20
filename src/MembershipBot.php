<?php

declare(strict_types=1);

namespace MembershipBot;

/**
 * Main bot logic: handles all incoming Telegram updates.
 *
 * Update types handled:
 *   - message (commands, text)
 *   - callback_query (inline-keyboard actions)
 *   - pre_checkout_query (Telegram Payments approval gate)
 *   - message.successful_payment (payment confirmed)
 */
class MembershipBot
{
    private TelegramAPI $api;
    private Database    $db;
    private array       $config;

    /** Indexed by plan 'id' for fast lookup */
    private array $plansById;

    public function __construct(TelegramAPI $api, Database $db, array $config)
    {
        $this->api    = $api;
        $this->db     = $db;
        $this->config = $config;

        $this->plansById = [];
        foreach ($config['plans'] as $plan) {
            $this->plansById[$plan['id']] = $plan;
        }
    }

    // -----------------------------------------------------------------------
    // Entry point
    // -----------------------------------------------------------------------

    /**
     * Process a decoded Telegram Update array.
     */
    public function handleUpdate(array $update): void
    {
        if (isset($update['pre_checkout_query'])) {
            $this->handlePreCheckout($update['pre_checkout_query']);
            return;
        }

        if (isset($update['callback_query'])) {
            $this->handleCallbackQuery($update['callback_query']);
            return;
        }

        if (isset($update['message'])) {
            $msg = $update['message'];

            // Successful payment confirmation
            if (isset($msg['successful_payment'])) {
                $this->handleSuccessfulPayment($msg);
                return;
            }

            // Regular message / command
            if (isset($msg['text'])) {
                $this->handleMessage($msg);
            }
        }
    }

    // -----------------------------------------------------------------------
    // Messages / Commands
    // -----------------------------------------------------------------------

    private function handleMessage(array $msg): void
    {
        $chatId = $msg['chat']['id'];
        $userId = $msg['from']['id'];
        $text   = trim($msg['text']);

        // Strip bot username from commands (e.g. /start@MyBot → /start)
        $firstWord = explode(' ', $text)[0];
        $command   = strtolower(explode('@', $firstWord)[0]);

        match ($command) {
            '/start'  => $this->cmdStart($chatId, $userId, $msg['from']),
            '/buy'    => $this->cmdBuy($chatId),
            '/status' => $this->cmdStatus($chatId, $userId),
            '/help'   => $this->cmdHelp($chatId),
            '/admin'  => $this->cmdAdmin($chatId, $userId),
            '/stats'  => $this->cmdAdmin($chatId, $userId),
            '/plans'  => $this->cmdListPlans($chatId, $userId),
            default   => $this->cmdUnknown($chatId, $text),
        };
    }

    // -----------------------------------------------------------------------
    // Command handlers
    // -----------------------------------------------------------------------

    private function cmdStart(int $chatId, int $userId, array $from): void
    {
        $name    = htmlspecialchars($from['first_name'] ?? 'there', ENT_QUOTES);
        $caption = "👋 <b>Welcome, {$name}!</b>\n\n"
                 . "This bot gives you access to our exclusive channel.\n\n"
                 . "Use the menu below to get started:";

        $photo = $this->config['welcome_photo'] ?? '';

        if ($photo !== '') {
            $this->api->sendPhoto($chatId, $photo, $caption, [
                'reply_markup' => $this->mainMenuKeyboard(),
            ]);
        } else {
            $this->api->sendMessage($chatId, $caption, [
                'reply_markup' => $this->mainMenuKeyboard(),
            ]);
        }
    }

    private function cmdBuy(int $chatId): void
    {
        $this->sendPlanSelector($chatId);
    }

    private function cmdStatus(int $chatId, int $userId): void
    {
        $membership = $this->db->getActiveMembership($userId);

        if (!$membership) {
            $this->api->sendMessage(
                $chatId,
                "❌ <b>No active membership found.</b>\n\nUse /buy to purchase access.",
                ['reply_markup' => $this->mainMenuKeyboard()]
            );
            return;
        }

        $plan      = $this->plansById[$membership['plan_id']] ?? ['name' => $membership['plan_id']];
        $expiresAt = (int)$membership['expires_at'];
        $daysLeft  = max(0, (int)ceil(($expiresAt - time()) / 86400));
        $dateStr   = date('Y-m-d H:i', $expiresAt) . ' UTC';

        $text = "✅ <b>Active Membership</b>\n\n"
              . "📦 Plan: <b>{$plan['name']}</b>\n"
              . "⏳ Expires: <b>{$dateStr}</b>\n"
              . "📅 Days remaining: <b>{$daysLeft}</b>";

        $this->api->sendMessage($chatId, $text, [
            'reply_markup' => $this->mainMenuKeyboard(),
        ]);
    }

    private function cmdHelp(int $chatId): void
    {
        $text = "<b>Available Commands</b>\n\n"
              . "/start  – Show main menu\n"
              . "/buy    – Purchase a membership plan\n"
              . "/status – Check your current membership\n"
              . "/help   – Show this message\n\n"
              . "For support, contact the channel admin.";

        $this->api->sendMessage($chatId, $text);
    }

    private function cmdAdmin(int $chatId, int $userId): void
    {
        if (!$this->isAdmin($userId)) {
            $this->api->sendMessage($chatId, '⛔ You are not authorised to use this command.');
            return;
        }

        $stats = $this->db->getStats();
        $text  = "🛠 <b>Admin Panel</b>\n\n"
               . "📊 <b>Statistics</b>\n"
               . "├ Total paid orders : <b>{$stats['total_paid']}</b>\n"
               . "├ Active members    : <b>{$stats['active_members']}</b>\n"
               . "├ Paid today        : <b>{$stats['today_paid']}</b>\n"
               . "└ Expiring in 3 days: <b>{$stats['expiring_soon']}</b>\n\n"
               . "Use the buttons below to manage the bot:";

        $this->api->sendMessage($chatId, $text, [
            'reply_markup' => $this->adminKeyboard(),
        ]);
    }

    private function cmdListPlans(int $chatId, int $userId): void
    {
        if (!$this->isAdmin($userId)) {
            $this->api->sendMessage($chatId, '⛔ You are not authorised to use this command.');
            return;
        }

        $text = "📋 <b>Configured Plans</b>\n\n";
        foreach ($this->config['plans'] as $plan) {
            $price   = number_format($plan['price'] / 100, 2);
            $text   .= "• <b>{$plan['name']}</b> (ID: <code>{$plan['id']}</code>)\n"
                     . "  {$plan['description']}\n"
                     . "  💵 {$price} {$plan['currency']} / {$plan['duration_days']} days\n\n";
        }

        $this->api->sendMessage($chatId, $text);
    }

    private function cmdUnknown(int $chatId, string $text): void
    {
        // Only respond to messages that look like commands
        if (str_starts_with($text, '/')) {
            $this->api->sendMessage(
                $chatId,
                "❓ Unknown command. Use /help to see available commands."
            );
        }
    }

    // -----------------------------------------------------------------------
    // Callback queries (inline keyboard presses)
    // -----------------------------------------------------------------------

    private function handleCallbackQuery(array $cbq): void
    {
        $cbqId  = $cbq['id'];
        $chatId = $cbq['message']['chat']['id'];
        $msgId  = $cbq['message']['message_id'];
        $userId = $cbq['from']['id'];
        $data   = $cbq['data'] ?? '';

        $this->api->answerCallbackQuery($cbqId);

        if ($data === 'menu_buy') {
            $this->sendPlanSelector($chatId, $msgId);
            return;
        }

        if ($data === 'menu_status') {
            $this->cmdStatus($chatId, $userId);
            return;
        }

        if ($data === 'menu_help') {
            $this->cmdHelp($chatId);
            return;
        }

        if ($data === 'admin_stats') {
            $this->cmdAdmin($chatId, $userId);
            return;
        }

        if ($data === 'admin_recent') {
            $this->sendRecentOrders($chatId, $userId);
            return;
        }

        if ($data === 'admin_plans') {
            $this->cmdListPlans($chatId, $userId);
            return;
        }

        if (str_starts_with($data, 'buy_plan:')) {
            $planId = substr($data, 9);
            $this->initiatePurchase($chatId, $userId, $planId);
            return;
        }

        if ($data === 'back_to_plans') {
            $this->sendPlanSelector($chatId, $msgId);
            return;
        }

        if ($data === 'deposit') {
            $this->handleDeposit($chatId, $userId);
            return;
        }

        if (str_starts_with($data, 'check_deposit:')) {
            $paymentId = substr($data, 14);
            $this->handleCheckDeposit($chatId, $userId, $paymentId, $msgId);
            return;
        }

        if (str_starts_with($data, 'cancel_deposit:')) {
            $paymentId = substr($data, 15);
            $this->handleCancelDeposit($chatId, $paymentId, $msgId);
            return;
        }
    }

    // -----------------------------------------------------------------------
    // Payment flow
    // -----------------------------------------------------------------------

    /**
     * Send the plan-selection inline keyboard.
     * If $editMessageId is provided, the existing message is edited in place.
     */
    private function sendPlanSelector(int $chatId, ?int $editMessageId = null): void
    {
        $text    = "🛒 <b>Choose a Membership Plan</b>\n\n";
        $buttons = [];

        foreach ($this->config['plans'] as $plan) {
            $price    = number_format($plan['price'] / 100, 2);
            $text    .= "📦 <b>{$plan['name']}</b>\n"
                      . "   {$plan['description']}\n"
                      . "   💵 {$price} {$plan['currency']} / {$plan['duration_days']} days\n\n";
            $buttons[] = [
                ['text' => "Buy – {$plan['name']} ({$price} {$plan['currency']})", 'callback_data' => "buy_plan:{$plan['id']}"],
            ];
        }

        $keyboard = ['inline_keyboard' => $buttons];

        if ($editMessageId !== null) {
            try {
                $this->api->editMessageText($chatId, $editMessageId, $text, ['reply_markup' => $keyboard]);
            } catch (\RuntimeException) {
                // Message may not have changed; ignore
            }
        } else {
            $this->api->sendMessage($chatId, $text, ['reply_markup' => $keyboard]);
        }
    }

    /**
     * Initiate a purchase for the given plan.
     * With a payment provider token: send a Telegram invoice.
     * Without one: show a fallback message (extend as needed).
     */
    private function initiatePurchase(int $chatId, int $userId, string $planId): void
    {
        $plan = $this->plansById[$planId] ?? null;
        if (!$plan) {
            $this->api->sendMessage($chatId, '❌ Invalid plan. Please try again.');
            return;
        }

        $providerToken = $this->config['payment_provider_token'];

        if ($providerToken !== '') {
            $this->sendTelegramInvoice($chatId, $userId, $plan, $providerToken);
        } else {
            $this->sendManualPaymentInfo($chatId, $plan);
        }
    }

    /**
     * Send a native Telegram invoice.
     */
    private function sendTelegramInvoice(int $chatId, int $userId, array $plan, string $providerToken): void
    {
        // Create a pending order; embed its ID in the invoice payload
        $orderId = $this->db->createOrder($userId, null, $plan['id']);

        $payload = json_encode(['order_id' => $orderId, 'plan_id' => $plan['id']]);

        $this->api->sendInvoice(
            $chatId,
            $plan['name'],
            $plan['description'],
            $payload,
            $providerToken,
            $plan['currency'],
            [['label' => $plan['name'], 'amount' => $plan['price']]],
            ['need_name' => false, 'need_phone_number' => false, 'need_email' => false]
        );
    }

    /**
     * Fallback when no payment provider token is configured.
     * Extend this method to integrate with an external payment gateway.
     */
    private function sendManualPaymentInfo(int $chatId, array $plan): void
    {
        $price = number_format($plan['price'] / 100, 2);
        $text  = "💳 <b>Manual Payment</b>\n\n"
               . "Plan: <b>{$plan['name']}</b>\n"
               . "Price: <b>{$price} {$plan['currency']}</b>\n"
               . "Duration: <b>{$plan['duration_days']} days</b>\n\n"
               . "⚠️ No payment provider is configured yet.\n"
               . "Please contact the admin to complete your purchase.";

        $this->api->sendMessage($chatId, $text);
    }

    /**
     * Telegram calls this before charging the user.
     * Validate the order payload and approve or reject.
     */
    private function handlePreCheckout(array $pcq): void
    {
        $payload = json_decode($pcq['invoice_payload'], true);
        $orderId = (int)($payload['order_id'] ?? 0);
        $planId  = $payload['plan_id'] ?? '';

        $order = $orderId > 0 ? $this->db->getOrder($orderId) : null;
        $plan  = $this->plansById[$planId] ?? null;

        if (!$order || !$plan || $order['status'] !== 'pending') {
            $this->api->answerPreCheckoutQuery(
                $pcq['id'],
                false,
                'Sorry, this order is no longer valid. Please start a new purchase.'
            );
            return;
        }

        $this->api->answerPreCheckoutQuery($pcq['id'], true);
    }

    /**
     * Payment was successful. Grant membership and send the invite link.
     */
    private function handleSuccessfulPayment(array $msg): void
    {
        $userId  = $msg['from']['id'];
        $chatId  = $msg['chat']['id'];
        $payment = $msg['successful_payment'];
        $payload = json_decode($payment['invoice_payload'], true);
        $orderId = (int)($payload['order_id'] ?? 0);
        $planId  = $payload['plan_id'] ?? '';

        $plan = $this->plansById[$planId] ?? null;
        if (!$plan || $orderId <= 0) {
            $this->api->sendMessage($chatId, '⚠️ Payment received but plan not found. Please contact support.');
            return;
        }

        // Mark order paid
        $chargeId = $payment['telegram_payment_charge_id'] ?? '';
        $this->db->markOrderPaid($orderId, $chargeId);

        // Generate a single-use invite link (expires in 72 h for safety)
        $inviteLink  = '';
        $channelId   = $this->config['channel_id'];
        $expireDate  = time() + 72 * 3600;

        try {
            $result     = $this->api->createChatInviteLink($channelId, 1, $expireDate);
            $inviteLink = $result['invite_link'] ?? '';
        } catch (\RuntimeException $e) {
            // Non-fatal: log and continue; user may contact admin for link
            $this->log('createChatInviteLink failed: ' . $e->getMessage());
        }

        // Grant membership (extends existing membership if any)
        $membership = $this->db->grantMembership(
            $userId,
            $planId,
            $orderId,
            $plan['duration_days'],
            $inviteLink
        );

        $expiresStr = date('Y-m-d', (int)$membership['expires_at']) . ' UTC';
        $price      = number_format($plan['price'] / 100, 2);

        $text = "🎉 <b>Payment Successful!</b>\n\n"
              . "✅ Thank you for purchasing <b>{$plan['name']}</b>.\n"
              . "💵 Amount paid: <b>{$price} {$plan['currency']}</b>\n"
              . "📅 Access expires: <b>{$expiresStr}</b>\n\n";

        if ($inviteLink !== '') {
            $text .= "🔗 <b>Your invite link (single-use, valid 72 h):</b>\n{$inviteLink}\n\n"
                   . "⚠️ This link can only be used once. Do not share it.";
        } else {
            $text .= "⚠️ We could not generate an invite link automatically.\n"
                   . "Please contact the admin to receive access.";
        }

        $this->api->sendMessage($chatId, $text);
    }

    // -----------------------------------------------------------------------
    // UPI / Paytm deposit flow
    // -----------------------------------------------------------------------

    /**
     * Initiate a new UPI deposit: generate a unique payment ID, build a QR
     * code URL, send the photo, and store a pending-deposit record.
     */
    private function handleDeposit(int $chatId, int $userId): void
    {
        $paymentId  = $this->generateRandom18Digit();
        $last4      = substr($paymentId, -4);
        $timeout    = (int)($this->config['deposit_timeout_seconds'] ?? 300);

        $upiLink  = 'upi://pay'
                  . '?pa=' . rawurlencode($this->config['upi_pa'] ?? '')
                  . '&pn=' . rawurlencode($this->config['upi_pn'] ?? '')
                  . '&tr=' . $paymentId
                  . '&tn=' . rawurlencode($this->config['upi_tn'] ?? '');
        $qrUrl    = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data='
                  . rawurlencode($upiLink);

        $caption  = "💳 <b>Pay via UPI / Paytm</b>\n\n"
                  . "Scan the QR code to complete your payment.\n\n"
                  . "<i>ID: XXXXXXXXXXXXXX{$last4}</i>\n\n"
                  . "⏳ Complete payment within <b>" . (int)($timeout / 60) . " minutes</b>.\n"
                  . "Press <b>✅ I've Paid</b> after completing the payment.";

        $keyboard = ['inline_keyboard' => [
            [['text' => "✅ I've Paid", 'callback_data' => "check_deposit:{$paymentId}"]],
            [['text' => '❌ Cancel',    'callback_data' => "cancel_deposit:{$paymentId}"]],
        ]];

        $sentMsg = $this->api->sendPhoto($chatId, $qrUrl, $caption, [
            'reply_markup' => $keyboard,
        ]);

        $messageId = (int)($sentMsg['message_id'] ?? 0);
        if ($messageId > 0) {
            $this->db->upsertPendingDeposit($userId, $paymentId, $messageId);
        }
    }

    /**
     * Check whether the UPI payment identified by $paymentId has been received.
     * Called when the user presses "✅ I've Paid".
     */
    private function handleCheckDeposit(int $chatId, int $userId, string $paymentId, int $msgId): void
    {
        $pending = $this->db->getPendingDepositByPaymentId($paymentId);

        if (!$pending || (int)$pending['user_id'] !== $userId) {
            // Stale button – deposit was already processed or cancelled
            try {
                $this->api->editMessageCaption($chatId, $msgId,
                    '❌ <b>This deposit is no longer active.</b>\n\nUse the button below to start a new one.',
                    ['reply_markup' => ['inline_keyboard' => [
                        [['text' => '💰 New Deposit', 'callback_data' => 'deposit']],
                    ]]]
                );
            } catch (\RuntimeException) {
                // Message may already be edited; ignore
            }
            return;
        }

        $timeout   = (int)($this->config['deposit_timeout_seconds'] ?? 300);
        $createdAt = (int)$pending['created_at'];

        if (time() - $createdAt > $timeout) {
            $this->db->deletePendingDeposit($paymentId);
            try {
                $this->api->editMessageCaption($chatId, $msgId,
                    '❌ <b>Payment not detected within the allowed time.</b>\n\nPlease try again.',
                    ['reply_markup' => ['inline_keyboard' => [
                        [['text' => '🔄 Retry Payment', 'callback_data' => 'deposit']],
                    ]]]
                );
            } catch (\RuntimeException) {
                // Ignore
            }
            return;
        }

        // Call the payment verification API
        $data = $this->verifyPaytmPayment($paymentId);

        if (
            ($data['STATUS']  ?? '') === 'TXN_SUCCESS' &&
            ($data['RESPMSG'] ?? '') === 'Txn Success'
        ) {
            $txnAmount = (float)($data['TXNAMOUNT'] ?? 0);

            // Prevent double-processing
            if ($this->db->getTransactionByPaymentId($paymentId)) {
                $this->db->deletePendingDeposit($paymentId);
                try {
                    $this->api->editMessageCaption($chatId, $msgId,
                        '❌ <b>This payment has already been processed.</b>\n\nPlease start a new deposit.',
                        ['reply_markup' => ['inline_keyboard' => [
                            [['text' => '💰 New Deposit', 'callback_data' => 'deposit']],
                        ]]]
                    );
                } catch (\RuntimeException) {
                    // Ignore
                }
                return;
            }

            // Credit the wallet
            $newBalance = $this->db->incrementUserBalance($userId, $txnAmount);
            $this->db->createTransaction($userId, $paymentId, $txnAmount);
            $this->db->deletePendingDeposit($paymentId);

            $this->notifyAdmins(
                "💰 <b>New Deposit Received</b>\n\n"
                . "User ID: <code>{$userId}</code>\n"
                . "Amount: ₹" . number_format($txnAmount, 2) . "\n"
                . "Payment ID: <code>{$paymentId}</code>\n"
                . "Time: " . date('Y-m-d H:i:s') . ' UTC'
            );

            try {
                $this->api->editMessageCaption($chatId, $msgId,
                    "✅ <b>Payment of ₹" . number_format($txnAmount, 2) . " was successful!</b>\n\n"
                    . "Your new wallet balance: <b>₹" . number_format($newBalance, 2) . "</b>",
                    ['reply_markup' => ['inline_keyboard' => [
                        [['text' => '💰 New Deposit', 'callback_data' => 'deposit']],
                    ]]]
                );
            } catch (\RuntimeException) {
                // Ignore
            }
            return;
        }

        // Payment not confirmed yet – prompt user to try again
        $last4    = substr($paymentId, -4);
        $elapsed  = time() - $createdAt;
        $remaining = max(0, $timeout - $elapsed);
        $minLeft  = (int)ceil($remaining / 60);

        try {
            $this->api->editMessageCaption($chatId, $msgId,
                "⏳ <b>Payment not detected yet.</b>\n\n"
                . "<i>ID: XXXXXXXXXXXXXX{$last4}</i>\n\n"
                . "Please complete the payment and press <b>✅ I've Paid</b> again.\n"
                . "Time remaining: ~{$minLeft} min.",
                ['reply_markup' => ['inline_keyboard' => [
                    [['text' => "✅ I've Paid", 'callback_data' => "check_deposit:{$paymentId}"]],
                    [['text' => '❌ Cancel',    'callback_data' => "cancel_deposit:{$paymentId}"]],
                ]]]
            );
        } catch (\RuntimeException) {
            // Ignore
        }
    }

    /**
     * Cancel a pending deposit.
     */
    private function handleCancelDeposit(int $chatId, string $paymentId, int $msgId): void
    {
        $this->db->deletePendingDeposit($paymentId);

        try {
            $this->api->editMessageCaption($chatId, $msgId,
                '🚫 <b>Payment process has been cancelled.</b>',
                ['reply_markup' => ['inline_keyboard' => [
                    [['text' => '💰 New Deposit', 'callback_data' => 'deposit']],
                ]]]
            );
        } catch (\RuntimeException) {
            // Ignore
        }
    }

    /**
     * Generate a cryptographically random 18-digit numeric string.
     * Uses random_bytes() for compatibility with both 32-bit and 64-bit PHP.
     */
    private function generateRandom18Digit(): string
    {
        // 18 random bytes → one digit per byte to avoid any integer-size dependency.
        // The distribution is slightly biased (256 mod 9/10 ≠ 0) but is more than
        // sufficient for an unpredictable, unique payment tracking ID.
        $bytes  = random_bytes(18);
        $digits = (string)(ord($bytes[0]) % 9 + 1); // first digit: 1–9
        for ($i = 1; $i < 18; $i++) {
            $digits .= (string)(ord($bytes[$i]) % 10);
        }
        return $digits;
    }

    /**
     * Call the Paytm payment verification endpoint and return the decoded JSON.
     * Returns an empty array on any network or parsing error.
     */
    private function verifyPaytmPayment(string $paymentId): array
    {
        $base      = rtrim($this->config['paytm_api_base'] ?? '', '/');
        $separator = str_contains($base, '?') ? '&' : '?';
        $url       = $base . $separator . 'id=' . urlencode($paymentId);

        $ch = curl_init($url);
        if ($ch === false) {
            $this->log('verifyPaytmPayment: failed to init cURL');
            return [];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            $this->log("verifyPaytmPayment cURL error [{$errno}]: {$error}");
            return [];
        }

        $decoded = json_decode((string)$body, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Send a message to every configured admin.
     */
    private function notifyAdmins(string $text): void
    {
        foreach ($this->config['admin_ids'] as $adminId) {
            if ($adminId <= 0) {
                continue;
            }
            try {
                $this->api->sendMessage((int)$adminId, $text);
            } catch (\RuntimeException $e) {
                $this->log("notifyAdmins: failed to send to {$adminId}: " . $e->getMessage());
            }
        }
    }

    // -----------------------------------------------------------------------
    // Admin helpers
    // -----------------------------------------------------------------------

    private function sendRecentOrders(int $chatId, int $userId): void
    {
        if (!$this->isAdmin($userId)) {
            return;
        }

        $orders = $this->db->getRecentOrders(10);
        if (empty($orders)) {
            $this->api->sendMessage($chatId, 'No paid orders yet.');
            return;
        }

        $text = "📋 <b>Last 10 Orders</b>\n\n";
        foreach ($orders as $o) {
            $date     = date('Y-m-d H:i', (int)$o['paid_at']);
            $username = $o['username'] ? '@' . htmlspecialchars($o['username'], ENT_QUOTES) : "ID:{$o['user_id']}";
            $plan     = $this->plansById[$o['plan_id']]['name'] ?? $o['plan_id'];
            $text    .= "• {$username} – {$plan} – {$date}\n";
        }

        $this->api->sendMessage($chatId, $text);
    }

    // -----------------------------------------------------------------------
    // Keyboards
    // -----------------------------------------------------------------------

    private function mainMenuKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '🛒 Buy Membership', 'callback_data' => 'menu_buy'],
                    ['text' => '📋 My Status',      'callback_data' => 'menu_status'],
                ],
                [
                    ['text' => '💰 Deposit', 'callback_data' => 'deposit'],
                    ['text' => '❓ Help',    'callback_data' => 'menu_help'],
                ],
            ],
        ];
    }

    private function adminKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '📊 Stats',         'callback_data' => 'admin_stats'],
                    ['text' => '📋 Recent Orders', 'callback_data' => 'admin_recent'],
                ],
                [
                    ['text' => '📦 List Plans', 'callback_data' => 'admin_plans'],
                ],
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Utilities
    // -----------------------------------------------------------------------

    private function isAdmin(int $userId): bool
    {
        return in_array($userId, $this->config['admin_ids'], true);
    }

    private function log(string $message): void
    {
        if (!($this->config['debug'] ?? false)) {
            return;
        }
        $logPath = $this->config['log_path'] ?? (dirname($this->config['db_path']) . '/bot.log');
        $line    = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
    }
}
