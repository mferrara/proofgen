<?php namespace App\Console\Commands;

use Illuminate\Console\Command;
use League\Flysystem\Exception;
use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local as Adapter;
use League\Flysystem\Sftp\SftpAdapter;
use Intervention\Image\ImageManager;
use Proofgen\Utility;
use Proofgen\Image;
use Symfony\Component\Debug\Exception\FatalErrorException;

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
        ini_set('memory_limit', getenv('PHP_MEMORY_LIMIT'));

        // Check that the archive drive is in place and accessible
        try{
            $archive_fs = new Filesystem(new Adapter(getenv('ARCHIVE_HOME_DIR')));
            unset($archive_fs);
        }
        catch(Exception $e)
        {
            dd('Archive/Backup filesystem not accessible - Ending execution');
        }

        // Check the error log to see if there's stuff in there that needs to process..
        $errors = Utility::parseErrorLog();
        if(count($errors))
        {
            $this->error('Yo, there\'s stuff in the error log you may want to process..');
        }

        $this->info('Starting...');
        $base_path  = getenv('FULLSIZE_HOME_DIR');
        $this->info('Checking '.$base_path.' for new images.. ');
        $max_images = getenv('MAX_IMAGES_PER_RUN');

        $contents = Utility::getContentsOfPath($base_path);
        $shows    = $contents['directories'];
        $upload = [];

        $this->info('Base path has '.count($shows).' directories.');
        $this->info('Processing up to '.$max_images.' images this run.');
        $this->info('');

        //$are_we_uploading = $this->confirm('Shall we upload these proofs? [yes/no]');
        $are_we_uploading = getenv('UPLOAD_PROOFS');
        if($are_we_uploading === 'TRUE')
            $are_we_uploading = true;
        else
            $are_we_uploading = false;

        unset($contents);

        $results = [];
        $processed_count = 0;
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
                    // TODO: Fix this - it's using the full path but we just want to use the relative path
                    //Utility::checkArchivePath($class_path);

                    // Pull all image files
                    $contents = Utility::getContentsOfPath($class_path);
                    $images = $contents['images'];
                    $contents = null;
                    unset($contents);

                    // If there's images, run through them
                    // Rename them
                    // Move them
                    // Thumbnail them
                    if(count($images) > 0)
                    {
                        foreach($images as $image)
                        {
                            //$this->info('Importing '.$image['path'].'...');
                            $start          = microtime(true);
                            $image_filename = Image::processNewImage($class_path, $image, $this);
                            if($image_filename)
                            {
                                $upload[] = [
                                    'path' => $class_path,
                                    'file' => $image_filename,
                                    'show' => $show_name,
                                    'class'=> $class_name
                                ];

                                $to_thumbnail[] = [
                                    'path' => $class_path,
                                    'file' => $image_filename
                                ];
                            }
                            $end            = microtime(true);
                            $total          = number_format(($end - $start));
                            $processed_count++;
                            $this->comment('Completed'.' - '.$class_name.'/'.$image['path'].' -> '.$class_name.'/'.$image_filename.' in '.$total.'s'.' ('.$processed_count.'/'.count($images).')');

                            if(isset($results[$show_name][$class_name]))
                                $results[$show_name][$class_name]++;
                            else
                                $results[$show_name][$class_name] = 1;

                            // Check to see if we've reached the $max_images configuration setting, if so break out of the loops.
                            if($processed_count >= $max_images)
                            {
                                $this->info('');
                                $this->comment('Maximum images reached. Processing will continue next run.');
                                $this->info('');
                                break 3;
                            }

                            $image_filename = null;
                            unset($image_filename);
                        }
                    }
                }
            }
        }

        // Create thumbnails
        if(count($to_thumbnail))
        {
            $this->comment('Creating '.count($to_thumbnail).' thumbnails...');
            Image::batchGenerateThumbnailsPooled($to_thumbnail);
        }

        // Upload any needed files
        if(count($upload))
        {
            if($are_we_uploading)
            {
                try{
                    Image::uploadThumbnailsPooled($upload);
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
            else
            {
                $this->info('');
                $this->info('Uploading skipped by user.');
                $this->info('');
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
