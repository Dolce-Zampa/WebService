<?php


$container->set(\PS\Webservice\Repositories\PrestashopRepository::class, function($c) use($capsule) {
    return new \PS\Webservice\Repositories\PrestashopRepository($capsule);
});

$container->set(\PS\Webservice\Repositories\ManufacturerRepository::class, function($c) use($capsule) {
    return new \PS\Webservice\Repositories\ManufacturerRepository($capsule);
});

