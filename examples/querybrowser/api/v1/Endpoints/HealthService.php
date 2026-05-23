<?php

namespace RapidBase\Endpoints;

use RapidBase\Api\BaseEndpoint;
use RapidBase\Core\X;

class HealthService extends BaseEndpoint
{
    /**
     * Real ping a la conexión guardada.
     */
    public function ping(string $connectionId): array
    {
        // El connectionId ya viene normalizado (nombre de conexión)
        $key = $connectionId;

        // Si la conexión no está activa, intentamos cargarla bajo demanda
        if (!isset($this->context->session['connections'][$key])) {
            // Podrías añadir lógica para activarla desde la BD de control,
            // pero por ahora devolvemos error amigable.
            return [
                'success' => false,
                'error'   => 'Connection not active. Please open it first.',
            ];
        }

        // Activar la conexión (igual que activateConnection en api.php)
        $c = $this->context->session['connections'][$key];
        \RapidBase\Core\DB::setup($c['dsn'], $c['user'] ?? '', $c['pass'] ?? '', $key);
        \RapidBase\Core\SchemaMap::setMap($c['map'], $key);
        \RapidBase\Core\SQL\ConditionMatrix::setDriver($c['map']['driver']);

        $res = X::con($key)->ping();

        return [
            'success' => $res['success'],
            'latency' => $res['latency'] ?? 0,
            'error'   => $res['error'] ?? null,
        ];
    }
}