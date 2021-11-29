<?php
declare(strict_types=1);

namespace libLogDNA\attachment;

use libLogDNA\LogProxy;

class PublisherAttachment extends \ThreadedLoggerAttachment
{

    public function __construct(public LogProxy $logProxy)
    {

    }

    public function log($level, $message)
    {
        $this->logProxy->logMessage($message, $level);
    }
}