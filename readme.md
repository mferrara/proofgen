# Proof management for ferraraphoto.com

## Process new images found:

Processes all new images found in the base show/class directories. The images will be renamed, thumbnailed, watermarked and uploaded.

php artisan proofgen:process

## Rebuild thumbnails/upload to website

This will remove all existing thumbnails, re-producing and uploading them

php artisan proofgen:regenerate

## Process all errors, attempting to complete the failed jobs

php artisan proofgen:errors