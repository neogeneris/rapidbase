<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('ROOT_PATH', __DIR__);
define('DATA_PATH', ROOT_PATH . '/data');
define('CONNECTIONS_DB', ROOT_PATH . '/connections.sqlite'); // BD interna

require_once ROOT_PATH . '/RapidBase.php';

use RapidBase\Core\SQL\ConditionMatrix;
ConditionMatrix::setDriver('sqlite');

// Función auxiliar para debug
function debug($data) {
    echo '<pre>';
    var_dump($data);
    echo '</pre>';
}