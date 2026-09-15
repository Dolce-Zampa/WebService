<?php

$redisCli = new PS\Webservice\Service\Providers\RedisCliProvider();
$queueService = new \PS\Webservice\Service\RedisQueue($redisCli->connection());