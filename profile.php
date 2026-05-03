<?php
require 'vendor/autoload.php';
use RapidBase\Core\DB;

DB::setup('sqlite::memory:', '', '', 'main');
DB::exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');

$start = microtime(true);
for($i=0;$i<100;$i++){
    \RapidBase\Core\Gateway::select('*', 'users', [], [], [], [], [1, 50]);
}
$end = microtime(true);
echo 'Total: ' . (($end-$start)*1000) . " ms\n";
echo 'Avg: ' . (($end-$start)*1000/100) . " ms\n";
