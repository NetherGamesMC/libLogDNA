<?php

declare(strict_types=1);

namespace libLogDNA\task;

use libLogDNA\LogInstance;
use libLogDNA\LogThread;
use pocketmine\scheduler\AsyncTask;

class LogRegisterTask extends AsyncTask
{
    private LogThread $logger;

    public function __construct()
    {
        $this->logger = LogInstance::get();
    }

    public function onRun(): void
    {
        LogInstance::set($this->logger);
    }
}