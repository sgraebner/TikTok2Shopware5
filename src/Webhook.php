<?php

declare(strict_types=1);

class Webhook
{
    private Database $db;
    private Logger $logger;

    public function __construct(Database $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    public function handle(): void
    {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!isset($data['order_id'])) {
            http_response_code(400);
            $this->logger->log('Invalid webhook payload: ' . $input);
            exit('Invalid payload');
        }

        $orderId = $data['order_id'];
        if ($this->db->orderExists($orderId)) {
            $this->logger->log("Order $orderId already processed. Skipping.");
            exit('Order already processed');
        }

        $this->db->enqueueOrder($orderId);
        $this->logger->log("Order $orderId enqueued.");
        exit('OK');
    }
}