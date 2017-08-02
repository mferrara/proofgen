<?php

namespace App\Jobs;

use App\Jobs\Job;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Bus\SelfHandling;
use Illuminate\Contracts\Queue\ShouldQueue;

use League\Flysystem\Filesystem;
use League\Flysystem\Sftp\SftpAdapter;

use Proofgen\Image;
use Proofgen\Utility;

class UploadProofs extends Job implements SelfHandling, ShouldQueue
{
    use SerializesModels;

    protected $path;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($parameters)
    {
        $this->parameters = $parameters;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Image::uploadThumbnail($this->parameters);
    }
}