<?php

declare(strict_types=1);

namespace MembershipBot;

/**
 * SQLite database layer.
 *
 * Schema:
 *   orders      – one row per payment attempt
 *   memberships – one row per active/expired membership period
 */
class Database
{
    private \PDO $pdo;

    public function __construct(string $dbPath)
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create database directory: {$dir}");
        }

        $this->pdo = new \PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA foreign_keys=ON');

        $this->createSchema();
    }

    // -----------------------------------------------------------------------
    // Schema
    // -----------------------------------------------------------------------

    private function createSchema(): void
    {
        $this->pdo->exec(
            <<<SQL
            CREATE TABLE IF NOT EXISTS orders (
                id                 INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id            INTEGER NOT NULL,
                username           TEXT,
                plan_id            TEXT    NOT NULL,
                status             TEXT    NOT NULL DEFAULT 'pending',
                -- 'pending' | 'paid' | 'cancelled'
                telegram_charge_id TEXT,
                created_at         INTEGER NOT NULL DEFAULT (strftime('%s','now')),
                paid_at            INTEGER
            );

            CREATE INDEX IF NOT EXISTS idx_orders_user   ON orders (user_id);
            CREATE INDEX IF NOT EXISTS idx_orders_status ON orders (status);

            CREATE TABLE IF NOT EXISTS memberships (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    INTEGER NOT NULL,
                plan_id    TEXT    NOT NULL,
                order_id   INTEGER NOT NULL REFERENCES orders(id),
                started_at INTEGER NOT NULL DEFAULT (strftime('%s','now')),
                expires_at INTEGER NOT NULL,
                invite_link TEXT
            );

            CREATE INDEX IF NOT EXISTS idx_memberships_user    ON memberships (user_id);
            CREATE INDEX IF NOT EXISTS idx_memberships_expires ON memberships (expires_at);
            SQL
        );
    }

    // -----------------------------------------------------------------------
    // Orders
    // -----------------------------------------------------------------------

    /**
     * Create a new pending order and return its ID.
     */
    public function createOrder(int $userId, ?string $username, string $planId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO orders (user_id, username, plan_id) VALUES (:uid, :uname, :plan)'
        );
        $stmt->execute([':uid' => $userId, ':uname' => $username, ':plan' => $planId]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Mark an order as paid and store the Telegram charge ID.
     */
    public function markOrderPaid(int $orderId, string $chargeId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE orders
                SET status = 'paid', telegram_charge_id = :charge, paid_at = strftime('%s','now')
              WHERE id = :id"
        );
        $stmt->execute([':charge' => $chargeId, ':id' => $orderId]);
    }

    /**
     * Retrieve a single order by its ID.
     */
    public function getOrder(int $orderId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE id = :id');
        $stmt->execute([':id' => $orderId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Return the most recent pending order for a user + plan combination.
     */
    public function getPendingOrder(int $userId, string $planId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM orders
              WHERE user_id = :uid AND plan_id = :plan AND status = 'pending'
              ORDER BY created_at DESC
              LIMIT 1"
        );
        $stmt->execute([':uid' => $userId, ':plan' => $planId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // -----------------------------------------------------------------------
    // Memberships
    // -----------------------------------------------------------------------

    /**
     * Grant membership to a user.
     * If the user already has an active membership for the same plan,
     * extend it from the current expiry date.
     *
     * @return array The newly created membership row
     */
    public function grantMembership(
        int    $userId,
        string $planId,
        int    $orderId,
        int    $durationDays,
        string $inviteLink = ''
    ): array {
        $now    = time();
        $active = $this->getActiveMembership($userId);

        // Extend from current expiry if still active; otherwise start from now
        $base = ($active && $active['expires_at'] > $now) ? (int)$active['expires_at'] : $now;
        $expiresAt = $base + ($durationDays * 86400);

        $stmt = $this->pdo->prepare(
            'INSERT INTO memberships (user_id, plan_id, order_id, started_at, expires_at, invite_link)
             VALUES (:uid, :plan, :oid, :start, :exp, :link)'
        );
        $stmt->execute([
            ':uid'   => $userId,
            ':plan'  => $planId,
            ':oid'   => $orderId,
            ':start' => $now,
            ':exp'   => $expiresAt,
            ':link'  => $inviteLink,
        ]);

        return $this->getMembership((int)$this->pdo->lastInsertId());
    }

    /**
     * Return the most-recently-started active membership for a user, or null.
     */
    public function getActiveMembership(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM memberships
              WHERE user_id = :uid AND expires_at > strftime('%s','now')
              ORDER BY expires_at DESC
              LIMIT 1"
        );
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Retrieve a membership row by its primary key.
     */
    public function getMembership(int $membershipId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM memberships WHERE id = :id');
        $stmt->execute([':id' => $membershipId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \RuntimeException("Membership {$membershipId} not found.");
        }
        return $row;
    }

    // -----------------------------------------------------------------------
    // Admin / stats
    // -----------------------------------------------------------------------

    /**
     * Return aggregate statistics for the admin panel.
     */
    public function getStats(): array
    {
        $now = time();

        $totalPaid = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM orders WHERE status = 'paid'"
        )->fetchColumn();

        $activeMembers = (int)$this->pdo->query(
            "SELECT COUNT(DISTINCT user_id) FROM memberships WHERE expires_at > {$now}"
        )->fetchColumn();

        $todayStart = mktime(0, 0, 0);
        $todayPaid  = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM orders WHERE status = 'paid' AND paid_at >= {$todayStart}"
        )->fetchColumn();

        $expiringSoon = (int)$this->pdo->query(
            "SELECT COUNT(DISTINCT user_id) FROM memberships
              WHERE expires_at > {$now} AND expires_at <= " . ($now + 3 * 86400)
        )->fetchColumn();

        return [
            'total_paid'      => $totalPaid,
            'active_members'  => $activeMembers,
            'today_paid'      => $todayPaid,
            'expiring_soon'   => $expiringSoon,
        ];
    }

    /**
     * List recent paid orders with user info.
     */
    public function getRecentOrders(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM orders WHERE status = 'paid'
              ORDER BY paid_at DESC
              LIMIT :lim"
        );
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Return all memberships expiring within $withinSeconds.
     */
    public function getExpiringSoon(int $withinSeconds = 86400): array
    {
        $now    = time();
        $cutoff = $now + $withinSeconds;
        $stmt   = $this->pdo->prepare(
            'SELECT * FROM memberships WHERE expires_at > :now AND expires_at <= :cutoff'
        );
        $stmt->execute([':now' => $now, ':cutoff' => $cutoff]);
        return $stmt->fetchAll();
    }
}
