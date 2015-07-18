<?php namespace Proofgen;

use Illuminate\Console\Command;
use Intervention\Image\ImageManager;
use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local as Adapter;
use League\Flysystem\Sftp\SftpAdapter;

class Image {

    public static function processNewImage($class_path, $image, Command $terminal)
    {
        $home_dir           = getenv('FULLSIZE_HOME_DIR');
        $archive_base_path  = getenv('ARCHIVE_HOME_DIR');
        $full_class_path    = $class_path;
        $class_path         = str_replace($home_dir.'/', '', $class_path);

        $uri = explode('/', $class_path);
        if(count($uri) === 2)
        {
            $show = $uri[0];
            $class= $uri[1];

            $start          = microtime(true);
            $proof_number = self::generateProofNumber($show);
            $end            = microtime(true);
            $total          = number_format(($end - $start));
            $terminal->info($total.'s to determine the proof number.');

            $flysystem  = new Filesystem(new Adapter($full_class_path));
            $archive_fs = new Filesystem(new Adapter($archive_base_path));

            // Copy the file to originals path
            $start          = microtime(true);
            $terminal->info('Copying image...');
            $flysystem->copy($image['path'], 'originals/'.$image['path']);

            // Confirm the copy
            if($flysystem->has('originals/'.$image['path']))
            {
                $end            = microtime(true);
                $total          = number_format(($end - $start));
                $terminal->info($total.'s to copy image and confirm.');

                $terminal->info('Copy Confirmed.');
                // Rename the copied file to the proof number
                $proof_filename = $proof_number.'.'.strtolower($image['extension']);
                $terminal->info('Renaming copy to '.$proof_filename);

                $start          = microtime(true);
                $flysystem->rename('originals/'.$image['path'], 'originals/'.$proof_filename);

                // Confirm the rename
                if($flysystem->has('originals/'.$proof_filename))
                {
                    $end            = microtime(true);
                    $total          = number_format(($end - $start));
                    $terminal->info($total.'s to rename image and confirm.');

                    $start = microtime(true);
                    $terminal->info('Rename confirmed, copying to archive');
                    $archive_file_path  = $class_path.'/'.$proof_filename;
                    $image_data         = file_get_contents($home_dir.'/'.$class_path.'/originals/'.$proof_filename);

                    // Check for already existing image in the archive (left over from a previously failed run)
                    if( ! $archive_fs->has($archive_file_path))
                    {
                        $archive_fs->write($archive_file_path, $image_data);
                    }

                    $image_data = null;
                    unset($image_data);

                    if($archive_fs->has($archive_file_path))
                    {
                        $end            = microtime(true);
                        $total          = number_format(($end - $start));
                        $terminal->info($total.'s to check for existing image on archive, copy if not, and confirm.');

                        try{

                            $terminal->info('Memory used at start of thumbnails: '.self::convert(memory_get_usage(true)));
                            $terminal->info('Creating thumbnails...');
                            // Create the thumbnails if they're not already there

                            $start          = microtime(true);

                            self::checkImageForThumbnails($full_class_path, $proof_filename, $show, $class);

                            $end            = microtime(true);
                            $total          = number_format(($end - $start));
                            $terminal->info($total.'s to check for thumbnails and create if not existing.');

                            $terminal->info('Archived copy confirmed, thumbnails created, deleting original.');
                            // Delete the input file
                            $flysystem->delete($image['path']);

                        }
                        catch(ErrorException $e)
                        {
                            $terminal->info('Error creating thumbnails, resetting image.');

                            $temp_filename = 'temp'.rand(0,999999).'.jpg';

                            $flysystem->copy('originals/'.$proof_filename, $temp_filename);

                            $terminal->info('Confirming reset of image.');
                            if($flysystem->has($temp_filename))
                            {
                                $flysystem->delete('originals/'.$proof_filename);
                                $terminal->info('Original moved back to processing folder, ready to try again.');
                            }

                            dd('Execution stopped due to error.');
                        }

                        $flysystem = null;
                        $archive_fs = null;
                        unset($flysystem);
                        unset($archive_fs);

                        return $proof_filename;
                    }
                    else
                    {
                        dd('Archive copy failed');
                    }
                }
                else
                {
                    dd('File rename failed');
                }
            }
            else
            {
                dd('File copy failed');
            }

            $flysystem = null;
            $archive_fs = null;
            unset($flysystem);
            unset($archive_fs);
        }

        return false;
    }

