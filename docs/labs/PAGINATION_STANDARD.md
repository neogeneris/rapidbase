# Estándar de Paginación en RapidBase

## Principio Fundamental
En todo el ecosistema RapidBase, la paginación se maneja exclusivamente como:
```php
$page = [numero_pagina, registros_por_pagina];
// Ejemplo: [1, 10] significa "Página 1, mostrando 10 registros"
```

**NUNCA** se calcula el `OFFSET` manualmente antes de llamar a `DB::grid()`.
❌ Incorrecto: `$offset = ($page * $limit); DB::grid(..., [$offset, $limit]);`
✅ Correcto: `DB::grid(..., [$page, $limit]);`

La clase interna `SQL` es la responsable de transformar `[1, 10]` en `LIMIT 10 OFFSET 10`.

## Entrada Polimórfica
Los adaptadores y controladores deben aceptar entradas flexibles y normalizarlas al estándar interno:

| Entrada Usuario | Normalización Interna | Significado |
| :--- | :--- | :--- |
| `"1,10"` (String) | `[1, 10]` | Página 1, 10 regs |
| `[1, 10]` (Array) | `[1, 10]` | Página 1, 10 regs |
| `1` (Entero) | `[1, 50]` | Página 1, default 50 |
| `null` | `[0, 50]` | Página 0, default 50 |

## Flujo de Datos
1. **URL**: `GET /api/users?page=2,25`
2. **Parser**: Convierte string `"2,25"` → Array `[2, 25]`
3. **Adapter**: Pasa `[2, 25]` a `DB::grid('users', ..., [2, 25])`
4. **SQL Builder**: Genera `... LIMIT 25 OFFSET 50` (porque página 2 empieza en el registro 51)
5. **PDO**: Ejecuta con `FETCH_NUM`
6. **Response**: Devuelve `meta: { page: 2, per_page: 25 }`

## Por qué es importante
- **Abstracción**: El desarrollador piensa en "Páginas", no en "Bytes/Offsets".
- **Consistencia**: La UI (Grid.js, DataTables) y la API hablan el mismo idioma.
- **Optimización**: Evita cálculos redundantes de offsets en la capa de aplicación.
