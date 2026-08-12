<?php

$config = [
    'version' => 'latest',
    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
];

$accessKeyId = (string) env('AWS_ACCESS_KEY_ID', '');
$secretAccessKey = (string) env('AWS_SECRET_ACCESS_KEY', '');

if (trim($accessKeyId) !== '' && trim($secretAccessKey) !== '') {
    $config['credentials'] = [
        'key' => $accessKeyId,
        'secret' => $secretAccessKey,
    ];
}

$s3Client = new \Aws\S3\S3Client($config);

$s3Service = new \PS\Webservice\Service\AWS\S3ManagerService($s3Client, env('AWS_BUCKET', 'dolcezampa'));