<?php

namespace Core\Cache\Adapters;

use Core\CacheInterface;
use ZipArchive;

class ZipCacheAdapter implements CacheInterface {
    private string $basePath;

    public function __construct(string $path) {
        $this->basePath = rtrim($path, '/') . '/';
    }

    public function get(string $table, string $key): mixed {
        $zipFile = $this->basePath . $table . '.zip';
        if (!file_exists($zipFile)) return null;

        $zip = new ZipArchive();
        if ($zip->open($zipFile) === true) {
            $content = $zip->getFromName($key . '.php');
            $zip->close();

            if ($content !== false) {
                // Quitamos el '<?php' inicial para evaluarlo de forma segura
                $cleanContent = str_replace('<?php', '', $content);
                return eval($cleanContent);
            }
        }
        return null;
    }

    public function set(string $table, string $key, mixed $data): bool {
        $zipFile = $this->basePath . $table . '.zip';
        $content = "<?php return " . var_export($data, true) . ";";

        $zip = new ZipArchive();
        $mode = file_exists($zipFile) ? ZipArchive::CHECKCONS : ZipArchive::CREATE;
        
        if ($zip->open($zipFile, $mode) === true) {
            $zip->addFromString($key . '.php', $content);
            $zip->close();
            return true;
        }
        return false;
    }

    public function invalidate(string $table): void {
        $zipFile = $this->basePath . $table . '.zip';
        if (file_exists($zipFile)) unlink($zipFile);
    }

    public function exists(string $table, string $key): bool {
        $zipFile = $this->basePath . $table . '.zip';
        if (!file_exists($zipFile)) return false;

        $zip = new ZipArchive();
        $res = false;
        if ($zip->open($zipFile) === true) {
            $res = $zip->locateName($key . '.php') !== false;
            $zip->close();
        }
        return $res;
    }
}