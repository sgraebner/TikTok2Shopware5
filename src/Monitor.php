<?php

declare(strict_types=1);

class Monitor
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
        $orders = $this->db->getOpenOrders();
        foreach ($orders as $order) {
            $this->checkOrder($order);
            sleep(1); // Throttle API calls
        }
    }

    private function checkOrder(array $order): void
    {
        $orderId = $order['order_id'];
        $this->logger->log("Checking order $orderId");

        $shopwareOrder = $this->api->getShopwareOrder($orderId);
        $status = $shopwareOrder['data']['orderStatus']['id'];
        $trackingNumber = $shopwareOrder['data']['trackingCode'] ?? null;

        if ($status == 4) { // Shipped in Shopware 5
            if ($trackingNumber) {
                $this->api->notifyTikTokShipment($orderId, $trackingNumber);
                $this->db->updateOrderStatus($orderId, 'shipped', $trackingNumber);
                $this->db->deleteOrder($orderId);
                $this->logger->log("Order $orderId shipped with tracking $trackingNumber");
            }
        } elseif ($status == 7) { // Cancelled in Shopware 5
            $this->db->updateOrderStatus($orderId, 'cancelled');
            $this->db->deleteOrder($orderId);
            $this->logger->log("Order $orderId cancelled");
        } elseif (time() - $order['imported_at'] > 90 * 24 * 3600) {
            $this->sendExpirationEmail($orderId);
            $this->logger->log("Order $orderId unresolved for 90+ days. Email sent.");
        }
    }

    private function sendExpirationEmail(string $orderId): void
    {
        $to = 'it@kraeuterland.de';
        $subject = "Unresolved TikTok Order: $orderId";
        $message = "Order $orderId has been unresolved for over 90 days.";
        $headers = 'From: ' . getenv('MAIL_SENDER_ID');
        mail($to, $subject, $message, $headers);
    }
}