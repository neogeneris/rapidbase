<?php

$srcDir = __DIR__ . '/src/RapidBase';
$outputFile = __DIR__ . '/RapidBase.php';

echo "Buscando archivos en $srcDir...\n";

// Usamos RecursiveDirectoryIterator para encontrar todos los archivos PHP
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));
$phpFiles = [];

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $phpFiles[] = $file->getPathname();
    }
}

echo "Encontrados " . count($phpFiles) . " archivos.\n";

// El contenido final comenzará con la etiqueta PHP y strict_types
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

// Asegurarse de que no haya múltiples declaraciones de namespace sin llaves mezcladas de forma incorrecta,
// aunque PHP soporta multiples `namespace X;` si están bien separados.

file_put_contents($outputFile, $finalContent);

echo "Archivo crudo generado. Minificando...\n";

// Minificar usando el parser interno de PHP (seguro contra strings/regex erróneos)
$minified = php_strip_whitespace($outputFile);

// Volver a colocar la cabecera limpia
$minified = preg_replace('/^\s*<\?php\s*/', '', $minified);
$header = "<?php\n/**\n * RapidBase - Bundled & Minified\n * Generated on " . date('Y-m-d H:i:s') . "\n */\n";

file_put_contents($outputFile, $header . "\n" . $minified);

echo "¡Construcción y minificación completadas! Archivo generado en: $outputFile\n";
echo "Tamaño del archivo minificado: " . number_format(filesize($outputFile) / 1024, 2) . " KB\n";
