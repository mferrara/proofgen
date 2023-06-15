<?php

namespace Proofgen\PooledJobs;

class AutoloadedWorker extends \Worker
{
    public function run()
    {
        //Auto loading library for threads
        require_once getenv('VENDOR_FULL_PATH').'autoload.php';
    }

    public function start(int $options = null)
    {
        //invoking thread with inherit none
        parent::start(PTHREADS_INHERIT_NONE);
    }
}
