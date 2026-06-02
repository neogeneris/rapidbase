<?php

$srcDir = __DIR__ . '/../src/RapidBase';
$outputFile = __DIR__ . '/RapidBase.php';

echo "Buscando archivos en $srcDir...\n";

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));
$interfaceFiles = [];
$otherFiles = [];

foreach ($iterator as $file) {
    // Excluir archivos schema_map.php
    if (basename($file) === 'schema_map.php') {
        continue;
    }

    // Excluir toda la carpeta Tdd (funciona en Windows y Linux)
    $fullPath = str_replace('\\', '/', $file->getPathname());
    if (strpos($fullPath, '/Tdd/') !== false) {
        continue;
    }

    if ($file->isFile() && $file->getExtension() === 'php') {
        $path = $file->getPathname();
        // Detectar si es una interfaz (está en Contracts o el nombre del archivo sugiere interfaz)
        if (strpos($fullPath, '/Contracts/') !== false) {
            $interfaceFiles[] = $path;
        } else {
            $otherFiles[] = $path;
        }
    }
}

// Ordenar interfaces primero para asegurar que se definan antes de usarse
sort($interfaceFiles);
sort($otherFiles);

// Combinar: interfaces primero, luego el resto
$phpFiles = array_merge($interfaceFiles, $otherFiles);

echo "Encontrados " . count($phpFiles) . " archivos (" . count($interfaceFiles) . " interfaces, " . count($otherFiles) . " clases).\n";

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