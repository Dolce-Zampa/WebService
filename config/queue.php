<?php

$redisCli = new \App\Service\Providers\RedisCliProvider();
$queueService = new \PS\Webservice\Service\RedisQueue($redisCli->connection());