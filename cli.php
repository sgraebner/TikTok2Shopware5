<?php

declare(strict_types=1);

require __DIR__ . '/config/env.php';
require __DIR__ . '/src/Database.php';
require __DIR__ . '/src/Logger.php';
require __DIR__ . '/src/ApiClient.php';
require __DIR__ . '/src/Worker.php';
require __DIR__ . '/src/Monitor.php';

$db = new Database();
$logger = new Logger('app.log');
$api = new ApiClient($logger);

$command = $argv[1] ?? 'help';

switch ($command) {
    case 'worker':
        $worker = new Worker($db, $api, $logger);
        $worker->run();
        break;
    case 'monitor':
        $monitor = new Monitor($db, $api, $logger);
        $monitor->run();
        break;
    case 'help':
    default:
        echo "Usage: php cli.php [worker|monitor]\n";
        break;
}