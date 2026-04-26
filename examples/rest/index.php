<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RapidBase REST Adapter Demo</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; color: #333; line-height: 1.6; }
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        h1 { margin-bottom: 20px; color: #2c3e50; }
        .grid { display: grid; grid-template-columns: 350px 1fr; gap: 20px; }
        .panel { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: 600; color: #555; }
        input[type="text"], input[type="number"], select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
        input[type="checkbox"] { margin-right: 8px; }
        button { width: 100%; padding: 12px; background: #3498db; color: white; border: none; border-radius: 4px; font-size: 16px; cursor: pointer; transition: background 0.2s; }
        button:hover { background: #2980b9; }
        .url-display { background: #f8f9fa; padding: 15px; border-radius: 4px; font-family: monospace; word-break: break-all; border: 1px solid #e9ecef; margin-bottom: 15px; }
        .url-label { font-weight: bold; color: #495057; margin-bottom: 5px; display: block; }
        textarea { width: 100%; height: 300px; font-family: 'Courier New', monospace; font-size: 13px; padding: 15px; border: 1px solid #ddd; border-radius: 4px; resize: vertical; background: #2d2d2d; color: #f8f8f2; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 14px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; font-weight: 600; color: #495057; }
        tr:hover { background: #f8f9fa; }
        .meta-info { margin-top: 15px; padding: 15px; background: #e8f4fd; border-radius: 4px; font-size: 14px; }
        .meta-info strong { color: #2980b9; }
        .hint { font-size: 12px; color: #7f8c8d; margin-top: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>REST Adapter Interactive Demo</h1>
        <p style="margin-bottom: 20px; color: #666;">Test how the RESTAdapter parses URL parameters and returns JSON data. Adjust controls to see real-time changes.</p>
        
        <div class="grid">
            <!-- Controls Panel -->
            <div class="panel">
                <h2 style="margin-bottom: 20px; font-size: 18px;">Request Parameters</h2>
                
                <div class="form-group">
                    <label for="search">Search (Global)</label>
                    <input type="text" id="search" placeholder="e.g., John">
                    <div class="hint">Searches across configured columns</div>
                </div>

                <div class="form-group">
                    <label for="page">Page Number</label>
                    <input type="number" id="page" value="1" min="1">
                </div>

                <div class="form-group">
                    <label for="perPage">Items Per Page</label>
                    <select id="perPage">
                        <option value="1">1</option>
                        <option value="10">10</option>
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="sortColumn">Sort Column</label>
                    <select id="sortColumn">
                        <option value="">None</option>
                        <option value="id">ID</option>
                        <option value="name">Name</option>
                        <option value="email">Email</option>
                        <option value="created_at">Created At</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" id="sortDesc"> Descending Order
                    </label>
                    <div class="hint">Check for DESC, unchecked for ASC</div>
                </div>

                <div class="form-group">
                    <label for="filter">Advanced Filters (JSON)</label>
                    <input type="text" id="filter" placeholder='{"age":">18","status":"active"}'>
                    <div class="hint">Format: {"field":"operator value"}</div>
                </div>

                <button onclick="updatePreview()">Update Preview</button>
            </div>

            <!-- Output Panel -->
            <div class="panel">
                <h2 style="margin-bottom: 20px; font-size: 18px;">API Response</h2>
                
                <span class="url-label">Generated URL:</span>
                <div class="url-display" id="urlDisplay">Waiting for parameters...</div>

                <span class="url-label">JSON Response:</span>
                <textarea id="jsonOutput" readonly></textarea>

                <div id="metaInfo" class="meta-info" style="display: none;"></div>

                <h3 style="margin: 20px 0 10px; font-size: 16px;">Data Preview:</h3>
                <div style="overflow-x: auto;">
                    <table id="dataPreview">
                        <thead></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        /**
         * Datos simulados en formato FETCH_NUM (compacto, índices numéricos)
         * Esto es lo que realmente devuelve DB::grid() con PDO::FETCH_NUM
         * 
         * Estructura: {head: {columns: [...], titles: [...]}, data: [[1, "Alice", ...], ...], page: {...}, stats: {...}}
         * Los nombres de columnas están en metadata.head.columns
         */
        const mockResponse = {
            head: {
                columns: ["id", "name", "email", "role", "created_at"],
                titles: ["Id", "Name", "Email", "Role", "Created At"]
            },
            data: [
                [1, "John Doeg", "john@example.com", "admin", "2026-02-25 22:47:41"],
                [2, "Jane Smith", "jane@example.com", "user", "2025-08-11 22:47:41"],
                [3, "Bob Johnson", "bob@example.com", "moderator", "2025-10-22 22:47:41"],
                [4, "Alice Williams", "alice@example.com", "user", "2026-04-13 22:47:41"],
                [5, "Charlie Brown", "charlie@example.com", "moderator", "2026-03-11 22:47:41"],
                [6, "Diana Prince", "diana@example.com", "admin", "2025-12-05 22:47:41"],
                [7, "Edward Norton", "edward@example.com", "user", "2025-09-18 22:47:41"],
                [8, "Fiona Apple", "fiona@example.com", "moderator", "2026-01-22 22:47:41"],
                [9, "George Lucas", "george@example.com", "user", "2025-07-30 22:47:41"],
                [10, "Hannah Montana", "hannah@example.com", "user", "2026-05-14 22:47:41"]
            ],
            page: {
                current: 1,
                total: 6,
                limit: 10,
                records: 51,
                next: 2,
                prev: null,
                first: 1,
                last: 6
            },
            stats: {
                exec_ms: 0.0868,
                cache: false,
                cache_type: null,
                memory_kb: 124.5,
                queries: 1
            }
        };

        function buildURL() {
            const params = new URLSearchParams();
            
            const search = document.getElementById('search').value.trim();
            if (search) params.append('search', search);

            const page = document.getElementById('page').value;
            const perPage = document.getElementById('perPage').value;
            if (page !== '1' || perPage !== '25') {
                params.append('page', `${page}:${perPage}`);
            } else {
                params.append('page', page);
            }

            const sortColumn = document.getElementById('sortColumn').value;
            if (sortColumn) {
                const isDesc = document.getElementById('sortDesc').checked;
                const sortValue = isDesc ? `-${sortColumn}` : sortColumn;
                params.append('sort', sortValue);
            }

            const filter = document.getElementById('filter').value.trim();
            if (filter) params.append('filter', filter);

            return '?' + params.toString();
        }

        function updatePreview() {
            const url = buildURL();
            document.getElementById('urlDisplay').textContent = window.location.origin + '/api/users' + url;

            // Simular respuesta API en formato toGridFormat() (datos compactos FETCH_NUM)
            // En producción esto viene directamente de QueryResponse->toGridFormat()
            const response = mockResponse;

            // Display JSON - Formato completo con head, data (FETCH_NUM), page, stats
            document.getElementById('jsonOutput').value = JSON.stringify(response, null, 2);

            // Display Meta Info
            const metaDiv = document.getElementById('metaInfo');
            metaDiv.style.display = 'block';
            metaDiv.innerHTML = `
                <strong>Pagination Info:</strong> 
                Showing ${response.data.length} of ${response.page.records} records | 
                Page ${response.page.current} of ${response.page.total} | 
                ${response.page.limit} items per page |
                <strong>Format: FETCH_NUM (compact arrays)</strong>
            `;

            // Render Table desde datos numéricos usando column names del head
            renderTable(response.data, response.head.columns);
        }

        /**
         * Renderiza tabla desde datos en formato FETCH_NUM
         * @param {Array<Array>} data - Arrays numéricos: [[1, "Alice", ...], ...]
         * @param {Array<string>} columns - Nombres de columnas: ["id", "name", ...]
         */
        function renderTable(data, columns) {
            const thead = document.querySelector('#dataPreview thead');
            const tbody = document.querySelector('#dataPreview tbody');
            
            thead.innerHTML = '';
            tbody.innerHTML = '';

            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="' + columns.length + '" style="text-align:center;color:#999;">No data found</td></tr>';
                return;
            }

            // Headers desde column names
            const headerRow = document.createElement('tr');
            columns.forEach(col => {
                const th = document.createElement('th');
                th.textContent = col;
                headerRow.appendChild(th);
            });
            thead.appendChild(headerRow);

            // Rows desde arrays numéricos
            data.forEach(row => {
                const tr = document.createElement('tr');
                row.forEach(cell => {
                    const td = document.createElement('td');
                    td.textContent = cell;
                    tr.appendChild(td);
                });
                tbody.appendChild(tr);
            });
        }

        // Initialize on load
        updatePreview();
    </script>
</body>
</html>
