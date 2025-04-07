<?php

declare(strict_types=1);

require __DIR__ . '/../config/env.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Logger.php';
require __DIR__ . '/../src/Webhook.php';

$db = new Database();
$logger = new Logger('webhook.log');
$webhook = new Webhook($db, $logger);

$webhook->handle();