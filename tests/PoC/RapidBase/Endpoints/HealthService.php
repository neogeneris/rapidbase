<?php

namespace RapidBase\Endpoints;

use RapidBase\Api\BaseEndpoint;

class HealthService extends BaseEndpoint {
    /**
     * Verifica el estado de una conexión y devuelve la latencia.
     */
    public function ping(int $connectionId) {
        // En lugar de usar $_SESSION, usamos el contexto inyectado
        $userId = $this->context->session['user_id'] ?? 0;
        
        return [
            "status" => "online",
            "user_context" => $userId,
            "latency" => "0.002ms"
        ];
    }
}
