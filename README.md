# Membership Bot

A PHP Telegram bot for selling channel memberships using Telegram's native Payments API.

## Features

| Feature | Details |
|---------|---------|
| Membership plans | Configurable monthly / quarterly / yearly (or any custom) plans |
| Native Telegram Payments | Full pre-checkout validation + automatic invite-link delivery |
| Single-use invite links | Generated per successful payment, expire after 72 h |
| Membership extensions | Purchasing while active extends from the current expiry |
| `/status` command | Users can check their expiry date at any time |
| Admin panel | `/admin` — live stats, recent orders, plan list |
| SQLite storage | Zero-dependency persistence via PDO + SQLite 3 |
| Debug logging | Optional file logging to `data/bot.log` |

---

## Requirements

- PHP 8.1 or higher
- Extensions: `curl`, `json`, `pdo_sqlite` (all standard)
- A publicly accessible HTTPS server (required by Telegram)
- A Telegram bot token from [@BotFather](https://t.me/BotFather)
- A Telegram payment provider token (Stripe, etc.) — optional for manual payments

---

## Quick Start

### 1. Clone / upload the files

```bash
git clone https://github.com/Uday-x-ai/membership-bot.git
cd membership-bot
```

### 2. Configure

Edit `config.php` (or set environment variables):

| Key | Description |
|-----|-------------|
| `bot_token` | Token from @BotFather |
| `payment_provider_token` | From @BotFather after connecting a payment provider (leave empty to disable) |
| `channel_id` | Numeric channel ID (`-1001234567890`) or public username (`@mychannel`) |
| `admin_ids` | Comma-separated Telegram user IDs that may use `/admin` |
| `webhook_url` | Full HTTPS URL to `index.php` on your server |
| `db_path` | Path to the SQLite database file (auto-created) |
| `debug` | `true` to enable file logging |

> **Tip:** Use environment variables in production instead of editing `config.php` directly.

### 3. Set up the database & webhook

```bash
# Initialise the database
php setup.php --init-db

# Register the webhook with Telegram
php setup.php --register-webhook

# Check current webhook status
php setup.php --info
```

### 4. Make the bot an admin in your channel

The bot must be a channel **administrator** with the *Invite Users via Link* permission so it can generate single-use invite links.

---

## Environment Variables

All configuration keys can be overridden via environment variables, which is the recommended approach for production:

```
BOT_TOKEN=123456:ABC-DEF...
PAYMENT_PROVIDER_TOKEN=284685063:TEST:...
CHANNEL_ID=-1001234567890
ADMIN_IDS=111222333,444555666
WEBHOOK_URL=https://example.com/index.php
DB_PATH=/var/data/membership.db
BOT_DEBUG=false
```

---

## Bot Commands

| Command | Description |
|---------|-------------|
| `/start` | Show the welcome message and main menu |
| `/buy` | Display available membership plans |
| `/status` | Show your current membership status |
| `/help` | List available commands |
| `/admin` | Open the admin panel (admins only) |
| `/stats` | Alias for `/admin` |
| `/plans` | List configured plans (admins only) |

---

## Payment Flow

```
User presses "Buy" → selects a plan
  → Telegram invoice is sent
  → User confirms payment
  → Bot receives pre_checkout_query → validates order → approves
  → Telegram charges user
  → Bot receives successful_payment
     → Order marked paid in DB
     → Single-use invite link generated
     → Link sent to user
     → Membership row created (or extended)
```

---

## Project Structure

```
membership-bot/
├── config.php          ← Configuration (tokens, plans, etc.)
├── index.php           ← Webhook entry point
├── setup.php           ← One-time setup helper (DB + webhook)
├── src/
│   ├── TelegramAPI.php ← Telegram Bot API HTTP wrapper
│   ├── Database.php    ← SQLite data-access layer
│   └── MembershipBot.php ← All bot logic (commands, payments)
├── data/               ← Created automatically
│   ├── membership.db   ← SQLite database
│   └── bot.log         ← Debug log (when debug=true)
└── README.md
```

---

## Security Notes

- **Never commit `config.php` with real tokens** — use environment variables.
- **Delete or restrict `setup.php`** after initial setup.
- The `data/` directory should not be web-accessible; add a `.htaccess` deny rule or move it outside the web root.
- Validate the `BOT_TOKEN` secret on your server to ensure requests originate from Telegram (optional but recommended for high-traffic bots).

---

## Extending the Bot

### Add a new plan

Add an entry to the `plans` array in `config.php`:

```php
[
    'id'            => 'lifetime',
    'name'          => 'Lifetime Membership',
    'description'   => 'One-time purchase, access forever.',
    'duration_days' => 36500, // 100 years
    'price'         => 19999, // $199.99
    'currency'      => 'USD',
],
```

No database migration is needed — plans are read from config at runtime.

### External payment gateway

If `payment_provider_token` is empty, `MembershipBot::sendManualPaymentInfo()` is called instead of sending a Telegram invoice. Replace that method's body with your gateway's redirect URL or payment instructions.

To complete the flow, call `Database::markOrderPaid()` and `Database::grantMembership()` from your payment callback handler.
