<?php
declare(strict_types=1);

namespace libLogDNA;

class LogEntry
{
    public int $timestamp;

    public function __construct(
        public string $level,
        public string $app,
        public string $line
    )
    {
        $this->timestamp = time();
    }

    public function serialize(string $env): array
    {
        return [
            "timestamp" => $this->timestamp,
            "level" => $this->level,
            "app" => $this->app,
            "line" => $this->line,
            "env" => $env
        ];
    }
}