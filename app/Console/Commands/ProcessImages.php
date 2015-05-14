<?php namespace App\Console\Commands;

use Illuminate\Console\Command;
use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local as Adapter;
use League\Flysystem\Sftp\SftpAdapter;
use Intervention\Image\ImageManager;
use Proofgen\Utility;
use Proofgen\Image;

class ProcessImages extends Command {

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'proofgen:process';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Traverse all directories, creating thumbnails where needed.';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function fire()
    {

        $this->info('Starting...');
        $base_path  = getenv('FULLSIZE_HOME_DIR');
        $this->info('Checking '.$base_path.' for new images.. ');

        $contents = Utility::getContentsOfPath($base_path);
        $shows    = $contents['directories'];
        $upload = [];

        $this->info('Base path has '.count($shows).' directories.');
        $this->info('');

        unset($contents);

        $results = [];
        // Cycle through each show, checking for class folders and images within them
        foreach($shows as $directory)
        {
            $show_path = $base_path.'/'.$directory['path'];
            $show_name = $directory['path'];

            $this->info('Checking '.$directory['path'].'...');
            $this->info('');

            $contents = Utility::getContentsOfPath($show_path);

            // if there's directories, run through them, processing them
            if(count($contents['directories']) > 0)
            {
                $classes = $contents['directories'];
                foreach($classes as $class)
                {
                    $class_path = $show_path.'/'.$class['path'];
                    $class_name = $class['path'];
                    // Check for /proofs directory
                    // Check for /originals directory
                    Utility::checkDirectoryForProofsPath($class_path);
                    Utility::checkDirectoryForOriginalsPath($class_path);
                    //Utility::checkArchivePath($class_path);

                    // Pull all image files
                    $images = Utility::getContentsOfPath($class_path);
                    $images = $images['images'];

                    // If there's images, run through them
                    // Rename them
                    // Move them
                    // Thumbnail them
                    if(count($images) > 0)
                    {
                        foreach($images as $image)
                        {
                            $image_path = $class_path.'/'.$image['path'];

                            $this->info('Importing '.$image['path'].'...');
                            $image_filename = Image::processNewImage($class_path, $image);
                            if($image_filename)
                            {
                                $upload[] = [
                                    'path' => $class_path,
                                    'file' => $image_filename,
                                    'show' => $show_name,
                                    'class'=> $class_name
                                ];
                            }
                            $this->comment('Completed '.' - '.$image['path'].' -> '.$image_filename);

                            if(isset($results[$show_name][$class_name]))
                                $results[$show_name][$class_name]++;
                            else
                                $results[$show_name][$class_name] = 1;
                        }
                    }
                }

                // Upload any needed files
                if(count($upload))
                {

                    try{
                        Image::uploadThumbnails($upload);
                    }
                    catch(\ErrorException $e)
                    {
                        foreach($results as $s_name => $classes)
                        {
                            foreach($classes as $class_name => $count)
                            {
                                // Turn this into a dump into some sort of error_log that's created in the home directory and run through a proofgen:processerrorlog or something
                                $error = 'upload '.$s_name.'/'.$class_name.PHP_EOL;
                                Utility::addErrorLog($error);
                            }
                        }

                        echo $e->getMessage().PHP_EOL;
                        $this->info('Error caught, added to error log. Run "php artisan proofgen:errors to process them."');
                    }

                }
            }
        }

        // Output results

        if(count($results) > 0)
        {
            $this->info('');
            $this->info('=====================');
            $this->info('Import Results');
            $this->info('=====================');
            foreach($results as $show_name => $classes)
            {
                $this->info('=====================');
                $this->info('- '.$show_name);

                foreach($classes as $class_name => $count)
                {
                    $this->info('-- '.$class_name.' - '.$count.' new images.');
                }
            }


        }
        else
            $this->info('No new images found.');

        $this->info('');
        $this->info('Ending execution.');
        $this->info('');
    }

}
