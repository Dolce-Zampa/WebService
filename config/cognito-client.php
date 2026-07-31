<?php
$config = [
    'region' => env('AWS_COGNITO_REGION','eu-west-1'),
    'version' => env('AWS_COGNITO_VERSION','latest'),
    'app_client_id' => env('AWS_COGNITO_CLIENT_ID', ''),
    'app_client_secret' => env('AWS_COGNITO_CLIENT_SECRET', ''),
    'user_pool_id' => env('AWS_COGNITO_USER_POOL_ID', ''),
    'redirect_uri' => env('AWS_COGNITO_REDIRECT_URI', ''),
];

if(env('AWS_COGNITO_ACCESS_KEY_ID') && env('AWS_COGNITO_SECRET_ACCESS_KEY')) {
    $config['credentials'] = [
        'key' => env('AWS_COGNITO_ACCESS_KEY_ID'),
        'secret' => env('AWS_COGNITO_SECRET_ACCESS_KEY'),
    ];
}

if(!env('AWS_COGNITO_CLIENT_ID') || !env('AWS_COGNITO_CLIENT_SECRET') || !env('AWS_COGNITO_USER_POOL_ID')) {
    throw new \Exception("Missing AWS Cognito configuration. Please check your .env file.");
}
 
$aws = new \Aws\Sdk($config);
$cognitoClient = $aws->createCognitoIdentityProvider();

$awsCognitoClient = new \malirobot\AwsCognito\CognitoClient($cognitoClient);
$awsCognitoClient->setAppClientId($config['app_client_id']);
$awsCognitoClient->setAppClientSecret($config['app_client_secret']);
$awsCognitoClient->setRegion($config['region']);
$awsCognitoClient->setUserPoolId($config['user_pool_id']);
$awsCognitoClient->setAppName(env('APP_NAME'));
$awsCognitoClient->setAppRedirectUri($config['redirect_uri']);

$awsCognitoClient;
