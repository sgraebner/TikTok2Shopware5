<?php

declare(strict_types=1);

class ApiClient
{
    private string $tiktokBaseUrl = 'https://open-api.tiktokglobalshop.com';
    private string $shopwareBaseUrl;
    private string $tiktokAppKey;
    private string $tiktokAppSecret;
    private string $tiktokAccessToken;
    private string $shopwareApiKey;
    private string $shopwareApiSecret;
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->shopwareBaseUrl = getenv('SHOPWARE_BASE_URL');
        $this->tiktokAppKey = getenv('TIKTOK_APP_KEY');
        $this->tiktokAppSecret = getenv('TIKTOK_APP_SECRET');
        $this->tiktokAccessToken = getenv('TIKTOK_ACCESS_TOKEN');
        $this->shopwareApiKey = getenv('SHOPWARE_API_KEY');
        $this->shopwareApiSecret = getenv('SHOPWARE_API_SECRET');
        $this->logger = $logger;
    }

    public function fetchTikTokOrder(string $orderId): array
    {
        return $this->request(
            'GET',
            "{$this->tiktokBaseUrl}/order/202309/orders?order_id={$orderId}",
            ['Authorization' => "Bearer {$this->tiktokAccessToken}"]
        );
    }

    public function notifyTikTokShipment(string $orderId, string $trackingNumber): void
    {
        $this->request(
            'POST',
            "{$this->tiktokBaseUrl}/logistics/202309/shipments",
            ['Authorization' => "Bearer {$this->tiktokAccessToken}"],
            ['order_id' => $orderId, 'tracking_number' => $trackingNumber]
        );
    }

    public function createShopwareOrder(array $orderData): array
    {
        return $this->request(
            'POST',
            "{$this->shopwareBaseUrl}/api/orders",
            ['Authorization' => 'Basic ' . base64_encode("{$this->shopwareApiKey}:{$this->shopwareApiSecret}")],
            $orderData
        );
    }

    public function getShopwareOrder(string $orderId): array
    {
        return $this->request(
            'GET',
            "{$this->shopwareBaseUrl}/api/orders/{$orderId}",
            ['Authorization' => 'Basic ' . base64_encode("{$this->shopwareApiKey}:{$this->shopwareApiSecret}")]
        );
    }

    private function request(string $method, string $url, array $headers, array $data = []): array
    {
        $attempts = 0;
        $maxAttempts = 3;
        $timeout = 10;

        while ($attempts < $maxAttempts) {
            $attempts++;
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers));

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(
                    array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers),
                    ['Content-Type: application/json']
                ));
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                $this->logger->log("Request failed: $error. Attempt $attempts/$maxAttempts");
                if ($attempts === $maxAttempts) {
                    throw new RuntimeException("API request failed after $maxAttempts attempts: $error");
                }
                sleep(2 ** $attempts); // Exponential backoff
                continue;
            }

            if ($httpCode >= 429) { // Rate limit
                $this->logger->log("Rate limit hit for $url. Sleeping for 60s.");
                sleep(60);
                continue;
            }

            if ($httpCode >= 400) {
                $this->logger->log("API error ($httpCode): " . $response);
                throw new RuntimeException("API request failed with status $httpCode: $response");
            }

            return json_decode($response, true);
        }

        throw new RuntimeException('Unreachable code');
    }
}