    public static function checkImageForThumbnails($class_path, $proof_filename, $show, $class)
    {
        $lrg_suf            = getenv('LARGE_THUMBNAIL_SUFFIX');
        $sml_suf            = getenv('SMALL_THUMBNAIL_SUFFIX');
        $fullsize_path      = $class_path.'/originals/'.$proof_filename;
        $class_proofs_path  = $class_path.'/proofs';
        $image_name         = explode('.', $proof_filename);
        if(isset($image_name[0]) && isset($image_name[1]))
        {
            $image_filename     = $image_name[0];
            $image_ext          = $image_name[1];
        }
        else
            dd('couldnt parse image name');

        $flysystem          = new Filesystem(new Adapter($class_proofs_path));

        // Check for large thumbnail
        $large_thumb_filename = $image_filename.$lrg_suf.'.'.$image_ext;
        $small_thumb_filename = $image_filename.$sml_suf.'.'.$image_ext;

        $created = false;
        if(     ! $flysystem->has($large_thumb_filename)
            ||  ! $flysystem->has($small_thumb_filename))
        {
            // Doesn't exist, create it
            self::createThumbnails($fullsize_path, $class_proofs_path);
            $created = true;
        }
        $flysystem = null;
        unset($flysystem);

        return $created;
    }

    public static function generateProofNumber($show)
    {
        // Generate the path for this show
        $show_path = getenv('FULLSIZE_HOME_DIR').'/'.$show;
        // Get all the class folders
        $contents = Utility::getContentsOfPath($show_path);

        $images = [];
        if(count($contents['directories']) > 0)
        {
            // Cycle through the class folders
            foreach($contents['directories'] as $dir)
            {
                // Get the images from the 'originals' path, which will have renamed images.
                $class_contents = Utility::getContentsOfPath($show_path.'/'.$dir['path'].'/originals');
                $class_images = $class_contents['images'];

                // If there's images in here, drop them in the main images array
                if(count($class_images) > 0)
                {
                    foreach($class_images as $image)
                    {
                        $images[] = $image;
                    }
                }

                $image = null;
                $class_contents = null;
                $class_images = null;
                unset($image);
                unset($class_contents);
                unset($class_images);
            }
        }

        $image_numbers = [];
        if(count($images) > 0)
        {
            // Now we've got an array of images, we need to find the highest proof number of them all
            foreach($images as $img)
            {
                $num = $img['filename'];
                $num = str_replace(strtoupper($show).'_', '', $num);
                $image_numbers[] = $num;
            }

            rsort($image_numbers);
        }

        if(count($image_numbers) > 0)
        {
            $highest_number = $image_numbers[0];
        }
        else
            $highest_number = 0;

        $proof_num = $highest_number+1;
        $proof_num = str_pad($proof_num, 5, '0', STR_PAD_LEFT);

        $contents = null;
        unset($contents);

        return strtoupper($show).'_'.$proof_num;
    }

    public static function watermarkLargeProof($text, $width = 0)
    {
        $font_size          = getenv('LARGE_THUMBNAIL_FONT_SIZE');
        $background_height  = getenv('LARGE_THUMBNAIL_BG_SIZE');
        $foreground_opacity = getenv('WATERMARK_FOREGROUND_OPACITY');
        $background_opacity = getenv('WATERMARK_BACKGROUND_OPACITY');
        $im = imagettfJustifytext($text,'',2,$width,$background_height,0,0,$font_size, [255,255,255, $foreground_opacity], [0,0,0, $background_opacity]);
        return $im;
    }

    public static function watermarkSmallProof($text, $width = 0)
    {
        $font_size          = getenv('SMALL_THUMBNAIL_FONT_SIZE');
        $background_height  = getenv('SMALL_THUMBNAIL_BG_SIZE');
        $foreground_opacity = getenv('WATERMARK_FOREGROUND_OPACITY');
        $background_opacity = getenv('WATERMARK_BACKGROUND_OPACITY');
        $text = ' '.$text.' ';
        $im = imagettfJustifytext($text,'',2,$width,$background_height,0,0,$font_size, [255,255,255, $foreground_opacity], [0,0,0, $background_opacity]);
        return $im;
    }

