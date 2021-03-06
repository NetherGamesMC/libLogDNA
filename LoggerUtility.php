<?php

declare(strict_types=1);

namespace libLogDNA;

use LogLevel;

/**
 * Logger utility, faster and easy to understand.
 */
class LoggerUtility
{
    public static function emergency(string $message): void
    {
        LogInstance::get()->ingestLog($message, LogLevel::EMERGENCY);
    }

    public static function alert(string $message): void
    {
        LogInstance::get()->ingestLog($message, LogLevel::ALERT);
    }

    public static function critical(string $message): void
    {
        LogInstance::get()->ingestLog($message, LogLevel::CRITICAL);
    }

    public static function error(string $message): void
    {
        LogInstance::get()->ingestLog($message, LogLevel::ERROR);
    }

    public static function warning(string $message): void
    {
        LogInstance::get()->ingestLog($message, LogLevel::WARNING);
    }

    public static function notice(string $message): void
    {
        LogInstance::get()->ingestLog($message, LogLevel::NOTICE);
    }

    public static function info(string $message): void
    {
        LogInstance::get()->ingestLog($message);
    }

    public static function debug(string $message): void
    {
        LogInstance::get()->ingestLog($message, LogLevel::DEBUG);
    }
}