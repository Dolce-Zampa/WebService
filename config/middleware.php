<?php

// ##### APPLICATION MIDDLEWARE ######
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

/**
 * error middleware
 */
$errorMiddleware = $app->addErrorMiddleware(true, true, true, $logger);
$errorMiddleware->setDefaultErrorHandler(function (\Slim\Http\ServerRequest $request, Throwable $e) use ($logger, $app) {

    $procId = uniqid();
    $logger->error('Unhandled request error', [
        'exception_type' => get_class($e),
        'procid' => $procId,
    ]);

    $statusError = [
        'id' => $procId,
        'status' => empty($e->getCode()) ? 500 : $e->getCode(),
        'detail' => $e instanceof \PS\Webservice\Service\PS\PrestashopConnectorException
            ? $e->getMessage()
            : ($e instanceof \InvalidArgumentException ? $e->getMessage() : 'An internal error occurred.')
    ];

    $payload = ['error' => $statusError];
    $code = (int) $e->getCode();
    if ($code < 100 || $code > 599) {
        $code = 500;
    }

    return response($payload, $code);
});
