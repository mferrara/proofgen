<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use League\Flysystem\Adapter\Local as Adapter;
use League\Flysystem\Exception;
use League\Flysystem\Filesystem;
use Proofgen\Image;
use Proofgen\Utility;

class ProcessImages extends Command
{
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
        try {
            $archive_fs = new Filesystem(new Adapter(getenv('ARCHIVE_HOME_DIR')));
            unset($archive_fs);
        } catch(Exception $e) {
            dd('Archive/Backup filesystem not accessible - Ending execution');
        }

        // Check the error log to see if there's stuff in there that needs to process..
        $errors = Utility::parseErrorLog();
        if (count($errors)) {
            $this->error('Yo, there\'s stuff in the error log you may want to process..');
        }

        $this->info('Starting...');
        $base_path = getenv('FULLSIZE_HOME_DIR');
        $this->info('Checking '.$base_path.' for new images.. ');
        $max_images = getenv('MAX_IMAGES_PER_RUN');

        $contents = Utility::getContentsOfPath($base_path);
        $shows = $contents['directories'];
        $upload = [];

        $this->info('Base path has '.count($shows).' directories.');
        $this->info('Processing up to '.$max_images.' images this run.');
        $this->info('');

        //$are_we_uploading = $this->confirm('Shall we upload these proofs? [yes/no]');
        $are_we_uploading = getenv('UPLOAD_PROOFS');
        if ($are_we_uploading === 'TRUE') {
            $are_we_uploading = true;
        } else {
            $are_we_uploading = false;
        }

        unset($contents);

        $results = [];
        $processed_count = 0;
        $to_thumbnail = [];
        // Cycle through each show, checking for class folders and images within them
        foreach ($shows as $directory) {
            $show_path = $base_path.'/'.$directory['path'];
            $show_name = $directory['path'];

            $this->info('Checking '.$directory['path'].'...');
            $this->info('');

            $contents = Utility::getContentsOfPath($show_path);

            // if there's directories, run through them, processing them
            if (count($contents['directories']) > 0) {
                $classes = $contents['directories'];
                foreach ($classes as $class) {
                    $class_path = $show_path.'/'.$class['path'];
                    $class_name = $class['path'];
                    // Check for /proofs directory
                    // Check for /originals directory
                    Utility::checkDirectoryForProofsPath($class_path);
                    Utility::checkDirectoryForOriginalsPath($class_path);
                    // TODO: Fix this - it's using the full path but we just want to use the relative path
                    Utility::checkArchivePath($class['path']);

                    // Pull all image files
                    $contents = Utility::getContentsOfPath($class_path);
                    $images = $contents['images'];
                    $contents = null;
                    unset($contents);

                    // If there's images, run through them
                    // Rename them
                    // Move them
                    // Thumbnail them
                    if (count($images) > 0) {
                        // Get proof numbers here
                        // Generate a proof number (before the copy so we're not seeing/including the new file)
                        $proof_numbers = Image::generateProofNumbers($directory['path'], count($images));



                        $maxProcesses = env('THREAD_COUNT'); // Max number of parallel processes
                        $processCount = 0;
                        $children = [];

                        $pipes = [];

                        // Cut the $images array down to $max_images
                        if(count($images) > $max_images - $processed_count)
                            $images = array_slice($images, 0, $max_images - $processed_count);

                        foreach ($images as $image) {

                            $pipe = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                            if (!$pipe) {
                                die('Could not create pipe');
                            }

                            $pipes[] = $pipe;

                            $proof_number = array_shift($proof_numbers);

                            $pid = pcntl_fork(); // Fork the process

                            if ($pid == -1) {
                                // Could not fork
                                die("Could not fork the process\n");
                            } elseif ($pid) {
                                // We are the parent
                                fclose($pipe[1]); // Close the unused write end in the parent

                                $processCount++;
                                $children[] = $pid;

                                if ($processCount >= $maxProcesses) {
                                    pcntl_wait($status); // Wait for any child process to finish
                                    $processCount--;
                                }
                            } else {
                                // This is the child process
                                fclose($pipe[0]); // Close the unused read end in the child

                                $start = microtime(true);
                                $image_filename = Image::processNewImage($class_path, $image, $proof_number, $this);
                                if ($image_filename) {
                                    $data = [
                                        'upload' => [
                                            'path' => $class_path,
                                            'file' => $image_filename,
                                            'show' => $show_name,
                                            'class' => $class_name,
                                        ],
                                        'to_thumbnail' => [
                                            'path' => $class_path,
                                            'file' => $image_filename,
                                        ]
                                    ];

                                    // Write the data to the pipe
                                    fwrite($pipe[1], serialize($data));
                                    fclose($pipe[1]);
                                }
                                $end = microtime(true);
                                $total = number_format(($end - $start));
                                $processed_count++;
                                echo 'Completed'.' - '.$class_name.'/'.$image['path'].' -> '.$class_name.'/'.$image_filename.' in '.$total.'s'.' ('.$processed_count.'/'.count($images).')' . "\n";

                                exit(); // Important: end child process
                            }
                        }

                        // Read data from pipes
                        foreach ($pipes as $pipe) {
                            $data = stream_get_contents($pipe[0]);
                            fclose($pipe[0]);

                            if ($data !== false) {
                                $data = unserialize($data);
                                $upload[] = $data['upload'];
                                $to_thumbnail[] = $data['to_thumbnail'];
                            }
                        }

                        // Wait for remaining child processes to finish
                        while (count($children) > 0) {
                            foreach ($children as $key => $pid) {
                                $res = pcntl_waitpid($pid, $status, WNOHANG);

                                if ($res == -1 || $res > 0) {
                                    unset($children[$key]);
                                }
                            }
                            sleep(1); // Avoid busy waiting
                        }

                        // Fill the results array outside of the fork loop
                        foreach($images as $image){
                            if (isset($results[$show_name][$class_name])) {
                                $results[$show_name][$class_name]++;
                            } else {
                                $results[$show_name][$class_name] = 1;
                            }

                            $processed_count++;
                        }

                        /*
                        foreach ($images as $image) {
                            //$this->info('Importing '.$image['path'].'...');
                            $start = microtime(true);
                            $proof_number = array_shift($proof_numbers);
                            $image_filename = Image::processNewImage($class_path, $image, $proof_number, $this);
                            if ($image_filename) {
                                $upload[] = [
                                    'path' => $class_path,
                                    'file' => $image_filename,
                                    'show' => $show_name,
                                    'class' => $class_name,
                                ];

                                $to_thumbnail[] = [
                                    'path' => $class_path,
                                    'file' => $image_filename,
                                ];
                            }
                            $end = microtime(true);
                            $total = number_format(($end - $start));
                            $processed_count++;
                            $this->comment('Completed'.' - '.$class_name.'/'.$image['path'].' -> '.$class_name.'/'.$image_filename.' in '.$total.'s'.' ('.$processed_count.'/'.count($images).')');

                            if (isset($results[$show_name][$class_name])) {
                                $results[$show_name][$class_name]++;
                            } else {
                                $results[$show_name][$class_name] = 1;
                            }

                            // Check to see if we've reached the $max_images configuration setting, if so break out of the loops.
                            if ($processed_count >= $max_images) {
                                $this->info('');
                                $this->comment('Maximum images reached. Processing will continue next run.');
                                $this->info('');
                                break 3;
                            }

                            $image_filename = null;
                            unset($image_filename);
                        }
                        */

                        // Check to see if we've reached the $max_images configuration setting, if so break out of the loops.
                        if ($processed_count >= $max_images) {
                            $this->info('');
                            $this->comment('Maximum images reached. Processing will continue next run.');
                            $this->info('');
                            break 2;
                        }
                    }
                }
            }
        }

