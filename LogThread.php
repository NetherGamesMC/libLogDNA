<?php

declare(strict_types=1);

namespace libLogDNA;

use libLogDNA\task\LogRegisterTask;
use LogLevel;
use NetherGames\NGEssentials\thread\NGThreadPool;
use pocketmine\Server;
use pocketmine\thread\Thread;
use pocketmine\thread\Worker;
use pocketmine\utils\Internet;
use pocketmine\utils\InternetException;
use ReflectionClass;
use Threaded;

/**
 * Logging utility which utilizes to log all messages even when the message comes from another thread.
 */
class LogThread extends Thread
{
    public const PUBLISHING_DELAY = 5; // every 5 seconds
    public const PUBLISHER_URL = "https://logs.logdna.com/logs/ingest?hostname={host}&tags={tags}&now={now}";

    /** @var Threaded */
    private Threaded $mainToThreadBuffer;
    /** @var string */
    private string $tagsEncoded;
    /** @var bool */
    private bool $isRunning = true;

    public function __construct(
        private string $accessToken,
        private string $hostname,
        array          $tags,
        private string $environment = 'production'
    )
    {
        $this->mainToThreadBuffer = new Threaded;
        $this->tagsEncoded = implode(",", $tags);

        LogInstance::set($this);

        Server::getInstance()->getAsyncPool()->addWorkerStartHook(function (int $worker): void {
            Server::getInstance()->getAsyncPool()->submitTaskToWorker(new LogRegisterTask(), $worker);
        });

        NGThreadPool::getInstance()->addWorkerStartHook(function (int $worker): void {
            NGThreadPool::getInstance()->submitTaskToWorker(new LogRegisterTask(), $worker);
        });

        $this->start(PTHREADS_INHERIT_INI | PTHREADS_INHERIT_CONSTANTS);
    }

    protected function onRun(): void
    {
        LogInstance::set($this);

        $this->ingestLog("Starting LogDNA logger utility.");

        $pending = null;
        while ($this->isRunning) {
            $start = microtime(true);

            $this->tickProcessor($pending);

            $time = microtime(true) - $start;
            if ($time < self::PUBLISHING_DELAY) {
                $sleepUntil = (int)((self::PUBLISHING_DELAY - $time) * 1000000);

                $this->synchronized(function () use ($sleepUntil): void {
                    $this->wait($sleepUntil);
                });
            }
        }

        $this->ingestLog("Shutting down LogDNA logger utility.");

        $this->tickProcessor($pending);
    }

    private function tickProcessor(&$pending): void
    {
        if ($pending === null) {
            $payload = $this->mainToThreadBuffer->synchronized(function (): array {
                $results = [];

                while (($buffer = $this->mainToThreadBuffer->shift()) !== null) {
                    /** @var array $message */
                    $message = igbinary_unserialize($buffer);

                    $digest = [];
                    $digest['line'] = $message[0];
                    $digest['level'] = strtoupper($message[1]);
                    $digest['app'] = $message[2];
                    $digest['env'] = $this->environment;

                    $results[] = $digest;
                }

                return $results;
            });
        } else {
            $payload = $pending;
        }

        if (empty($payload)) {
            return;
        }

        $ingestUrl = str_replace(["{host}", "{tags}", "{now}"], [$this->hostname, $this->tagsEncoded, time()], self::PUBLISHER_URL);

        try {
            $jsonPayload = json_encode(['lines' => $payload]);

            $v = Internet::simpleCurl($ingestUrl, 10, [
                "User-Agent: NetherGamesMC/libLogDNA",
                'Content-Type: application/json'
            ], [
                CURLOPT_POST => 1,
                CURLOPT_USERPWD => $this->accessToken . ':',
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_POSTFIELDS => $jsonPayload
            ]);

            if ($v->getCode() === 200) {
                $pending = null;
            }
        } catch (InternetException) {
            $pending = $payload;
        }
    }

    public function quit(): void
    {
        // Proper synchronization to quit the thread.
        $this->synchronized(function (): void {
            $this->isRunning = false;

            $this->notify();
        });

        parent::quit();
    }

    /**
     * LogDNA ingestion function, utility function to communicate across threads to pass log messages.
     * Can be used in any threads as long the object holds this initialization correctly.
     *
     * @param string $message
     * @param string $level
     */
    public function ingestLog(string $message, string $level = LogLevel::INFO): void
    {
        $thread = Thread::getCurrentThread();
        if ($thread === null) {
            $threadName = "Server thread";
        } elseif ($thread instanceof Thread or $thread instanceof Worker) {
            $threadName = $thread->getThreadName() . " thread";
        } else {
            $threadName = (new ReflectionClass($thread))->getShortName() . " thread";
        }

        $this->mainToThreadBuffer->synchronized(function () use ($message, $level, $threadName) {
            $this->mainToThreadBuffer[] = igbinary_serialize([$message, $level, $threadName]);
        });
    }
}