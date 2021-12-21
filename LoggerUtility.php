<?php

declare(strict_types=1);

namespace libLogDNA;

use LogLevel;

/**
 * Logger utility, faster and easy to understand.
 */
class LoggerUtility
{
    public static function emergency($message): void
    {
        LogInstance::get()->ingestLog($message, LogLevel::EMERGENCY);
    }

    public static function alert($message): void
    {
        LogInstance::get()->ingestLog($message, LogLevel::ALERT);
    }

    public static function critical($message): void
    {
        LogInstance::get()->ingestLog($message, LogLevel::CRITICAL);
    }

    public static function error($message): void
    {
        LogInstance::get()->ingestLog($message, LogLevel::ERROR);
    }

    public static function warning($message): void
    {
        LogInstance::get()->ingestLog($message, LogLevel::WARNING);
    }

    public static function notice($message): void
    {
        LogInstance::get()->ingestLog($message, LogLevel::NOTICE);
    }

    public static function info($message): void
    {
        LogInstance::get()->ingestLog($message);
    }

    public static function debug($message): void
    {
        LogInstance::get()->ingestLog($message, LogLevel::DEBUG);
    }
}