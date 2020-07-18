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

class CheckShow extends Command {

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'proofgen:check-show';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check local photo count per class against website photo count.';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function fire()
    {
        ini_set('memory_limit', getenv('PHP_MEMORY_LIMIT'));

        $base_path      = getenv('FULLSIZE_HOME_DIR');

        $this->info('Listing shows on this drive...');
        $this->info('');

        $contents       = Utility::getContentsOfPath($base_path);
        $shows          = $contents['directories'];

        foreach($shows as $show)
        {
            $this->info($show['path']);
        }

        // Determine what show we're checking
        $show_slug      = $this->ask('What show do you want to check?');

        // Get website response
        $response       = file_get_contents('http://www.ferraraphoto.com/api/show/'.$show_slug.'/class-details');
        $response_array = json_decode($response);

        // Determine how many files we have locally
        $contents       = Utility::getContentsOfPath($base_path.'/'.$show_slug);
        $directories    = $contents['directories'];

        $local_array = [];
        foreach($directories as $class_path)
        {
            $class_path = $class_path['path'];
            // Determine how many photos there are in this class folder
            $class_contents = Utility::getContentsOfPath($base_path.'/'.$show_slug.'/'.$class_path);

            foreach($class_contents['directories'] as $class_directories)
            {
                $type = $class_directories['path'];

                // Get contents
                $class_directory_type_contents = Utility::getContentsOfPath($base_path.'/'.$show_slug.'/'.$class_path.'/'.$type);

                // Get a count of the images
                $image_count = count($class_directory_type_contents['images']);

                // Build the array
                $local_array[$class_path][$type] = $image_count;
            }
        }

        // Loop through local copy to determine if the proof count matches up with the originals count
        $this->info('Checking local file counts to ensure that the proof count matches up...');
        $this->info('');

        foreach($local_array as $class_slug => $count_array)
        {
            if(($count_array['originals'] * 2) !== $count_array['proofs'])
                $this->info('Class '. $class_slug.' has '.$count_array['originals'].' original images and '.$count_array['proofs'].' proofs. It should have '.($count_array['originals']*2).' proofs');
        }

        $this->info('');
        $this->info('Checking local proof count against website proof count to ensure all proofs are on website...');
        $this->info('');

        foreach($local_array as $class_slug => $count_array)
        {
            $local_proof_count = $count_array['proofs'] / 2;
            if(isset($response_array->$class_slug))
            {
                $remote_proof_count = $response_array->$class_slug;

                // Do the counts match?
                if($local_proof_count !== $remote_proof_count)
                {
                    $this->info('Class '.$class_slug.' has '.$local_proof_count.' local proofs and '.$remote_proof_count.' website proofs.');
                }
            }
            else
            {
                $this->info('Class '.$class_slug.' is not yet uploaded.');
            }

        }

        $this->info('');
    }
}
