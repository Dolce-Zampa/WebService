<?php

namespace PS\Webservice\Facades;

use Illuminate\Support\Facades\Facade;


/**
 * This class represents a facade for interacting with the AWS S3 service.
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
