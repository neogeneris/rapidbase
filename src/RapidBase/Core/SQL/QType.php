<?php

namespace RapidBase\Core\SQL;

/**
 * Constantes de operación para máxima velocidad en el switch interno.
 * El uso de enteros es más rápido que comparar strings.
 */
class QType
{
    public const SELECT = 1;
    public const INSERT = 2;
    public const UPDATE = 3;
    public const DELETE = 4;
    public const COUNT  = 5;
    public const EXISTS = 6;
}
