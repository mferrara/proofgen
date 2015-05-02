<?php namespace Proofgen;


use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local as Adapter;

class Utility {

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