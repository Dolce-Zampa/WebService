<?php
// Autoload Composer dependencies
use \Illuminate\Support\Carbon as Date;
use Illuminate\Support\Facades\Facade;

require_once __DIR__ . '/../vendor/autoload.php';

\Sentry\init([
  'dsn' => 'https://ef5aad4b4761f3298bb76380951a20c3@o4511381519204352.ingest.de.sentry.io/4511394505097296',
]);

// Set up your application configuration
// Initialize slim application
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Crea un'istanza del gestore del database (Capsule)
$capsule = new \Illuminate\Database\Capsule\Manager();

// Aggiungi la configurazione del database al Capsule
$connections = require_once __DIR__.'/../config/database.php';
$capsule->addConnection($connections['mysql']);

// Esegui il boot del Capsule
$capsule->bootEloquent();
$capsule->setAsGlobal();

// validator laravel
$validator = new \Illuminate\Validation\Validator(
    new \Illuminate\Translation\Translator(
        new \Illuminate\Translation\ArrayLoader(),
        'en'
    ),
    [],
    []
);

// Set up the logger
require_once __DIR__ . '/../config/logger.php';

// Set up the dependency injection container
require_once __DIR__ . '/../config/container-di.php';

require_once __DIR__ . '/../config/cache.php';

// cognito
require_once __DIR__ . '/../config/cognito-client.php';

// Set up the Facade application
Facade::setFacadeApplication([
    'log' => $logger,
    'date' => new Date(),
    'cache' => $cache,
    'aws-cognito-client' => $awsCognitoClient,
    'validator' => $validator,
    ]);
