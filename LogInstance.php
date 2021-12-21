<?php

declare(strict_types=1);

namespace libLogDNA;

use RuntimeException;

class LogInstance
{
    private static ?LogThread $logger = null;

    public static function get(): LogThread
    {
        if (self::$logger === null) {
            throw new RuntimeException("LogDNA logging utility is not initialized.");
        }

        return self::$logger;
    }

    public static function set(LogThread $logger): void
    {
        self::$logger = $logger;
    }
}