        // Create thumbnails
        if (count($to_thumbnail)) {
            $this->comment('Creating '.count($to_thumbnail).' thumbnails...');
            $image_class = new Image();
            $image_class->batchGenerateThumbnails($to_thumbnail);
        }

        // Upload any needed files
        if (count($upload)) {
            if ($are_we_uploading) {
                try {
                    Image::uploadThumbnails($upload);
                } catch(\ErrorException $e) {
                    foreach ($results as $s_name => $classes) {
                        foreach ($classes as $class_name => $count) {
                            // Turn this into a dump into some sort of error_log that's created in the home directory and run through a proofgen:processerrorlog or something
                            $error = 'upload '.$s_name.'/'.$class_name.PHP_EOL;
                            Utility::addErrorLog($error);
                        }
                    }

                    echo $e->getMessage().PHP_EOL;
                    $this->info('Error caught, added to error log. Run "php artisan proofgen:errors to process them."');
                }
            } else {
                $this->info('');
                $this->info('Uploading skipped by user.');
                $this->info('');
            }
        }

        // Output results

        if (count($results) > 0) {
            $this->info('');
            $this->info('=====================');
            $this->info('Import Results');
            $this->info('=====================');
            foreach ($results as $show_name => $classes) {
                $this->info('=====================');
                $this->info('- '.$show_name);

                foreach ($classes as $class_name => $count) {
                    $this->info('-- '.$class_name.' - '.$count.' new images.');
                }
            }
        } else {
            $this->info('No new images found.');
        }

        $this->info('');
        $this->info('Ending execution.');
        $this->info('');
    }
}
