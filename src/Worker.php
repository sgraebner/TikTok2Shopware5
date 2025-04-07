<?php

declare(strict_types=1);

class Worker
{
    private Database $db;
    private ApiClient $api;
    private Logger $logger;

    public function __construct(Database $db, ApiClient $api, Logger $logger)
    {
        $this->db = $db;
        $this->api = $api;
        $this->logger = $logger;
    }

    public function run(): void
    {
        while (($orderId = $this->db->dequeueOrder()) !== null) {
            try {
                $this->processOrder($orderId);
                sleep(1); // Throttle to respect API rate limits
            } catch (Exception $e) {
                $this->logger->log("Error processing order $orderId: " . $e->getMessage());
            }
        }
    }

    private function processOrder(string $orderId): void
    {
        $this->logger->log("Processing order $orderId");

        $tiktokOrder = $this->api->fetchTikTokOrder($orderId);
        if (empty($tiktokOrder['data']['orders'])) {
            $this->logger->log("Order $orderId not found on TikTok");
            return;
        }

        $orderData = $tiktokOrder['data']['orders'][0];
        $shopwareOrder = [
            'customer' => ['email' => $orderData['buyer_email'] ?? 'unknown@tiktok.com'],
            'billing' => [['email' => $orderData['buyer_email'] ?? 'unknown@tiktok.com']],
            'shipping' => [['street' => $orderData['shipping_address']['address_line1'] ?? 'Unknown']],
            'orderDetails' => array_map(fn($item) => [
                'articleNumber' => $item['sku_id'], // Assuming TikTok SKU maps to Shopware product number
                'quantity' => $item['quantity'],
                'price' => $item['price']['amount'],
            ], $orderData['order_items']),
            'comment' => "TikTok Order: $orderId",
            'orderStatus' => 0, // Open
            'paymentStatus' => 17, // Paid
        ];

        $response = $this->api->createShopwareOrder($shopwareOrder);
        $this->db->saveOrder($orderId, 'open', time());
        $this->logger->log("Order $orderId imported to Shopware as {$response['id']}");
    }
}