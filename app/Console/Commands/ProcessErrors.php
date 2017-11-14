<?php namespace App\Console\Commands;

use Illuminate\Console\Command;
use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local as Adapter;
use League\Flysystem\Sftp\SftpAdapter;
use Intervention\Image\ImageManager;
use League\Flysystem\Util;
use Proofgen\Utility;
use Proofgen\Image;

class ProcessErrors extends Command {

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'proofgen:errors';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process error log.';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function fire()
    {
        ini_set('memory_limit', getenv('PHP_MEMORY_LIMIT'));

        $base_path  = getenv('FULLSIZE_HOME_DIR');

        $errors = Utility::parseErrorLog();

        if(count($errors))
        {
            foreach($errors as $key => $error)
            {
                $action = $error[0];
                $data = $error[1];

                if($action == 'upload')
                {
                    $upload = [];

                    $data = explode('/', $data);
                    $show = $data[0];
                    $class = $data[1];
                    $this->info('Re-uploading '.$show.' '.$class);
                    $contents = Utility::getContentsOfPath($base_path.'/'.$show.'/'.$class.'/proofs');
                    $thumbnails = $contents['images'];

                    foreach($thumbnails as $thumb)
                    {
                        if(stristr($thumb['filename'], '_std'))
                        {
                            $filename = $thumb['filename'];
                            $filename = str_replace('_std','',$filename);
                            $upload[] = [
                                'show' => $show,
                                'class'=> $class,
                                'file' => $filename
                            ];
                        }
                    }

                    try{

                        Image::uploadThumbnails($upload);

                        $this->info('Upload complete, updating error log.');
                        unset($errors[$key]);

                        Utility::updateErrorLog($errors);
                    }
                    catch(\ErrorException $e)
                    {
                        echo $e->getMessage();

                        dd("Error processing error - ".$action.' - '.$data);
                    }
                }
            }
        }


    }

}
