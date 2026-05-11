/**
 * GridViewer.js
 * Wrapper for APIDataGrid to be used in TabManager.
 */
class GridViewer {
    constructor(connectionId, tableName) {
        this.connectionId = connectionId;
        this.tableName = tableName;
        this.grid = null;
    }

    init(parentElement) {
        parentElement.innerHTML = `
            <div class="grid-container" id="grid-${this.connectionId}-${this.tableName}">
                <div class="grid-controls"></div>
                <div class="grid-scroll-wrapper">
                    <table class="grid-table">
                        <thead class="grid-head"></thead>
                        <tbody class="grid-body"></tbody>
                    </table>
                </div>
                <div class="grid-footer"></div>
                <div class="grid-loading" style="display:none;">Cargando...</div>
                <div class="grid-error" style="display:none;"></div>
            </div>
        `;

        const apiUrl = `api.php?action=grid_data&connectionId=${this.connectionId}&table=${this.tableName}`;
        this.grid = new APIDataGrid(`#grid-${this.connectionId}-${this.tableName}`, apiUrl, {
            mode: 'infinite',
            pageSize: 50
        });
        this.grid.load();
    }

    onActivate() {
        // Any refresh logic if needed
    }
}
