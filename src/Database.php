<?php

declare(strict_types=1);

class Database
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite:' . __DIR__ . '/../../queue.db');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->init();
    }

    private function init(): void
    {
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id TEXT NOT NULL,
                created_at INTEGER NOT NULL
            );
            CREATE TABLE IF NOT EXISTS orders (
                order_id TEXT PRIMARY KEY,
                status TEXT NOT NULL,
                imported_at INTEGER,
                shipped_at INTEGER,
                cancelled_at INTEGER,
                tracking_number TEXT,
                last_checked INTEGER
            );
        SQL);
    }

    public function enqueueOrder(string $orderId): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO queue (order_id, created_at) VALUES (?, ?)');
        $stmt->execute([$orderId, time()]);
    }

    public function dequeueOrder(): ?string
    {
        $this->pdo->beginTransaction();
        $stmt = $this->pdo->query('SELECT order_id FROM queue ORDER BY created_at ASC LIMIT 1');
        $orderId = $stmt->fetchColumn();

        if ($orderId) {
            $this->pdo->prepare('DELETE FROM queue WHERE order_id = ?')->execute([$orderId]);
            $this->pdo->commit();
            return $orderId;
        }

        $this->pdo->commit();
        return null;
    }

    public function saveOrder(string $orderId, string $status, ?int $importedAt = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR REPLACE INTO orders (order_id, status, imported_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$orderId, $status, $importedAt]);
    }

    public function updateOrderStatus(string $orderId, string $status, ?string $trackingNumber = null): void
    {
        $fields = ['status' => $status, 'last_checked' => time()];
        if ($status === 'shipped' && $trackingNumber) {
            $fields['shipped_at'] = time();
            $fields['tracking_number'] = $trackingNumber;
        } elseif ($status === 'cancelled') {
            $fields['cancelled_at'] = time();
        }

        $sql = 'UPDATE orders SET ' . implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields))) . ' WHERE order_id = :order_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge($fields, ['order_id' => $orderId]));
    }

    public function getOpenOrders(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM orders WHERE status NOT IN ("shipped", "cancelled")');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deleteOrder(string $orderId): void
    {
        $this->pdo->prepare('DELETE FROM orders WHERE order_id = ?')->execute([$orderId]);
    }

    public function orderExists(string $orderId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM orders WHERE order_id = ?');
        $stmt->execute([$orderId]);
        return (bool) $stmt->fetchColumn();
    }
}