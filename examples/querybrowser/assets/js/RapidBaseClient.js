/**
 * RapidBaseClient - Edición Mágica
 * 
 * Cliente ligero para la API v1 de RapidBase.
 * Utiliza Proxies de JavaScript para permitir llamadas dinámicas sin configuración previa.
 * 
 * @example
 * const api = new RapidBaseClient('api/v1/index.php');
 * 
 * // Llama automáticamente a: api/v1/index.php?ep=ConnectionManager&action=list
 * const connections = await api.connectionManager.list();
 * 
 * // Llama automáticamente a: api/v1/index.php?ep=HealthService&action=ping&connectionId=5
 * const status = await api.healthService.ping({ connectionId: 5 });
 */
class RapidBaseClient {
    /**
     * Crea una instancia del cliente.
     * @param {string} baseUrl - La ruta relativa o absoluta al endpoint index.php de la API.
     *                          Ej: 'api/v1/index.php'
     */
    constructor(baseUrl) {
        this.baseUrl = baseUrl;

        // Retornamos un Proxy que intercepta el acceso a cualquier propiedad (nombre del endpoint)
        return new Proxy({}, {
            get: (target, endpointName) => {
                // Formateamos el nombre del endpoint a PascalCase (ej: 'healthService' -> 'HealthService')
                // para que coincida con el nombre de la clase PHP.
                const endpointFormatted = String(endpointName).charAt(0).toUpperCase() + String(endpointName).slice(1);

                // Retornamos OTRO Proxy para interceptar el nombre del método (acción)
                return new Proxy({}, {
                    get: (_, methodName) => {
                        // Retornamos la función asíncrona que ejecutará la llamada real
                        return async (params = {}) => {
                            return this._executeRequest(endpointFormatted, methodName, params);
                        };
                    }
                });
            }
        });
    }

    /**
     * Ejecuta la petición HTTP real hacia la API.
     * @private
     * @param {string} endpoint - Nombre del Endpoint (Clase PHP).
     * @param {string} action - Nombre del método (Función PHP).
     * @param {object} params - Parámetros adicionales para enviar en la query string.
     * @returns {Promise<any>} - Los datos devueltos por la API (contenido de 'data').
     * @throws {Error} - Si la API devuelve success: false o hay error de red.
     */
    async _executeRequest(endpoint, action, params = {}) {
        // Construir los parámetros de la URL
        const urlParams = new URLSearchParams({
            ep: endpoint,
            action: action,
            ...params // Expandir los parámetros extra (ej: { connectionId: 5 })
        });

        try {
            const response = await fetch(`${this.baseUrl}?${urlParams.toString()}`, {
                method: 'GET',
                headers: { 
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                }
            });

            // Intentar parsear la respuesta como JSON
            let result;
            try {
                result = await response.json();
            } catch (e) {
                throw new Error(`Respuesta inválida del servidor (no es JSON): ${response.status}`);
            }

            // Si la API indica fallo lógico (success: false), lanzamos un error
            if (!result.success) {
                throw new Error(result.error || 'Error desconocido reportado por la API');
            }

            // Devolvemos solo el payload útil (lo que esté en 'data'), o el resultado completo si no hay 'data'
            return result.data !== undefined ? result.data : result;

        } catch (error) {
            // Logueamos el error detallado en consola para depuración
            console.error(`[RapidBaseClient] Error en ${endpoint}.${action}:`, error);
            // Re-lanzamos el error para que el componente UI lo maneje (mostrar alertas, etc.)
            throw error;
        }
    }
}

// Exportar globalmente para uso en navegador sin módulos ES6
if (typeof window !== 'undefined') {
    window.RapidBaseClient = RapidBaseClient;
}