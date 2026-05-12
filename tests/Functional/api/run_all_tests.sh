#!/bin/bash
# Script para ejecutar todas las pruebas del API en orden
# Uso: ./run_all_tests.sh

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API_DIR="/workspace/examples/querybrowser"
PHP_SERVER_PID=""

echo "=========================================="
echo "  Suite de Pruebas Funcionales - API"
echo "=========================================="
echo ""

# Función para limpiar al finalizar
cleanup() {
    echo ""
    echo "Deteniendo servidor PHP..."
    if [ ! -z "$PHP_SERVER_PID" ]; then
        kill $PHP_SERVER_PID 2>/dev/null
    fi
    # Ejecutar prueba de limpieza
    echo "Ejecutando limpieza..."
    php "$SCRIPT_DIR/10.limpieza.test.php"
}

trap cleanup EXIT

# Iniciar servidor PHP en background
echo "Iniciando servidor PHP en puerto 8000..."
cd "$API_DIR"
php -S localhost:8000 > /dev/null 2>&1 &
PHP_SERVER_PID=$!
sleep 2

# Verificar que el servidor esté corriendo
if ! kill -0 $PHP_SERVER_PID 2>/dev/null; then
    echo "FAIL: No se pudo iniciar el servidor PHP"
    exit 1
fi

echo "Servidor iniciado (PID: $PHP_SERVER_PID)"
echo ""

# Lista de pruebas en orden
TESTS=(
    "1.conectar.test.php"
    "2.listar_conexiones.test.php"
    "3.agregar_conexion.test.php"
    "4.probar_conexion.test.php"
    "5.listar_tablas.test.php"
    "6.ejecutar_consulta.test.php"
    "7.grid_data.test.php"
    "8.auto_query.test.php"
    "9.descripcion_tablas.test.php"
)

PASSED=0
FAILED=0

# Ejecutar cada prueba
for test in "${TESTS[@]}"; do
    echo "----------------------------------------"
    echo "Ejecutando: $test"
    echo "----------------------------------------"
    
    if php "$SCRIPT_DIR/$test"; then
        ((PASSED++))
        echo ""
    else
        ((FAILED++))
        echo ""
        echo "!!! FALLÓ: $test"
        echo ""
        # Continuar con las siguientes pruebas
    fi
done

echo "=========================================="
echo "  Resultados Finales"
echo "=========================================="
echo "Pruebas pasadas: $PASSED"
echo "Pruebas fallidas: $FAILED"
echo "Total: $((PASSED + FAILED))"
echo "=========================================="

if [ $FAILED -gt 0 ]; then
    exit 1
fi

exit 0
