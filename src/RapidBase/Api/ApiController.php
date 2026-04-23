<?php

namespace RapidBase\Api;

use RapidBase\Core\Gateway;
use RapidBase\Core\SQL;

class ApiController {

    public function get($f3, $params) {
        $table = $params['table'];
        $action = $f3->get('GET.action') ?: 'list';

        // 1. Validación: ¿La tabla existe en nuestro esquema de Venon?
        $reflect = new \ReflectionClass(SQL::class);
        $prop = $reflect->getProperty('schema');
        $prop->setAccessible(true);
        $schema = $prop->getValue();

        if (!isset($schema[$table])) {
            $f3->error(404, "La tabla [$table] no está registrada en Venon.");
            return;
        }

        header('Content-Type: application/json');

        try {
            switch ($action) {
                case 'list':
                    $this->handleList($f3, $table);
                    break;
                case 'get':
                    $this->handleGetOne($f3, $table);
                    break;
                case 'delete':
                    $this->handleDelete($f3, $table);
                    break;
                case 'create':
                    $this->handleCreate($f3, $table);
                    break;
                case 'update':
                    $this->handleUpdate($f3, $table);
                    break;
                default:
                    $f3->error(400, "Acción '$action' no soportada.");
            }
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    private function handleList($f3, $table) {
        // Soporte para paginación con offset (Grid.js) o page (estándar)
        $limit = (int)($f3->get('GET.limit') ?: 10);
        $offset = $f3->get('GET.offset');
        
        if ($offset !== null) {
            $page = ((int)$offset / $limit) + 1;
        } else {
            $page = (int)($f3->get('GET.page') ?: 1);
        }

        $sortRaw = $f3->get('GET.sort') ?: '';
        
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

        // Parámetros correctos: fields, table, where, groupBy, having, sort, page, perPage, withTotal, useFetchNum
        $result = Gateway::select('*', $table, [], [], [], $sort, $page, $limit, true, false);

        // Adaptar salida para Grid.js
        $output = [
            'data' => $result['data'],
            'total' => $result['total']
        ];
        
        echo json_encode($output);
    }

    private function handleGetOne($f3, $table) {
        $id = $f3->get('GET.id');
        if (!$id) {
            throw new \Exception("ID requerido");
        }

        // Usar FETCH_ASSOC (último parámetro false = no usa FETCH_NUM)
        // Parámetros: fields, table, where, groupBy, having, sort, page, perPage, withTotal, useFetchNum
        $result = Gateway::select('*', $table, ['id = ?' => $id], [], [], [], 1, 1, false, false);
        
        if (empty($result['data'])) {
            throw new \Exception("Registro no encontrado");
        }

        echo json_encode($result['data'][0]);
    }

    private function handleDelete($f3, $table) {
        // Leer JSON del body o parámetros GET/POST
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? $f3->get('POST.id') ?? $f3->get('GET.id');
        
        if (!$id) {
            throw new \Exception("ID requerido para eliminar");
        }

        $affected = Gateway::delete($table, ['id = ?' => $id]);
        
        echo json_encode([
            'success' => $affected > 0,
            'deleted' => $affected
        ]);
    }

    private function handleCreate($f3, $table) {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            throw new \Exception("Datos requeridos");
        }

        $id = Gateway::insert($table, $input);
        
        echo json_encode([
            'success' => true,
            'id' => $id
        ]);
    }

    private function handleUpdate($f3, $table) {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? null;
        
        if (!$id) {
            throw new \Exception("ID requerido para actualizar");
        }

        unset($input['id']); // No actualizar el ID
        $affected = Gateway::update($table, $input, ['id = ?' => $id]);
        
        echo json_encode([
            'success' => $affected > 0,
            'updated' => $affected
        ]);
    }
}
