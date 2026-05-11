/**
 * GraphViewer.js
 * Renders database schema as an interactive graph using vis-network.
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
            const resp = await fetch(`api.php?action=schema_graph&connectionId=${this.connectionId}`);
            const data = await resp.json();

            if (!data.nodes || data.nodes.length === 0) {
                networkContainer.innerHTML = '<div class="gv-empty">No se encontraron tablas o relaciones</div>';
                return;
            }

            const nodes = new vis.DataSet(data.nodes.map(n => ({
                ...n,
                color: { background: '#ffffff', border: '#cbd5e1', highlight: { background: '#eff6ff', border: '#3b82f6' } },
                shape: 'box',
                margin: 10,
                font: { face: 'Inter, Segoe UI', size: 12, color: '#1e293b' }
            })));

            const edges = new vis.DataSet(data.edges.map(e => ({
                ...e,
                arrows: 'to',
                color: { color: '#94a3b8', opacity: 0.5, highlight: '#3b82f6' },
                font: { size: 10, align: 'top', color: '#64748b' }
            })));

            const options = {
                layout: { improvedLayout: true },
                physics: {
                    enabled: true,
                    solver: 'forceAtlas2Based',
                    forceAtlas2Based: { gravitationalConstant: -50, centralGravity: 0.01, springLength: 100, springConstant: 0.08 },
                    stabilization: { iterations: 150 }
                },
                interaction: { hover: true, tooltipDelay: 200 }
            };

            this.network = new vis.Network(networkContainer, { nodes, edges }, options);

            // Stop physics after initial stabilization to avoid jitter
            this.network.once('stabilizationIterationsDone', () => {
                this.network.setOptions({ physics: false });
            });

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
        // Redraw if needed or fit
        if (this.network) {
            setTimeout(() => this.network.fit(), 100);
        }
    }
}
