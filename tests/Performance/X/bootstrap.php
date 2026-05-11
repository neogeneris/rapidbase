<?php

// Orden correcto de dependencias para cargar RapidBase sin el bundle
$base = __DIR__ . '/../../../src/RapidBase';

require_once "$base/Core/DBInterface.php";
require_once "$base/Core/Event.php";
require_once "$base/Core/Conn.php";
require_once "$base/Core/Cache/CacheService.php";
require_once "$base/Core/Cache/Adapters/DirectoryCacheAdapter.php";
require_once "$base/Core/Cache/CountCache.php";
require_once "$base/Core/SchemaMap.php";
require_once "$base/Core/SQL/ConditionMatrix.php";
require_once "$base/Core/SQL/CompiledQuery.php";
require_once "$base/Core/SQL/QType.php";
require_once "$base/Core/SQL/JoinResolver.php";
require_once "$base/Core/SQL/SqlCompiler.php";
require_once "$base/Core/SQL/Q.php";
require_once "$base/Core/Executor.php";
require_once "$base/Core/Gateway.php";
require_once "$base/Meta/Discovery/DiscoveryInterface.php";
require_once "$base/Meta/Discovery/FeatureDetector.php";
require_once "$base/Meta/Discovery/MySQLDiscovery.php";
require_once "$base/Meta/Discovery/PostgreSQLDiscovery.php";
require_once "$base/Meta/Discovery/SQLiteDiscovery.php";
require_once "$base/Meta/Discovery/DiscoveryFactory.php";
require_once "$base/Meta/SchemaMapper.php";
require_once "$base/Core/DB.php";
require_once "$base/Core/QueryResponse.php";
require_once "$base/Core/X.php";
require_once "$base/Core/XResponse.php";