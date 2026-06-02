<?php

$srcDir = __DIR__ . '/../src/RapidBase';
$outputFile = __DIR__ . '/RapidBase.php';

echo "Buscando archivos en $srcDir...\n";

// Orden explícito de interfaces para garantizar dependencias correctas
$interfaceOrder = [
    'KeyValueReaderInterface.php',
    'KeyValueWriterInterface.php',
    'KeyValueInterface.php',
    'CacheInterface.php'
];

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));
$orderedInterfaces = [];
$otherFiles = [];

// 1. Buscar primero las interfaces específicas en el orden definido
$contractsDir = $srcDir . '/Core/Contracts';
if (is_dir($contractsDir)) {
    foreach ($interfaceOrder as $interfaceFile) {
        $fullPath = $contractsDir . '/' . $interfaceFile;
        if (file_exists($fullPath)) {
            $orderedInterfaces[] = $fullPath;
        }
    }
    
    // 2. Capturar cualquier otra interfaz en Contracts que no esté en la lista explícita
    $contractsIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($contractsDir));
    foreach ($contractsIterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $path = $file->getPathname();
            $fileName = $file->getFilename();
            if (!in_array($fileName, $interfaceOrder)) {
                $orderedInterfaces[] = $path;
            }
        }
    }
}

// 3. Capturar el resto de archivos (excluyendo Contracts, Tdd y schema_map)
foreach ($iterator as $file) {
    // Excluir archivos schema_map.php
    if (basename($file) === 'schema_map.php') {
        continue;
    }

    // Excluir toda la carpeta Tdd
    $fullPath = str_replace('\\', '/', $file->getPathname());
    if (strpos($fullPath, '/Tdd/') !== false) {
        continue;
    }
    
    // Excluir carpeta Contracts (ya procesada arriba)
    if (strpos($fullPath, '/Contracts/') !== false) {
        continue;
    }

    if ($file->isFile() && $file->getExtension() === 'php') {
        $otherFiles[] = $file->getPathname();
    }
}

// Ordenar el resto de archivos alfabéticamente
sort($otherFiles);

// Combinar: interfaces en orden específico primero, luego el resto
$phpFiles = array_merge($orderedInterfaces, $otherFiles);

echo "Encontrados " . count($phpFiles) . " archivos (" . count($orderedInterfaces) . " interfaces, " . count($otherFiles) . " clases).\n";

$finalContent = "<?php\n\n/**\n * RapidBase - Bundled single file\n * Generated on " . date('Y-m-d H:i:s') . "\n */\n\ndeclare(strict_types=1);\n\n";

foreach ($phpFiles as $file) {
    $content = file_get_contents($file);

    // 1. Quitar el tag <?php
    $content = preg_replace('/^\s*<\?php\s*/', '', $content);
    
    // 2. Localizar y quitar declare(strict_types=1); porque ya está arriba
    $content = preg_replace('/declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/i', '', $content);
    
    // 3. Comentar los require_once / include que apuntan a cosas internas
    $content = preg_replace('/^\s*(require_once|require|include_once|include)\s+[^;]+;/m', '/* $0 */', $content);

    $finalContent .= "// --- START: " . str_replace(__DIR__, '', $file) . " ---\n";
    $finalContent .= trim($content) . "\n\n";
    $finalContent .= "// --- END: " . str_replace(__DIR__, '', $file) . " ---\n\n";
}

file_put_contents($outputFile, $finalContent);

echo "Archivo crudo generado. Minificando...\n";

$minified = php_strip_whitespace($outputFile);
$minified = preg_replace('/^\s*<\?php\s*/', '', $minified);
$header = "<?php\n/**\n * RapidBase - Bundled & Minified\n * Generated on " . date('Y-m-d H:i:s') . "\n */\n";

file_put_contents($outputFile, $header . "\n" . $minified);

echo "¡Construcción y minificación completadas! Archivo generado en: $outputFile\n";
echo "Tamaño del archivo minificado: " . number_format(filesize($outputFile) / 1024, 2) . " KB\n";