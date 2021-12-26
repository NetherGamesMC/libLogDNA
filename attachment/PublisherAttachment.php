<?php
declare(strict_types=1);

namespace libLogDNA\attachment;

use libLogDNA\LogInstance;
use libLogDNA\LogThread;
use pocketmine\utils\TextFormat;
use ThreadedLoggerAttachment;

class PublisherAttachment extends ThreadedLoggerAttachment
{
    /** @var LogThread */
    public LogThread $logger;

    public function __construct()
    {
        $this->logger = LogInstance::get();
    }

    /**
     * @param mixed $level
     * @param string $message
     */
    public function log($level, $message): void
    {
        $this->logger->ingestLog(preg_filter('/\[(.*?)] \[(.*?)]: /', '', TextFormat::clean($message)), $level);
    }
}