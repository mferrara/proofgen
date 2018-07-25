<?php


namespace Proofgen\PooledJobs;


class AutoloadedWorker extends \Worker
{
    public function run()
    {
        //Auto loading library for threads
        require_once '/vagrant/vendor/autoload.php';
    }
    public function start(int $options = NULL)
    {
        //invoking thread with inherit none
        parent::start(PTHREADS_INHERIT_NONE);
    }
}