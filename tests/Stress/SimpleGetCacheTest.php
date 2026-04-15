<?php

use RapidBase\Core\Cache\Adapters\DirectoryCacheAdapter;


include_once("../../src/RapidBase/Core/CacheInterface.php");
include_once("../../src/RapidBase/Core/Cache/Adapters/DirectoryCacheAdapter.php");
$tmpPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR;
if (!is_dir($tmpPath)) {
    mkdir($tmpPath, 0777, true);
}
$cache = new DirectoryCacheAdapter($tmpPath);
$dummy = $cache->get('bench_countries');
