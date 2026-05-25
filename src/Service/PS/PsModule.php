<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PS;

use PS\Webservice\Domain\Entities\FilterEntity;
use PS\Webservice\Domain\Entities\ProductEntity;
use PS\Webservice\Domain\Models\ProductLangTable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use PS\Webservice\Domain\Object\Filter;
use PS\Webservice\Service\HttpServiceInterface;

class PsModule extends PrestashopService implements PrestashopServiceInterface
{

    public function welcomeCoupon(array $payload): HttpServiceInterface
    {
        $this->httpService->setUrl(env('MODULES_WELCOME_COUPON'));
        return $this->httpService->invoke('POST', $payload);

    }

}