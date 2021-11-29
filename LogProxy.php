<?php
declare(strict_types=1);

namespace libLogDNA;

use AttachableThreadedLogger;
use libLogDNA\scheduler\PublisherTask;
use pocketmine\scheduler\TaskScheduler;
use pocketmine\utils\TextFormat;
use Throwable;

class LogProxy
{
    public const PUBLISHING_DELAY = 5; // every 5 seconds

    /** @var array<LogEntry> */
    private array $messageStack = [];
    private bool $shutdown = false;
    private string $tagsEncoded;

    public function __construct(
        private string $accessToken,
        array $tags,
        private string $hostname,
        private string $appName,
        private AttachableThreadedLogger $logger,
        TaskScheduler $scheduler,
    )
    {
        $this->tagsEncoded = implode(",", $tags);
        $scheduler->scheduleRepeatingTask(new PublisherTask($this), self::PUBLISHING_DELAY * 20);
    }

    public function logMessage(string $message, string $logLevel): void
    {
        $entry = new LogEntry($logLevel, $this->appName, TextFormat::clean($message));
        $this->messageStack[] = $entry;
    }

    public function logException(Throwable $throwable): void
    {
        $message = "Error in " . $throwable->getFile() . " (Line " . $throwable->getLine() . "): " . $throwable->getMessage();
        $message .= "\n" . $throwable->getTraceAsString();

        $this->logMessage($message, "ERROR");
    }

    public function generateJSON(): string
    {
        $lines = [];

        foreach ($this->messageStack as $message) {
            $lines[] = $message->serialize("production"); // todo make this modular
        }

        $result = [
            "lines" => $lines
        ];

        return json_encode($result);
    }

    public function shutdown(): void
    {
        $this->shutdown = true;
    }

    public function resetStack(): void
    {
        $this->messageStack = [];
    }

    /**
     * @return bool
     */
    public function isShutdown(): bool
    {
        return $this->shutdown;
    }

    /**
     * @return string
     */
    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    /**
     * @return array
     */
    public function getTags(): string
    {
        return $this->tagsEncoded;
    }

    /**
     * @return string
     */
    public function getHostname(): string
    {
        return $this->hostname;
    }

    /**
     * @return string
     */
    public function getAppName(): string
    {
        return $this->appName;
    }

    public function getLogger(): AttachableThreadedLogger
    {
        return $this->logger;
    }

}