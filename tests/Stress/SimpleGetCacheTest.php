<?php

use Core\Cache\Adapters\DirectoryCacheAdapter;


include_once("../../src/Core/CacheInterface.php");
include_once("../../src/Core/Cache/Adapters/DirectoryCacheAdapter.php");
$tmpPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR;
if (!is_dir($tmpPath)) {
    mkdir($tmpPath, 0777, true);
}
$cache = new DirectoryCacheAdapter($tmpPath);
$dummy = $cache->get('bench_countries');