    public static function createThumbnails($full_size_image_path, $proofs_dest_path)
    {
        $manager            = new ImageManager();

        $image              = $manager->make($full_size_image_path)->orientate();
        $lrg_suf            = getenv('LARGE_THUMBNAIL_SUFFIX');
        $sml_suf            = getenv('SMALL_THUMBNAIL_SUFFIX');
        $image_filename     = $image->filename;
        $large_thumb_filename = $image_filename.$lrg_suf.'.jpg';
        $small_thumb_filename = $image_filename.$sml_suf.'.jpg';

        // Save small thumbnail
        $image->resize(getenv('SMALL_THUMBNAIL_WIDTH'), getenv('SMALL_THUMBNAIL_HEIGHT'), function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        })->save($proofs_dest_path.'/'.$small_thumb_filename, getenv('SMALL_THUMBNAIL_QUALITY'));

        // Add watermark
        $image     = $manager->make($proofs_dest_path.'/'.$small_thumb_filename);
        $watermark = self::watermarkSmallProof($image_filename);
        $image->insert($watermark, 'bottom-left', 10, 10)->save();

        $watermark = null;
        $image = null;
        unset($watermark);
        unset($image);

        // Save large thumbnail
        $image = $manager->make($full_size_image_path)->orientate();
        $image->resize(getenv('LARGE_THUMBNAIL_WIDTH'), getenv('LARGE_THUMBNAIL_HEIGHT'), function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        })->save($proofs_dest_path.'/'.$large_thumb_filename, getenv('LARGE_THUMBNAIL_QUALITY'));

        $image = null;
        unset($image);

        // Add watermark
        $image = $manager->make($proofs_dest_path.'/'.$large_thumb_filename);

        if($image->width() > $image->height())
        {
            $text = 'Proof# '.$image_filename.' - Illegal to use - Ferrara Photography';
            $watermark = self::watermarkLargeProof($text, $image->width());
            $image->insert($watermark, 'center')->save();

            $watermark = null;
            unset($watermark);
        }
        else
        {
            $watermark_top = self::watermarkLargeProof('Proof# '.$image_filename.' - Proof# '.$image_filename, $image->width());
            $watermark_bot = self::watermarkLargeProof('Illegal to use - Ferrara Photography', $image->width());

            //$top_offset = round($image->height() * 0.2);
            //$bottom_offset = round($image->height() * 0.2);
            $bottom_offset = round($image->height() * 0.1);

            $image
                    //->insert($watermark_top, 'top', 0, $top_offset)
                    ->insert($watermark_top, 'center')
                    ->insert($watermark_bot, 'bottom', 0, $bottom_offset)
                    ->save();

            $watermark_top = null;
            $watermark_bot = null;
            unset($watermark_top);
            unset($watermark_bot);
        }

        echo 'Thumbnails created.'.PHP_EOL;

        $manager = null;
        $image = null;
        unset($manager);
        unset($image);

        echo 'Memory used at end of thumbnails:   '.self::convert(memory_get_usage(true)).PHP_EOL;

        return $image_filename;
    }

    public static function uploadThumbnails($upload)
    {
        $count = count($upload);
        echo 'Uploading '.$count.' thumbnails...'.PHP_EOL;

        // Connect to the remote server
        $remote_fs = new Filesystem(new SftpAdapter([
            'host'      => getenv('SFTP_HOSTNAME'),
            'port'      => 22,
            'username'  => getenv('SFTP_USERNAME'),
            'privateKey'=> getenv('SFTP_PATHTOPRIVATEKEY'),
            'root'      => getenv('SFTP_PROOFSPATH'),
            'timeout'   => 10,
        ]));

        $processed = 0;
        $total_upload_time = 0;
        foreach($upload as $up)
        {
            $start_time = microtime(true);
            $show_name = $up['show'];
            $class_name = $up['class'];
            $proof_number = explode('.', $up['file']);
            $proof_number = $proof_number[0];

            // Generate this photo's show/class path
            $remote_path = $show_name.'/'.$class_name;

            $lrg_suf            = getenv('LARGE_THUMBNAIL_SUFFIX');
            $sml_suf            = getenv('SMALL_THUMBNAIL_SUFFIX');
            $image_filename     = $proof_number;
            $large_thumb_filename = $image_filename.$lrg_suf.'.jpg';
            $small_thumb_filename = $image_filename.$sml_suf.'.jpg';
            $proofs_dest_path   = getenv('FULLSIZE_HOME_DIR').'/'.$show_name.'/'.$class_name.'/proofs';

            $small_thumbnail    = file_get_contents($proofs_dest_path.'/'.$small_thumb_filename);
            $large_thumbnail    = file_get_contents($proofs_dest_path.'/'.$large_thumb_filename);

            // Copy the files from local to remote
            $remote_fs->put($remote_path.'/'.$small_thumb_filename, $small_thumbnail);
            $remote_fs->put($remote_path.'/'.$large_thumb_filename, $large_thumbnail);

            $small_thumbnail = null;
            unset($small_thumbnail);
            $large_thumbnail = null;
            unset($large_thumbnail);

            $end_time = microtime(true);
            $upload_time = number_format(($end_time - $start_time));
            $total_upload_time = $total_upload_time + $upload_time;
            $processed++;

            echo $proof_number.' uploaded in '.$upload_time.' (s) ('.$processed.'/'.$count.')'.PHP_EOL;

        }

        echo $count.' Thumbnails uploaded to remote server in '.$total_upload_time.' seconds '.PHP_EOL;
    }

    public static function convert($size)
    {
        $unit=array('b','kb','mb','gb','tb','pb');
        return @round($size/pow(1024,($i=floor(log($size,1024)))),2).' '.$unit[$i];
    }

}

