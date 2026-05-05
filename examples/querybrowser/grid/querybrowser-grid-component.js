class QueryBrowserGrid {
    static createTableGrid(containerSelector, connectionId, table, options = {}) {
        const apiUrl = 'api.php?action=grid_data';
        const config = {
            mode: options.mode || 'infinite',
            pageSize: options.pageSize || 30,
            filter: options.filter || {}
        };
        const grid = new APIDataGrid(containerSelector, apiUrl, config);
        grid.connectionId = connectionId;
        grid.table = table;
        
        const origBuildParams = grid.buildParams.bind(grid);
        grid.buildParams = function() {
            const p = origBuildParams();
            p.set('connectionId', this.connectionId);
            p.set('table', this.table);
            return p;
        };
        
        grid.container.addEventListener('click', (e) => {
            const header = e.target.closest('.grid-header');
            if (header && header.dataset.column) grid.sortBy(header.dataset.column);
        });
        
        grid.load();
        return grid;
    }
    
    static destroy(grid) {
        if (grid && typeof grid.clear === 'function') grid.clear();
    }
}
window.QueryBrowserGrid = QueryBrowserGrid;