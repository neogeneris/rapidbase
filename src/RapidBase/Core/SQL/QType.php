<?php

namespace RapidBase\Core\SQL;

/**
 * Tipos de consultas como constantes enteras para máximo rendimiento.
 */
class QType {
    const SELECT = 1;
    const INSERT = 2;
    const UPDATE = 3;
    const DELETE = 4;
    const COUNT = 5;
    const EXISTS = 6;
}

