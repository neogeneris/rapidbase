class Panel {
    /**
     * @param {string} containerId - El ID del div donde se renderizará el panel.
     * @param {string} title - El título que aparecerá en el encabezado.
     * @param {Object} options - Configuración adicional (iconos, acciones, etc.)
     */
    constructor(containerId, title, options = {}) {
        this.container = document.getElementById(containerId);
        this.title = title;
        this.options = options;
        this.bodyId = `${containerId}-body`; // ID único para el contenido interno
        
        if (!this.container) return;
        this.render();
    }

    render() {
        // Estructura del Panel siguiendo tu idea de "aspecto de ventana"
        this.container.innerHTML = `
            <div class="rb-panel">
                <div class="rb-panel-header">
                    <div class="rb-panel-title-group">
                        ${this.options.icon ? `<span class="rb-panel-icon">${this.options.icon}</span>` : ''}
                        <span class="rb-panel-title">${this.title}</span>
                    </div>
                    <div class="rb-panel-actions">
                        ${this.renderActions()}
                    </div>
                </div>
                <div class="rb-panel-body" id="${this.bodyId}">
                    </div>
            </div>
        `;
    }

    renderActions() {
        // Por ahora botones básicos, podrías expandirlos luego
        return `
            <button class="rb-panel-btn" title="Refrescar">⟳</button>
            <button class="rb-panel-btn" title="Opciones">⋮</button>
        `;
    }

    /**
     * Retorna el elemento del cuerpo del panel para que otros 
     * componentes puedan renderizarse dentro.
     */
    getBodyContainer() {
        return document.getElementById(this.bodyId);
    }
}