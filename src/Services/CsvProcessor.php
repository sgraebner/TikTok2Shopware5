<?php

namespace App\Services;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class CsvProcessor
{
    private ShopwareClient $shopwareClient;
    private Logger $logger;

    public function __construct()
    {
        $this->shopwareClient = new ShopwareClient();
        $this->logger = new Logger('csv_processor');
        $this->logger->pushHandler(new StreamHandler(__DIR__ . '/../Logs/app.log', Logger::INFO));
    }

    public function process(string $filePath): void
    {
        $this->logger->info("Processing CSV: $filePath");

        $orders = $this->parseCsv($filePath);
        foreach ($orders as $orderId => $items) {
            $this->createShopwareOrder($orderId, $items);
        }

        unlink($filePath); // Remove processed file
        $this->logger->info("Finished processing CSV: $filePath");
    }

    private function parseCsv(string $filePath): array
    {
        $orders = [];
        if (($handle = fopen($filePath, 'r')) !== false) {
            $headers = fgetcsv($handle, 0, ',');
            while (($row = fgetcsv($handle, 0, ',')) !== false) {
                $data = array_combine($headers, $row);
                $orderId = $data['Order ID'];
                $orders[$orderId][] = $data;
            }
            fclose($handle);
        }
        return $orders;
    }

    private function createShopwareOrder(string $orderId, array $items): void
    {
        $paymentId = $this->shopwareClient->getPaymentMethodId('TikTok');
        if (!$paymentId) {
            $this->logger->error("Payment method 'TikTok' not found");
            return;
        }

        $firstItem = $items[0];
        [$firstName, $lastName] = $this->splitName($firstItem['Recipient']);

        // Process street address and house number
        $streetAddress = $this->processStreetAddress($firstItem['Street Name'], $firstItem['House Name or Number']);

        $orderDetails = [];
        $total = 0;
        foreach ($items as $item) {
            $article = $this->shopwareClient->getArticleByNumber($item['Seller SKU']);
            if (!$article) {
                $this->logger->error("Article not found for SKU: " . $item['Seller SKU']);
                continue;
            }

            $price = (float)str_replace([' EUR', ','], ['', '.'], $item['SKU Subtotal After Discount']);
            $quantity = (int)$item['Quantity'];
            $total += $price * $quantity;

            $orderDetails[] = [
                'articleId' => $article['id'],
                'articleNumber' => $article['number'],
                'name' => $item['Product Name'],
                'quantity' => $quantity,
                'price' => $price / $quantity,
                'taxId' => $article['mainDetail']['taxId'],
            ];
        }

        $shippingFee = (float)str_replace([' EUR', ','], ['', '.'], $firstItem['Original Shipping Fee']);
        $shippingDiscount = (float)str_replace([' EUR', ','], ['', '.'], $firstItem['Shipping Fee Platform Discount']);
        if ($shippingFee > 0) {
            $total += $shippingFee;
            $orderDetails[] = [
                'articleId' => null,
                'articleNumber' => 'SHIPPING',
                'name' => 'Shipping Cost',
                'quantity' => 1,
                'price' => $shippingFee,
                'taxId' => 1, // Default tax ID, adjust as needed
            ];
        }
        if ($shippingDiscount > 0) {
            $total -= $shippingDiscount;
            $orderDetails[] = [
                'articleId' => null,
                'articleNumber' => 'SHIPPING_DISCOUNT',
                'name' => 'Shipping Discount',
                'quantity' => 1,
                'price' => -$shippingDiscount,
                'taxId' => 1,
            ];
        }

        $orderData = [
            'number' => $orderId,
            'customer' => ['email' => $firstItem['Email']],
            'billing' => [
                'firstName' => $firstName,
                'lastName' => $lastName,
                'street' => $streetAddress,
                'zipCode' => $firstItem['Zipcode'],
                'city' => $firstItem['City'],
                'countryId' => 2, // Germany, adjust as needed
            ],
            'shipping' => [
                'firstName' => $firstName,
                'lastName' => $lastName,
                'street' => $streetAddress,
                'zipCode' => $firstItem['Zipcode'],
                'city' => $firstItem['City'],
                'countryId' => 2,
            ],
            'payment' => ['id' => $paymentId],
            'orderStatus' => 1, // Ready to be shipped
            'paymentStatus' => 12, // Paid
            'details' => $orderDetails,
            'invoiceAmount' => $total,
            'attribute' => ['attribute1' => $orderId], // tiktok_order_id
        ];

        $this->shopwareClient->createOrder($orderData);
    }

    private function splitName(string $fullName): array
    {
        $parts = explode(' ', trim($fullName));
        $lastName = array_pop($parts);
        $firstName = implode(' ', $parts);
        return [$firstName ?: 'Unknown', $lastName ?: 'Unknown'];
    }

    private function processStreetAddress(string $streetName, string $houseNameOrNumber): string
    {
        // Step 1: Check if the street name contains a house number in parentheses (e.g., "Siechenhaustraße(11)")
        if (preg_match('/^(.*?)\((\d+[a-zA-Z]?)\)$/', $streetName, $matches)) {
            $cleanStreet = trim($matches[1]); // "Siechenhaustraße"
            $houseNumber = $matches[2]; // "11"
        } else {
            // Step 2: If no parentheses, check if the street name already contains a number at the end (e.g., "Westerwaldstraße 139")
            $parts = explode(' ', trim($streetName));
            $lastPart = end($parts);
            if (preg_match('/^\d+[a-zA-Z]?$/', $lastPart)) {
                $houseNumber = $lastPart;
                $cleanStreet = implode(' ', array_slice($parts, 0, -1));
            } else {
                // Step 3: Use the street name as-is and look for the house number in the "House Name or Number" field
                $cleanStreet = trim($streetName);
                $houseNumber = null;

                // Step 4: Check "House Name or Number" for a valid house number
                $houseParts = trim($houseNameOrNumber);
                if (preg_match('/^\d+[a-zA-Z]?$/', $houseParts)) {
                    $houseNumber = $houseParts;
                } elseif ($houseParts && !preg_match('/^(c\/o|wohnung)/i', $houseParts)) {
                    // If "House Name or Number" contains data but isn't a number or a "c/o" or "wohnung", log a warning
                    $this->logger->warning("Unrecognized house number format: $houseParts");
                }
            }
        }

        // Step 5: Combine the street and house number (if found)
        return $houseNumber ? "$cleanStreet $houseNumber" : $cleanStreet;
    }
}
