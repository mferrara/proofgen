<?php


namespace Proofgen\PooledJobs;


use Proofgen\Image;
use Symfony\Component\Debug\Exception\FatalErrorException;
use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local as Adapter;

class GenerateThumbnails extends \Threaded
{
    private $thumbnail_data;

    public function __construct($thumbnail_data)
    {
        $this->thumbnail_data = $thumbnail_data;
    }

    public function run()
    {
        $start  = microtime(true);

        try{
            Image::checkImageForThumbnails($this->thumbnail_data['path'], $this->thumbnail_data['file']);
        }
        catch(FatalErrorException $e)
        {
            echo 'Error creating thumbnails, resetting image.'.PHP_EOL;

            $temp_filename = 'temp'.rand(0,999999).'.jpg';
            $flysystem  = new Filesystem(new Adapter($this->thumbnail_data['path']));
            $flysystem->copy('originals/'.$this->thumbnail_data['file'], $temp_filename);

            echo 'Confirming reset of image.'.PHP_EOL;
            if($flysystem->has($temp_filename))
            {
                $flysystem->delete('originals/'.$this->thumbnail_data['file']);
                echo 'Original moved back to processing folder, ready to try again.'.PHP_EOL;
            }

            throw $e;
        }

        $end    = microtime(true);

        $elapsed_time = ($end - $start);
        $message = $this->thumbnail_data['file'].' thumbnailed in '.round($elapsed_time, 2).'s '.' - Current memory usage:   '.self::convert(memory_get_usage(true)).' ';

        echo $message.PHP_EOL;
    }

    public static function convert($size)
    {
        $unit=array('b','kb','mb','gb','tb','pb');
        return @round($size/pow(1024,($i=floor(log($size,1024)))),2).' '.$unit[$i];
    }
}
