<?php

$s3Client = new \Aws\S3\S3Client([
    'version' => 'latest',
    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    'credentials' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
    ],
]);

$s3Service = new \PS\Webservice\Service\AWS\S3ManagerService($s3Client, env('AWS_BUCKET'));

//test put object
$s3Service->uploadFile('Hello, S3!', 'test.txt');