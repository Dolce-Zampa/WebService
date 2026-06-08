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

    public function toPrestashop(string $path, string $method = 'GET', array $payload = []): \PS\Webservice\Service\HttpServiceInterface
    {
        $this->httpService->setUrl("/password-reset?no_cache=1");

        if (!empty($payload)) {
            return $this->httpService->invoke($method, $payload);
        }

        return $this->httpService->invoke($method);
    }
   
}
