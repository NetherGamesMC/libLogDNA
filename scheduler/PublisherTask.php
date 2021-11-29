<?php
declare(strict_types=1);

namespace libLogDNA\scheduler;

use libasynCurl\Curl;
use libLogDNA\LogProxy;
use pocketmine\scheduler\Task;
use pocketmine\utils\InternetRequestResult;

class PublisherTask extends Task
{
    public const PUBLISHER_URL = "https://logs.logdna.com/logs/ingest?hostname={host}&tags={tags}&now={now}";

    public function __construct(
        public LogProxy $logProxy
    )
    {
    }

    public function onRun(): void
    {

        $proxy = $this->logProxy;

        $payload = $proxy->generateJSON();
        $proxy->resetStack();

        $url = str_replace(["{url}", "{tags}", "{now}"], [$this->logProxy->getHostname(), $proxy->getTags(), time()], self::PUBLISHER_URL);

        Curl::postRequest(
            $url,
            $payload,
            10,
            [
                "apikey" => $this->logProxy->getAccessToken(),
                "User-Agent" => "NetherGamesMC/libLogDNA",
                "Accept" => "application/json"
            ],
            function (?InternetRequestResult $result) use ($proxy) {
                if ($result->getCode() !== 200) {
                    $this->logProxy->getLogger()->error("Error while publishing LogDNA logs: Server returned code " . $result->getCode() . ": " . $result->getBody());
                }
            }
        );

        if ($proxy->isShutdown()) {
            $this->getHandler()->cancel();
        }
    }
}