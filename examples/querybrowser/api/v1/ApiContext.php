<?php

namespace RapidBase\Api;

class ApiContext {
    public function __construct(
        public array $params = [],  // Equivalente a $_REQUEST
        public array $session = [], // Datos de sesión simulados o reales
        public array $auth = []     // Metadatos de autenticación/roles
    ) {}
}
