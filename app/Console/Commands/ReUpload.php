<?php namespace App\Console\Commands;

use Illuminate\Console\Command;
use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local as Adapter;
use League\Flysystem\Sftp\SftpAdapter;
use Intervention\Image\ImageManager;
use Proofgen\Utility;
use Proofgen\Image;

class ReUpload extends Command {

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'proofgen:re-upload';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-upload proofs from a given class.';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function fire()
    {

        ini_set('memory_limit', getenv('PHP_MEMORY_LIMIT'));

        $base_path  = getenv('FULLSIZE_HOME_DIR');

        $this->info('Enter show folder... (Required)');
        $this->info('Enter "list" to see all available shows.');

        $contents = Utility::getContentsOfPath($base_path);
        $shows    = $contents['directories'];

        $show = $this->ask('Show:');
        $all_shows = [];

        foreach($shows as $s)
        {
            if($show == 'list')
            {
                $this->info(' - '.$s['path']);
            }
            $all_shows[] = $s['path'];
        }

        if($show == 'list')
        {
            $this->info('');
            $show = $this->ask('Show:');
        }


        if($show == '')
            exit('Show required'.PHP_EOL);

        if( ! in_array($show, $all_shows))
            exit('Show folder not found.'.PHP_EOL);

        $this->info('');
        $this->info('Enter class folder or folders separated by commas (example: 123, or: 123,124,125)...');
        $this->info('Enter "list" to see all available classes.');

        $class = $this->ask('Class:');

        $contents   = Utility::getContentsOfPath($base_path.'/'.$show);
        $classes    = $contents['directories'];

        $all_classes = [];
        foreach($classes as $c)
        {
            if($class == 'list')
            {
                $this->info('-- '.$c['path']);
            }
            $all_classes[] = $c['path'];
        }

        if($class == 'list')
        {
            $this->info('');
            $class = $this->ask('Class:');
        }

        // Confirm that the class(s) exist
        if($class !== 'all')
        {
            if(mb_stristr($class, ','))
            {
                $classes = explode(',', $class);
                foreach($classes as $class_check)
                {
                    if( ! in_array($class, $all_classes))
                        exit('Class folder not found.'.PHP_EOL);
                }
            }

            if( ! in_array($class, $all_classes))
                exit('Class folder not found.'.PHP_EOL);
        }

        if($class == 'list')
        {
            $this->info('Too late to do that again... try again.');
            exit();
        }

        $this->info('');
        $this->info('----------------');
        $this->info('You have chosen to re-upload proofs for');
        $this->info('Show: '.$show);
        $this->info('Class: '.$class);

        if($this->confirm('Do you want to proceed?'))
        {
            $this->info('Here we go...');

            $classes_to_run = [];

            // A specific class was selected, run it.
            if(mb_stristr($class, ','))
                $classes_to_run = explode(',', $class);
            else
                $classes_to_run[] = $class;

            foreach($classes_to_run as $class)
            {
                $this->info('Generating list of proofs for class '.$class);

                // Get array of images in the originals path, to process into uploads
                $images = Utility::getContentsOfPath($base_path.'/'.$show.'/'.$class.'/originals');
                $images = $images['images'];

                // Add each image to an array for upload
                foreach($images as $image)
                {
                    $upload[] = [
                        'path' => $base_path.'/'.$show.'/'.$class,
                        'file' => $image['basename'],
                        'show' => $show,
                        'class'=> $class
                    ];
                }
                unset($images);
            }

            $this->info('Proofs acquired...');
            $this->info('');

            // Upload any needed files
            if(count($upload))
            {
                $this->info('Starting upload of '.count($upload).' thumbnails.');
                try{
                    Image::uploadThumbnails($upload);
                }
                catch(\ErrorException $e)
                {

                    foreach($classes_to_run as $class_name)
                    {
                        // Turn this into a dump into some sort of error_log that's created in the home directory and run through a proofgen:errors or something
                        $error = 'upload '.$show.'/'.$class_name.PHP_EOL;
                        Utility::addErrorLog($error);
                    }

                    echo $e->getMessage().PHP_EOL;
                    $this->info('Error caught, added to error log. Run "php artisan proofgen:errors to process them."');
                }
            }
            else
            {
                $this->info('Nothing to upload.');
            }
        }

        $this->info('');
        $this->info('Ending execution.');
        $this->info('');
    }

}
