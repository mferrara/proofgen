<?php

namespace App\Jobs;

use Illuminate\Contracts\Bus\SelfHandling;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;
use Proofgen\Image;

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
