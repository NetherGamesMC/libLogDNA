<?php

declare(strict_types=1);

namespace libLogDNA;

use libLogDNA\task\LogRegisterTask;
use LogLevel;
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
    public const PUBLISHING_MAX_LINES = 20; // 20 lines per query.
    public const PUBLISHER_URL = "https://logs.logdna.com/logs/ingest?hostname={host}&tags={tags}";

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
    /** @var string */
    private string $forceIgnore;

    /**
     * @param string $accessToken The access token to the logDNA server, this property is required.
     * @param string $hostname The hostname of this server.
     * @param array $tags The tags of the software itself, can be more than one to indicate specific type of server.
     * @param array $threadExclusion The thread that will be responsible to excludes all given log exclusion. Other threads will be able to send the logs freely even with exclusion.
     * @param array $logExcludes The log level that will be excluded from being logged into logDNA.
     * @param array $forceIgnore Force ignores any lines within this level.
     * @param array $regexIncludes The regular expression for a log pattern that will be included even the log is being excluded.
     * @param string $environment The environment of the server software.
     */
    public function __construct(
        private string $composerPath,
        private string $accessToken,
        private string $hostname,
        array          $tags,
        array          $threadExclusion = ["Server thread"],
        array          $logExcludes = [LogLevel::INFO, LogLevel::NOTICE, LogLevel::WARNING, LogLevel::ERROR],
        array          $forceIgnore = [LogLevel::NOTICE, LogLevel::WARNING, LogLevel::ERROR],
        array          $regexIncludes = ["#^\[NetworkSession: ((?!localhost 19132).)*] Player#i", "#^\[NetworkSession:((?!localhost 19132).)*] Session closed#i", "/Graceful shutdown complete/i"],
        private string $environment = 'production'
    )
    {
        $this->mainToThreadBuffer = new Threaded;
        $this->tagsEncoded = implode(",", $tags);
        $this->threadExclusion = implode(",", $threadExclusion);
        $this->logExclusion = implode(",", $logExcludes);
        $this->regexInclusion = implode(",", $regexIncludes);
        $this->forceIgnore = implode(",", $forceIgnore);

        LogInstance::set($this);

        Server::getInstance()->getAsyncPool()->addWorkerStartHook(function (int $worker): void {
            Server::getInstance()->getAsyncPool()->submitTaskToWorker(new LogRegisterTask(), $worker);
        });

        $this->start(PTHREADS_INHERIT_INI | PTHREADS_INHERIT_CONSTANTS);
    }

    protected function onRun(): void
    {
        if (!empty($this->composerPath)) {
            require $this->composerPath;
        }

        LogInstance::set($this);

        $this->ingestLog("Starting LogDNA logger utility.");

        $logExcludes = explode(',', $this->logExclusion);
        $regexIncludes = explode(',', $this->regexInclusion);
        $threadExclusion = explode(',', $this->threadExclusion);
        $forceIgnore = explode(",", $this->forceIgnore);

        $pending = null;
        while (!$this->isKilled) {
            $start = microtime(true);

            $this->tickProcessor($pending, $logExcludes, $regexIncludes, $threadExclusion, $forceIgnore);

            $time = microtime(true) - $start;
            if ($time < self::PUBLISHING_DELAY && $pending === null) {
                $sleepUntil = (int)((self::PUBLISHING_DELAY - $time) * 1000000);

                $this->synchronized(function () use ($sleepUntil): void {
                    $this->wait($sleepUntil);
                });
            }
        }

        $this->ingestLog("Shutting down LogDNA logger utility.");

        $this->tickProcessor($pending, $logExcludes, $regexIncludes, $threadExclusion, $forceIgnore);
    }

    private function tickProcessor(?array &$pending, array $logExcludes, array $regexIncludes, array $threadExclusion, array $forceIgnore): void
    {
        if ($pending === null) {
            $payload = $this->mainToThreadBuffer->synchronized(function (): array {
                $results = [];

                while (($buffer = $this->mainToThreadBuffer->shift()) !== null) {
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

            if (in_array(strtolower($level), $forceIgnore, true)) {
                unset($payload[$id]);
            }
        }

        // Try to reindex the array values, the keys should be in the wrong
        // order because of the previous filtering and such.
        if (empty($payload = array_values($payload))) {
            return;
        }

        $ingestUrl = str_replace(["{host}", "{tags}"], [$this->hostname, $this->tagsEncoded, time()], self::PUBLISHER_URL);

        try {
            $chunks = array_chunk($payload, self::PUBLISHING_MAX_LINES);
            $lines = array_shift($chunks);
            $pending = array_merge(...$chunks);

            $jsonPayload = json_encode(['lines' => $lines]);

            $v = Internet::simpleCurl($ingestUrl, 10, [
                "User-Agent: NetherGamesMC/libLogDNA",
                'Content-Type: application/json'
            ], [
                CURLOPT_POST => 1,
                CURLOPT_USERPWD => $this->accessToken . ':',
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_POSTFIELDS => $jsonPayload
            ]);

            if ($v->getCode() === 200 && empty($pending)) {
                $pending = null;
            }
        } catch (InternetException) {
            $pending = $payload;
        }
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