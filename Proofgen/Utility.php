<?php

namespace Proofgen;

use League\Flysystem\Adapter\Local as Adapter;
use League\Flysystem\Filesystem;

class Utility
{
    public static function regenerateThumbnails($show, $class)
    {
        $base_path = getenv('FULLSIZE_HOME_DIR');
        $class_path = implode('/', [$base_path, $show, $class]);

        $originals_path = $class_path.'/originals';
        $proofs_path = $class_path.'/proofs';
        $to_thumbnail = [];

        // Remove existing proofs
        $flysystem = new Filesystem(new Adapter($class_path));
        // Delete proofs directory
        $flysystem->deleteDir('proofs');
        // Recreate proofs directory
        $flysystem->createDir('proofs');

        // Get originals to process
        $contents = self::getContentsOfPath($originals_path);
        $images = $contents['images'];

        if (count($images)) {
            echo 'Regenerating '.count($images).' proofs...'.PHP_EOL;
            foreach ($images as $image) {
                //$filename = $image['path'];
                //Image::checkImageForThumbnails($class_path,$filename,$show,$class);
                //echo $filename.' done'.PHP_EOL;
                //unset($filename);
                $to_thumbnail[] = [
                    'path' => $base_path.'/'.$show.'/'.$class,
                    'file' => $image['basename'],
                ];
            }

            if (count($to_thumbnail)) {
                echo 'Creating '.count($to_thumbnail).' thumbnails...'.PHP_EOL;
                Image::batchGenerateThumbnails($to_thumbnail);
                echo 'Thumbnails done.'.PHP_EOL;
            }
        } else {
            echo 'No images found in '.$class.' folder'.PHP_EOL;
        }

        unset($images);
        unset($flysystem);
        unset($contents);
    }

    public static function getContentsOfPath($path, $recursive = false)
    {
        $flysystem = new Filesystem(new Adapter($path));
        $contents = $flysystem->listContents('', $recursive);

        $directories = [];
        $images = [];
        foreach ($contents as $key => $object) {
            switch($object['type']) {
                case 'dir':
                    $directories[] = $object;
                    break;
                case 'file':

                    if ($object['path'] == 'errors') {
                        break;
                    }

                    if (
                        $object['extension'] === 'JPG'
                        || $object['extension'] === 'JPEG'
                        || $object['extension'] === 'jpg'
                        || $object['extension'] === 'jpeg'
                        //|| $object['extension'] === 'cr2'
                        //|| $object['extension'] === 'CR2'

                    ) {
                        $images[] = $object;
                    }
                    break;
            }
        }

        // If there's images, sort them by their timestamp
        if (count($images)) {
            // Sort images by timestamp
            $temp_images = $images;
            $images = [];
            foreach ($temp_images as $key => $row) {
                $images[$key] = $row['timestamp'];
            }
            array_multisort($images, SORT_ASC, $temp_images);
            $images = $temp_images;
            unset($temp_images);
        }

        $flysystem = null;
        $contents = null;
        unset($flysystem);
        unset($contents);

        return [
            'directories' => $directories,
            'images' => $images,
        ];
    }

    public static function checkArchivePath($path)
    {
        $archive_home_dir = getenv('ARCHIVE_HOME_DIR');
        $flysystem = new Filesystem(new Adapter($archive_home_dir));

        if ($flysystem->createDir($path)) {
            return true;
        }

        return false;
    }

    public static function checkDirectoryForProofsPath($path)
    {
        $contents = self::getContentsOfPath($path);

        if (count($contents['directories']) > 0) {
            foreach ($contents['directories'] as $dir) {
                if ($dir['path'] == 'proofs') {
                    return true;
                }
            }
        }

        $flysystem = new Filesystem(new Adapter($path));
        if ($flysystem->createDir('proofs')) {
            return true;
        }

        return false;
    }

    public static function checkDirectoryForOriginalsPath($path)
    {
        $contents = self::getContentsOfPath($path);

        if (count($contents['directories']) > 0) {
            foreach ($contents['directories'] as $dir) {
                if ($dir['path'] == 'originals') {
                    return true;
                }
            }
        }

        $flysystem = new Filesystem(new Adapter($path));
        if ($flysystem->createDir('originals')) {
            return true;
        }

        return false;
    }

    public static function addErrorLog($string)
    {
        $string = trim($string);
        $string = trim($string, "\r");
        $string = trim($string, "\n");

        $flysystem = new Filesystem(new Adapter(getenv('FULLSIZE_HOME_DIR')));

        $errors = [];
        if ($flysystem->has('errors')) {
            $errors = $flysystem->read('errors');
        }

        if (count($errors)) {
            $errors = explode("\r\n", $errors);
        }

        $add = true;

        // If this error isn't already listed in the error doc, add it
        if (count($errors) && in_array($string, $errors)) {
            $add = false;
        }

        if ($add) {
            $errors[] = $string;
            $errors_string = implode("\r\n", $errors);
            $flysystem->put('errors', $errors_string);
        }
    }

    public static function parseErrorLog()
    {
        $flysystem = new Filesystem(new Adapter(getenv('FULLSIZE_HOME_DIR')));

        $errors = null;
        if ($flysystem->has('errors')) {
            $errors = $flysystem->read('errors');
        }

        $return = [];
        if ($errors !== null) {
            $errors = explode("\r\n", $errors);

            foreach ($errors as $line) {
                $values = explode(' ', $line);
                $return[] = [$values[0], $values[1]];
            }
        }

        return $return;
    }

    public static function updateErrorLog($errors)
    {
        $base_path = getenv('FULLSIZE_HOME_DIR');

        $out_errors = [];
        foreach ($errors as $key => $data) {
            $out_errors[] = implode(' ', $data);
        }

        $fly = new Filesystem(new Adapter($base_path));

        if (count($out_errors)) {
            $error_string = implode("\r\n", $out_errors);
            $fly->delete('errors');
            $fly->put('errors', $error_string);
        } else {
            if ($fly->has('errors')) {
                $fly->delete('errors');
            }
        }
    }
}
