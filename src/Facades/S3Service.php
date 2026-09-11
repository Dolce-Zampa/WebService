<?php

namespace PS\Webservice\Facades;

use Illuminate\Support\Facades\Facade;


/**
 * This class represents a facade for interacting with the AWS S3 service.
 * 
 * @method bool uploadFile(string $key, string $filePath)
 * @method bool deleteFile(string $key)
 * @method string uploadAvatarToS3(\Psr\Http\Message\UploadedFileInterface $uploadedFile, string $fileName)
 * 
 * @see \PS\Webservice\Service\AWS\S3ManagerService
 */
class S3Service extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'aws-s3-service';
    }
}
