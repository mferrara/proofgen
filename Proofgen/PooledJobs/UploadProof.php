<?php


namespace Proofgen\PooledJobs;


use Proofgen\Image;

class UploadProof extends \Threaded
{
    private $upload;

    public function __construct($upload)
    {
        $this->upload = $upload;
    }

    public function run()
    {
        $start  = microtime(true);
        Image::uploadThumbnail($this->upload);
        $end    = microtime(true);

        $elapsed_time = ($end - $start);

        $message = $this->upload['file'].' Uploaded in '.round($elapsed_time, 2).'s';

        echo $message.PHP_EOL;
    }
}
