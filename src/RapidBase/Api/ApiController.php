<?php

namespace RapidBase\Api;

use RapidBase\Core\Gateway;
use RapidBase\Core\SQL;

class ApiController {

    public function get($f3, $params) {
        $table = $params['table'];

        // 1. Validación: ¿La tabla existe en nuestro esquema de Venon?
        // Usamos una pequeña reflexión para leer la propiedad privada 'schema'
        $reflect = new \ReflectionClass(SQL::class);
        $prop = $reflect->getProperty('schema');
        $prop->setAccessible(true);
        $schema = $prop->getValue();

        if (!isset($schema[$table])) {
            $f3->error(404, "La tabla [$table] no está registrada en Venon.");
            return;
        }

        // 2. Captura de parámetros de la URL
        $page = $f3->get('GET.page') ?: 1;
        $limit = $f3->get('GET.limit') ?: 20;
        $sortRaw = $f3->get('GET.sort') ?: ''; // Ej: -id,nombre
        
        // Procesar el sort con la lógica de +/- que hablamos
        $sort = [];
        if ($sortRaw) {
            foreach (explode(',', $sortRaw) as $s) {
                $s = trim($s);
                if (str_starts_with($s, '-')) {
                    $sort[ltrim($s, '-')] = 'DESC';
                } else {
                    $sort[$s] = 'ASC';
                }
            }
        }

        // 3. Ejecución en el Gateway
        // El último parámetro 'true' es para que nos devuelva el conteo total (paginación)
        $result = Gateway::select('*', $table, [], $sort, $page, $limit, true);

        // 4. Respuesta JSON
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'meta' => [
                'table' => $table,
                'total' => $result['total'],
                'page' => $page,
                'limit' => $limit
            ],
            'data' => $result['data']
        ]);
    }
}
