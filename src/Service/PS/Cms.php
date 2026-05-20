<?php
declare(strict_types=1);

namespace PS\Webservice\Service\PS;

class Cms extends PrestashopService implements PrestashopServiceInterface {

public function cmsList(array $params = []): \PS\Webservice\Service\HttpServiceInterface
    {
        $this->httpService->setUrl('/content_management_system?display=[id,meta_title,link_rewrite]');

        return $this->httpService->invoke('GET');
    }

    public function cmsDetail(int $id, array $params = []): \PS\Webservice\Service\HttpServiceInterface
    {
        $this->httpService->setUrl("/content_management_system/{$id}");

        return $this->httpService->invoke('GET', $params);
    }

    public function toPrestashop($path): \PS\Webservice\Service\HttpServiceInterface
    {
        $this->httpService->setUrl($path);
        return $this->httpService->invoke('GET');
    }
   
}
