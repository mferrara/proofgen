<?php namespace Proofgen;


use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local as Adapter;

class Utility {

    public static function regenerateThumbnails($show, $class)
    {
        $base_path = getenv('FULLSIZE_HOME_DIR');
        $class_path = implode('/', [$base_path, $show, $class]);

        $originals_path = $class_path.'/originals';
        $proofs_path    = $class_path.'/proofs';

        // Remove existing proofs
        $flysystem      = new Filesystem(new Adapter($class_path));
        // Delete proofs directory
        $flysystem->deleteDir('proofs');
        // Recreate proofs directory
        $flysystem->createDir('proofs');

        // Get originals to process
        $contents = self::getContentsOfPath($originals_path);
        $images = $contents['images'];

        foreach($images as $image)
        {
            $filename = $image['path'];
            echo 'Regenerating proofs for '.$filename.PHP_EOL;
            Image::checkImageForThumbnails($class_path,$filename,$show,$class);
        }
    }

    public static function getContentsOfPath($path, $recursive = false)
    {
        $flysystem      = new Filesystem(new Adapter($path));
        $contents       = $flysystem->listContents('', $recursive);

        $directories    = [];
        $images         = [];
        foreach($contents as $key => $object)
        {
            switch($object['type'])
            {
                case "dir":
                    $directories[] = $object;
                    break;
                case "file":
                    if(
                        $object['extension'] == 'JPG'
                        || $object['extension'] == 'JPEG'
                        || $object['extension'] == 'jpg'
                        || $object['extension'] == 'jpeg'

                    )
                        $images[] = $object;
                    break;
            }
        }

        // If there's images, sort them by their timestamp
        if(count($images))
        {
            // Sort images by timestamp
            $temp_images = $images;
            $images = array();
            foreach ($temp_images as $key => $row)
            {
                $images[$key] = $row['timestamp'];
            }
            array_multisort($images, SORT_ASC, $temp_images);
            $images = $temp_images;
            unset($temp_images);
        }

        return [
            'directories'   => $directories,
            'images'        => $images
        ];
    }

    public static function checkArchivePath($path)
    {
        $archive_home_dir = getenv('ARCHIVE_HOME_DIR');
        $flysystem      = new Filesystem(new Adapter($archive_home_dir));

        if($flysystem->createDir($path))
            return true;

        return false;
    }

    public static function checkDirectoryForProofsPath($path)
    {
        $contents = self::getContentsOfPath($path);

        if(count($contents['directories']) > 0)
        {
            foreach($contents['directories'] as $dir)
            {
                if($dir['path'] == 'proofs')
                    return true;
            }
        }

        $flysystem      = new Filesystem(new Adapter($path));
        if($flysystem->createDir('proofs'))
            return true;

        return false;
    }

    public static function checkDirectoryForOriginalsPath($path)
    {
        $contents = self::getContentsOfPath($path);

        if(count($contents['directories']) > 0)
        {
            foreach($contents['directories'] as $dir)
            {
                if($dir['path'] == 'originals')
                    return true;
            }
        }

        $flysystem      = new Filesystem(new Adapter($path));
        if($flysystem->createDir('originals'))
            return true;

        return false;
    }

}