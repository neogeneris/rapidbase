/**
 * GridBuilder
 * 
 * Clase base para construir grids dinámicos a partir de plantillas HTML.
 * Detecta automáticamente el modo de renderizado según la estructura del HTML.
 */
class GridBuilder {
    constructor(containerSelector) {
        this.container = document.querySelector(containerSelector);
        if (!this.container) throw new Error("Container not found: " + containerSelector);

        // Extraer plantillas
        this.headerTemplate = this.container.querySelector('.grid-head');
        this.rowTemplate = this.container.querySelector('.grid-row');
        this.bodyContainer = this.container.querySelector('.grid-body') || this.container;

        // Ocultar plantillas originales
        if (this.rowTemplate) this.rowTemplate.classList.add('hidden');
        
        // Estado interno
        this.currentMetadata = null;
        this.columns = [];
    }

    /**
     * Renderiza los datos en el grid
     * @param {Array} data - Array de objetos con los datos
     * @param {Array|null} metadata - Metadata opcional de columnas
     */
    render(data, metadata = null) {
        this.currentMetadata = metadata;
        
        // Determinar si hay columnas definidas en la plantilla
        const colsInTemplate = this.headerTemplate ? 
            this.headerTemplate.querySelectorAll('.grid-header').length : 0;

        if (colsInTemplate === 1 && this.headerTemplate) {
            // Modo 1: Una columna en HTML → Generar dinámicamente para N columnas
            this.renderSingleColumnMode(data, metadata);
        } else if (metadata && metadata.length > 0) {
            // Modo 2: Usar metadata para generar encabezados
            this.renderWithMetadata(data, metadata);
        } else if (colsInTemplate > 1) {
            // Modo 3: Interpolación con {nombre}, {email}, etc.
            this.renderMultiColumnMode(data);
        } else {
            // Modo fallback: generar todo dinámicamente
            this.renderSingleColumnMode(data, metadata);
        }
    }

    /**
     * Modo de renderizado con una sola columna definida → genera todas dinámicamente
     */
    renderSingleColumnMode(data, metadata) {
        if (!data || data.length === 0) {
            this.bodyContainer.innerHTML = '<div class="grid-empty">No data available</div>';
            return;
        }

        const firstRow = data[0];
        const keys = Object.keys(firstRow);

        // Si hay metadata, usarla para los títulos
        if (metadata && metadata.length === keys.length) {
            this.columns = metadata.map(m => m.key || m.title);
            const headerHTML = metadata.map(m => 
                `<div class="grid-header" data-key="${m.key || ''}">${m.title || m.key}</div>`
            ).join('');
            if (this.headerTemplate) {
                this.headerTemplate.innerHTML = headerHTML;
            }
        } else {
            // Generar encabezados desde las claves
            this.columns = keys;
            const headerHTML = keys.map(key => 
                `<div class="grid-header" data-key="${key}">${this.formatKey(key)}</div>`
            ).join('');
            if (this.headerTemplate) {
                this.headerTemplate.innerHTML = headerHTML;
            }
        }

        // Limpiar cuerpo
        this.bodyContainer.innerHTML = '';

        // Renderizar filas
        data.forEach(row => {
            let rowHTML = '';
            this.columns.forEach(key => {
                const value = row[key] !== undefined ? row[key] : '';
                rowHTML += `<div class="grid-item">${this.escapeHtml(value)}</div>`;
            });
            this.bodyContainer.insertAdjacentHTML('beforeend', 
                `<div class="grid-row">${rowHTML}</div>`
            );
        });
    }

    /**
     * Modo de renderizado usando metadata explícita
     */
    renderWithMetadata(data, metadata) {
        this.columns = metadata.map(m => m.key);

        // Generar encabezados desde metadata
        const headerHTML = metadata.map(m => 
            `<div class="grid-header" data-key="${m.key || ''}">${m.title || m.key}</div>`
        ).join('');
        
        if (this.headerTemplate) {
            this.headerTemplate.innerHTML = headerHTML;
        }

        // Limpiar cuerpo
        this.bodyContainer.innerHTML = '';

        if (!data || data.length === 0) {
            this.bodyContainer.innerHTML = '<div class="grid-empty">No data available</div>';
            return;
        }

        // Renderizar filas
        data.forEach(row => {
            let rowHTML = '';
            metadata.forEach(m => {
                const key = m.key;
                const value = row[key] !== undefined ? row[key] : '';
                rowHTML += `<div class="grid-item">${this.escapeHtml(value)}</div>`;
            });
            this.bodyContainer.insertAdjacentHTML('beforeend', 
                `<div class="grid-row">${rowHTML}</div>`
            );
        });
    }

    /**
     * Modo de renderizado multi-columna con interpolación de plantillas
     */
    renderMultiColumnMode(data) {
        if (!this.rowTemplate) return;

        // Limpiar cuerpo
        this.bodyContainer.innerHTML = '';

        if (!data || data.length === 0) {
            this.bodyContainer.innerHTML = '<div class="grid-empty">No data available</div>';
            return;
        }

        // Clonar y rellenar filas usando interpolación
        const templateHTML = this.rowTemplate.innerHTML;
        
        data.forEach(row => {
            let rowClone = templateHTML;
            
            // Reemplazar {clave} por valor
            Object.keys(row).forEach(key => {
                const regex = new RegExp(`{${key}}`, 'g');
                rowClone = rowClone.replace(regex, this.escapeHtml(row[key]));
            });
            
            // También soportar índices numéricos {1}, {2}, etc.
            const keys = Object.keys(row);
            keys.forEach((key, index) => {
                const regex = new RegExp(`{${index + 1}}`, 'g');
                rowClone = rowClone.replace(regex, this.escapeHtml(row[key]));
            });

            this.bodyContainer.insertAdjacentHTML('beforeend', 
                `<div class="grid-row">${rowClone}</div>`
            );
        });
    }

    /**
     * Formatea una clave para mostrar como título
     */
    formatKey(key) {
        return key.charAt(0).toUpperCase() + key.slice(1).replace(/_/g, ' ');
    }

    /**
     * Escapa caracteres HTML para prevenir XSS
     */
    escapeHtml(value) {
        if (value === null || value === undefined) return '';
        const str = String(value);
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /**
     * Limpia el grid
     */
    clear() {
        this.bodyContainer.innerHTML = '';
        if (this.headerTemplate) {
            this.headerTemplate.innerHTML = '';
        }
    }
}
