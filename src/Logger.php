<?php

declare(strict_types=1);

class Logger
{
    private string $file;

    public function __construct(string $file)
    {
        $this->file = __DIR__ . '/../../' . $file;
    }

    public function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($this->file, "[$timestamp] $message\n", FILE_APPEND);
    }
}