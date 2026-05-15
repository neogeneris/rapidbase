/**
 * GraphViewer.js
 * Renders database schema as an interactive graph using vis-network.
 * Uses exclusively the new API v1 (SchemaExplorer endpoint).
 * Completely static – no physics, no dragging.
 */
class GraphViewer {
    constructor(connectionId, options = {}) {
        this.connectionId = connectionId;
        this.container = null;
        this.network = null;
        this.onTableClick = options.onTableClick || null;
    }

    init(parentElement) {
        this.container = parentElement;
        this.container.innerHTML = `
            <div class="gv-wrapper">
                <div class="gv-toolbar">
                    <span class="gv-title">Schema: ${this.connectionId}</span>
                    <div class="gv-actions">
                        <button class="gv-btn" id="gv-zoom-in" title="Zoom In">+</button>
                        <button class="gv-btn" id="gv-zoom-out" title="Zoom Out">-</button>
                        <button class="gv-btn" id="gv-reset" title="Reset">↺</button>
                    </div>
                </div>
                <div class="gv-network" id="gv-network-${this.connectionId}">
                    <div class="gv-loading">Cargando relaciones...</div>
                </div>
            </div>
        `;

        this._bindToolbar();
        this.load();
    }

    _bindToolbar() {
        this.container.querySelector('#gv-zoom-in').onclick = () => {
            if (this.network) this.network.moveTo({ scale: this.network.getScale() * 1.2 });
        };
        this.container.querySelector('#gv-zoom-out').onclick = () => {
            if (this.network) this.network.moveTo({ scale: this.network.getScale() * 0.8 });
        };
        this.container.querySelector('#gv-reset').onclick = () => {
            if (this.network) this.network.fit({ animation: true });
        };
    }

    async load() {
        const networkContainer = this.container.querySelector('.gv-network');
        try {
            const api = new RapidBaseClient('api/v1/index.php');
            const schema = await api.schemaExplorer.getSchema({ connectionId: this.connectionId });
            
            const tables = schema.tables || [];
            const relations = schema.relations || [];

            if (!tables.length) {
                networkContainer.innerHTML = '<div class="gv-empty">No se encontraron tablas o relaciones</div>';
                return;
            }

            const nodesArr = tables.map(t => ({
                id: t.name,
                label: t.name,
                title: t.name,
                shape: 'box',
                margin: 10,
                color: { background: '#ffffff', border: '#cbd5e1', highlight: { background: '#eff6ff', border: '#3b82f6' } },
                font: { face: 'Inter, Segoe UI', size: 12, color: '#1e293b' }
            }));

            // Eliminar relaciones duplicadas
            const seen = new Set();
            const edgesArr = [];
            relations.forEach(r => {
                const key = [r.sourceTable, r.targetTable].sort().join('|');
                if (!seen.has(key)) {
                    seen.add(key);
                    edgesArr.push({
                        from: r.sourceTable,
                        to: r.targetTable,
                        label: `${r.sourceColumn} → ${r.targetColumn}`,
                        arrows: 'to',
                        title: r.type,
                        color: { color: '#94a3b8', opacity: 0.5, highlight: '#3b82f6' },
                        font: { size: 10, align: 'top', color: '#64748b' }
                    });
                }
            });

            const nodes = new vis.DataSet(nodesArr);
            const edges = new vis.DataSet(edgesArr);

            const options = {
                layout: { improvedLayout: true },
                physics: {
                    enabled: false,
                    stabilization: false
                },
                edges: {
                    smooth: false
                },
                interaction: {
                    dragNodes: false,  // no permitir arrastrar nodos
                    dragView: true,    // permitir desplazar la vista con el ratón
                    zoomView: true     // permitir zoom con rueda
                }
            };

            this.network = new vis.Network(networkContainer, { nodes, edges }, options);

            this.network.on('click', (params) => {
                if (params.nodes.length > 0) {
                    const tableName = params.nodes[0];
                    if (this.onTableClick) this.onTableClick(tableName);
                }
            });

        } catch (error) {
            networkContainer.innerHTML = `<div class="gv-error">Error al cargar el grafo: ${error.message}</div>`;
        }
    }

    onActivate() {
        if (this.network) {
            setTimeout(() => this.network.fit(), 100);
        }
    }
}