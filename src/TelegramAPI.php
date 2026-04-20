<?php

declare(strict_types=1);

namespace MembershipBot;

/**
 * Minimal Telegram Bot API wrapper.
 *
 * Sends requests to the Telegram Bot API using PHP's built-in cURL extension.
 * Every method returns the decoded JSON response array on success,
 * or throws a \RuntimeException on HTTP / API failure.
 */
class TelegramAPI
{
    private const API_BASE = 'https://api.telegram.org/bot';
    private const TIMEOUT  = 10;

    private string $token;

    public function __construct(string $token)
    {
        if ($token === '' || $token === 'YOUR_BOT_TOKEN_HERE') {
            throw new \InvalidArgumentException(
                'A valid Telegram bot token is required (not empty or a placeholder value).'
            );
        }
        $this->token = $token;
    }

    // -----------------------------------------------------------------------
    // Webhook
    // -----------------------------------------------------------------------

    public function setWebhook(string $url): array
    {
        return $this->call('setWebhook', ['url' => $url]);
    }

    public function deleteWebhook(): array
    {
        return $this->call('deleteWebhook');
    }

    public function getWebhookInfo(): array
    {
        return $this->call('getWebhookInfo');
    }

    // -----------------------------------------------------------------------
    // Messages
    // -----------------------------------------------------------------------

    public function sendMessage(
        int|string $chatId,
        string $text,
        array $extra = []
    ): array {
        return $this->call('sendMessage', array_merge([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ], $extra));
    }

    public function editMessageText(
        int|string $chatId,
        int $messageId,
        string $text,
        array $extra = []
    ): array {
        return $this->call('editMessageText', array_merge([
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ], $extra));
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): array
    {
        return $this->call('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text'              => $text,
            'show_alert'        => $showAlert,
        ]);
    }

    // -----------------------------------------------------------------------
    // Payments
    // -----------------------------------------------------------------------

    public function sendInvoice(
        int|string $chatId,
        string $title,
        string $description,
        string $payload,
        string $providerToken,
        string $currency,
        array  $prices,
        array  $extra = []
    ): array {
        return $this->call('sendInvoice', array_merge([
            'chat_id'        => $chatId,
            'title'          => $title,
            'description'    => $description,
            'payload'        => $payload,
            'provider_token' => $providerToken,
            'currency'       => $currency,
            'prices'         => $prices,
        ], $extra));
    }

    public function answerPreCheckoutQuery(string $preCheckoutQueryId, bool $ok, string $errorMessage = ''): array
    {
        $params = [
            'pre_checkout_query_id' => $preCheckoutQueryId,
            'ok'                    => $ok,
        ];
        if (!$ok && $errorMessage !== '') {
            $params['error_message'] = $errorMessage;
        }
        return $this->call('answerPreCheckoutQuery', $params);
    }

    // -----------------------------------------------------------------------
    // Chat / Channel management
    // -----------------------------------------------------------------------

    public function getChatMember(int|string $chatId, int $userId): array
    {
        return $this->call('getChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    public function banChatMember(int|string $chatId, int $userId): array
    {
        return $this->call('banChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    public function unbanChatMember(int|string $chatId, int $userId, bool $onlyIfBanned = true): array
    {
        return $this->call('unbanChatMember', [
            'chat_id'        => $chatId,
            'user_id'        => $userId,
            'only_if_banned' => $onlyIfBanned,
        ]);
    }

    /**
     * Create a single-use invite link for the channel.
     * The link expires after one join (member_limit = 1).
     */
    public function createChatInviteLink(
        int|string $chatId,
        int $memberLimit = 1,
        ?int $expireDate = null
    ): array {
        $params = [
            'chat_id'      => $chatId,
            'member_limit' => $memberLimit,
        ];
        if ($expireDate !== null) {
            $params['expire_date'] = $expireDate;
        }
        return $this->call('createChatInviteLink', $params);
    }

    // -----------------------------------------------------------------------
    // Core HTTP caller
    // -----------------------------------------------------------------------

    /**
     * Make a Bot API call.
     *
     * @param string $method Telegram method name
     * @param array  $params Request parameters
     * @return array Decoded 'result' field from the API response
     * @throws \RuntimeException on curl or API error
     */
    public function call(string $method, array $params = []): array
    {
        $url = self::API_BASE . $this->token . '/' . $method;

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialise cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if (!empty($params)) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }

        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException("cURL error [{$errno}]: {$error}");
        }

        $response = json_decode((string)$body, true);
        if (!is_array($response)) {
            throw new \RuntimeException('Invalid JSON response from Telegram API.');
        }

        if (!($response['ok'] ?? false)) {
            $desc = $response['description'] ?? 'Unknown error';
            $code = $response['error_code']  ?? 0;
            throw new \RuntimeException("Telegram API error [{$code}]: {$desc}");
        }

        return $response['result'] ?? [];
    }
}
