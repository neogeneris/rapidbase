class QueryBrowserGrid {
    static createTableGrid(containerSelector, connectionId, table, options = {}) {
        const apiUrl = 'api.php?action=grid_data';
        const config = {
            mode: options.mode || 'pagination',
            pageSize: options.pageSize || 20,
            filter: options.filter || {}
        };
        const grid = new APIDataGrid(containerSelector, apiUrl, config);
        grid.connectionId = connectionId;
        grid.table = table;
        
        const originalBuildParams = grid.buildParams.bind(grid);
        grid.buildParams = function() {
            const params = originalBuildParams();
            params.set('connectionId', this.connectionId);
            params.set('table', this.table);
            return params;
        };
        
        grid.load();
        return grid;
    }
    static destroy(grid) {
        if (grid && typeof grid.clear === 'function') grid.clear();
    }
}
window.QueryBrowserGrid = QueryBrowserGrid;