/**
 * @name                    : makeImageF
 *
 * Function for create image from text with selected font.
 *
 * @param String $text     : String to convert into the Image.
 * @param String $font     : Font name of the text. Kip font file in same folder.
 * @param int    $justify  : Justify text in image (0-Left, 1-Right, 2-Center)
 * @param int    $W        : Width of the Image.
 * @param int    $H        : Height of the Image.
 * @param int    $X        : x-coordinate of the text into the image.
 * @param int    $Y        : y-coordinate of the text into the image.
 * @param int    $fsize    : Font size of text.
 * @param array  $color       : RGB color array for text color.
 * @param array  $bgcolor  : RGB color array for background.
 *
 * @return resource $im
 */
function imagettfJustifytext($text, $font="CENTURY.TTF", $justify=2, $W=0, $H=0, $X=0, $Y=0, $fsize=12, $color=array(0x0,0x0,0x0,1), $bgcolor=array(0xFF,0xFF,0xFF,1)){

    $font = getenv('WATERMARK_FONT');

    $angle = 0;
    $L_R_C = $justify;
    $_bx = imageTTFBbox($fsize,0,$font,$text);

    $W = ($W==0)?abs($_bx[2]-$_bx[0]):$W;    //If Height not initialized by programmer then it will detect and assign perfect height.
    $H = ($H==0)?abs($_bx[5]-$_bx[3]):$H;    //If Width not initialized by programmer then it will detect and assign perfect width.

    $im = @imagecreate($W, $H)
    or die("Cannot Initialize new GD image stream");


    $background_color = imagecolorallocatealpha($im, $bgcolor[0], $bgcolor[1], $bgcolor[2], $bgcolor[3]);        //RGB color background.
    $text_color = imagecolorallocatealpha($im, $color[0], $color[1], $color[2], $color[3]);            //RGB color text.

    if($L_R_C == 0){ //Justify Left

        imagettftext($im, $fsize, $angle, $X, $fsize, $text_color, $font, $text);

    }elseif($L_R_C == 1){ //Justify Right
        $s = explode("[\n]+", $text);
        $__H=0;

        foreach($s as $key=>$val){

            $_b = imageTTFBbox($fsize,0,$font,$val);
            $_W = abs($_b[2]-$_b[0]);
            //Defining the X coordinate.
            $_X = $W-$_W;
            //Defining the Y coordinate.
            $_H = abs($_b[5]-$_b[3]);
            $__H += $_H;
            imagettftext($im, $fsize, $angle, $_X, $__H, $text_color, $font, $val);
            $__H += 6;

        }

    }
    elseif($L_R_C == 2){ //Justify Center

        $s = explode("[\n]+", $text);
        $__H=0;

        foreach($s as $key=>$val){

            $_b = imageTTFBbox($fsize,0,$font,$val);
            $_W = abs($_b[2]-$_b[0]);
            //Defining the X coordinate.
            $_X = abs($W/2)-abs($_W/2);
            //Defining the Y coordinate.
            $_H = abs($_b[5]-$_b[3]);
            $__H += $_H;
            imagettftext($im, $fsize, $angle, $_X, $__H, $text_color, $font, $val);
            $__H += 6;

        }

    }

    return $im;

}
