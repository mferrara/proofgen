<?php

namespace App\Jobs;

use App\Jobs\Job;
use Exception;
use Illuminate\Contracts\Bus\SelfHandling;
use League\Flysystem\Adapter\Local as Adapter;
use League\Flysystem\FileExistsException;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\Filesystem;
use Proofgen\Image;

class GenerateThumbnail extends Job implements SelfHandling
{
    public $thumbnail_data;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($thumbnail_data)
    {
        $this->thumbnail_data = $thumbnail_data;
    }

    /**
     * Execute the job.
     *
     * @return void
     * @throws FileExistsException
     * @throws FileNotFoundException
     */
    public function handle()
    {
        $start = microtime(true);

        // Setting memory limit here because it's not inhereited from the parent process
        ini_set('memory_limit', getenv('PHP_MEMORY_LIMIT'));

        try {
            Image::checkImageForThumbnails($this->thumbnail_data['path'], $this->thumbnail_data['file']);
        } catch(Exception $e) {
            echo 'Error creating thumbnails, resetting image.'.PHP_EOL;

            $temp_filename = 'temp'.rand(0, 999999).'.jpg';
            $flysystem = new Filesystem(new Adapter($this->thumbnail_data['path']));
            $flysystem->copy('originals/'.$this->thumbnail_data['file'], $temp_filename);

            echo 'Confirming reset of image.'.PHP_EOL;
            if ($flysystem->has($temp_filename)) {
                $flysystem->delete('originals/'.$this->thumbnail_data['file']);
                echo 'Original moved back to processing folder, ready to try again.'.PHP_EOL;
            }

            throw $e;
        }

        $end = microtime(true);

        $elapsed_time = ($end - $start);
        $message = $this->thumbnail_data['file'].' thumbnailed in '.round($elapsed_time, 2).'s';

        echo $message.PHP_EOL;
    }
}
