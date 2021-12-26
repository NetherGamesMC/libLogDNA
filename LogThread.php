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
    /** @var string */
    private string $threadExclusion;
    /** @var string */
    private string $logExclusion;
    /** @var string */
    private string $regexInclusion;
    /** @var bool */
    private bool $isRunning = true;

    /**
     * @param string $accessToken The access token to the logDNA server, this property is required.
     * @param string $hostname The hostname of this server.
     * @param array $tags The tags of the software itself, can be more than one to indicate specific type of server.
     * @param array $threadExclusion The thread that will be responsible to excludes all given log exclusion. Other threads will be able to send the logs freely even with exclusion.
     * @param array $logExcludes The log level that will be excluded from being logged into logDNA.
     * @param array $regexIncludes The regular expression for a log pattern that will be included even the log is being excluded.
     * @param string $environment The environment of the server software.
     */
    public function __construct(
        private string $accessToken,
        private string $hostname,
        array          $tags,
        array          $threadExclusion = ["Server thread"],
        array          $logExcludes = [LogLevel::INFO, LogLevel::NOTICE],
        array          $regexIncludes = ["[NetworkSession: (.*?)]", "/Graceful shutdown complete/"],
        private string $environment = 'production'
    )
    {
        $this->mainToThreadBuffer = new Threaded;
        $this->tagsEncoded = implode(",", $tags);
        $this->threadExclusion = implode(",", $threadExclusion);
        $this->logExclusion = implode(",", $logExcludes);
        $this->regexInclusion = implode(",", $regexIncludes);

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

        $logExcludes = explode(',', $this->logExclusion);
        $regexIncludes = explode(',', $this->regexInclusion);
        $threadExclusion = explode(',', $this->threadExclusion);

        $pending = null;
        while ($this->isRunning) {
            $start = microtime(true);

            $this->tickProcessor($pending, $logExcludes, $regexIncludes, $threadExclusion);

            $time = microtime(true) - $start;
            if ($time < self::PUBLISHING_DELAY) {
                $sleepUntil = (int)((self::PUBLISHING_DELAY - $time) * 1000000);

                $this->synchronized(function () use ($sleepUntil): void {
                    $this->wait($sleepUntil);
                });
            }
        }

        $this->ingestLog("Shutting down LogDNA logger utility.");

        $this->tickProcessor($pending, $logExcludes, $regexIncludes, $threadExclusion);
    }

    private function tickProcessor(?array &$pending, array $logExcludes, array $regexIncludes, array $threadExclusion): void
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
                    $digest['timestamp'] = $message[3];
                    $digest['env'] = $this->environment;

                    $results[] = $digest;
                }

                return $results;
            });
        } else {
            $payload = $pending;
        }

        foreach ($payload as $id => ['level' => $level, 'line' => $line, 'app' => $threadName]) {
            if (in_array(strtolower($level), $logExcludes, true) && in_array($threadName, $threadExclusion)) {
                $regexMatches = false;

                foreach ($regexIncludes as $regex) {
                    $result = preg_match($regex, $line);

                    if ($regexMatches = (!is_bool($result) && $result > 0)) {
                        break;
                    }
                }

                if (!$regexMatches) {
                    unset($payload[$id]);
                }
            }
        }

        // Try to reindex the array values, the keys should be in the wrong
        // order because of the previous filtering and such.
        if (empty($payload = array_values($payload))) {
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
            $this->mainToThreadBuffer[] = igbinary_serialize([$message, $level, $threadName, time()]);
        });
    }
}