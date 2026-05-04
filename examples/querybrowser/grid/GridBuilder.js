/**
 * GridBuilder
 * 
 * Clase base para construir grids dinámicos a partir de plantillas HTML.
 * Soporta dos modos:
 * 1. Modo índice numérico: Usa {0}, {1}, {2}... para datos FETCH_NUM (DB::grid)
 * 2. Modo nombres de columna: Usa {nombre}, {email}... para datos asociativos
 * 
 * Detecta automáticamente el modo basado en la plantilla HTML y los datos.
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
     * Renderiza los datos en el grid.
     * @param {Array} data - Array de filas (cada fila es array numérico u objeto)
     * @param {Object|null} metadata - Metadatos con columns y titles (formato QueryResponse.toGridFormat)
     */
    render(data, metadata = null) {
        this.currentMetadata = metadata;
        
        if (!data || data.length === 0) {
            this.bodyContainer.innerHTML = '<div class="grid-empty">No hay datos</div>';
            return;
        }

        const firstRow = data[0];
        const isNumericArray = Array.isArray(firstRow);
        
        // Detectar modo basado en los datos y la plantilla
        if (isNumericArray) {
            // Modo FETCH_NUM: arrays numéricos [val1, val2, val3...]
            this.renderNumericMode(data, metadata);
        } else {
            // Modo asociativo: objetos {key: value, key2: value2...}
            this.renderAssociativeMode(data, metadata);
        }
    }

    /**
     * Renderizado para arrays numéricos (formato DB::grid FETCH_NUM).
     * Los placeholders son {0}, {1}, {2}...
     * Metadata esperada: { columns: ['id', 'name'], titles: ['ID', 'Nombre'] }
     */
    renderNumericMode(data, metadata = null) {
        // Generar encabezados desde metadata
        if (metadata && metadata.columns && metadata.titles) {
            this.columns = metadata.columns;
            const headerHTML = metadata.titles
                .map((title, i) => `<div class="grid-header" data-column="${metadata.columns[i]}">${this.escapeHtml(title)}</div>`)
                .join('');
            if (this.headerTemplate) {
                this.headerTemplate.innerHTML = headerHTML;
            }
        } else {
            // Generar encabezados genéricos Columna 0, Columna 1...
            const colsCount = data[0].length;
            this.columns = Array.from({length: colsCount}, (_, i) => i.toString());
            const headerHTML = Array.from({length: colsCount}, (_, i) => 
                `<div class="grid-header" data-column="${i}">Columna ${i}</div>`
            ).join('');
            if (this.headerTemplate) {
                this.headerTemplate.innerHTML = headerHTML;
            }
        }

        // Limpiar cuerpo
        this.bodyContainer.innerHTML = '';

        // Renderizar filas
        data.forEach((row, rowIndex) => {
            let rowHTML = this.rowTemplate ? this.rowTemplate.innerHTML : '';
            
            // Si no hay plantilla de fila, crear una dinámica
            if (!this.rowTemplate) {
                const colsCount = row.length;
                rowHTML = Array.from({length: colsCount}, (_, i) => 
                    `<div class="grid-item">{${i}}</div>`
                ).join('');
            }

            // Reemplazar placeholders numéricos {0}, {1}, {2}...
            row.forEach((value, colIndex) => {
                const placeholder = new RegExp(`\\{${colIndex}\\}`, 'g');
                rowHTML = rowHTML.replace(placeholder, this.escapeHtml(value));
            });

            this.bodyContainer.insertAdjacentHTML('beforeend', 
                `<div class="grid-row" data-row-index="${rowIndex}">${rowHTML}</div>`
            );
        });
    }

    /**
     * Renderizado para objetos asociativos.
     * Los placeholders son {nombre}, {email}, etc.
     */
    renderAssociativeMode(data, metadata = null) {
        const firstRow = data[0];
        const keys = Object.keys(firstRow);
        this.columns = keys;

        // Actualizar encabezados si hay metadata
        if (metadata && metadata.columns && metadata.titles) {
            const headerHTML = metadata.titles
                .map((title, i) => `<div class="grid-header" data-column="${metadata.columns[i]}">${this.escapeHtml(title)}</div>`)
                .join('');
            if (this.headerTemplate) {
                this.headerTemplate.innerHTML = headerHTML;
            }
        } else if (this.headerTemplate && this.headerTemplate.querySelectorAll('.grid-header').length === 1) {
            // Modo dinámico: generar encabezados desde las claves
            const headerHTML = keys.map(key => 
                `<div class="grid-header" data-column="${key}">${this.formatKey(key)}</div>`
            ).join('');
            this.headerTemplate.innerHTML = headerHTML;
        }

        // Limpiar cuerpo
        this.bodyContainer.innerHTML = '';

        // Renderizar filas
        data.forEach((row, rowIndex) => {
            let rowHTML = this.rowTemplate ? this.rowTemplate.innerHTML : '';
            
            // Si no hay plantilla, crear una dinámica con las claves
            if (!this.rowTemplate) {
                rowHTML = keys.map(key => 
                    `<div class="grid-item">{${key}}</div>`
                ).join('');
            }

            // Reemplazar placeholders nombrados {clave}
            Object.keys(row).forEach(key => {
                const placeholder = new RegExp(`\\{${key}\\}`, 'g');
                rowHTML = rowHTML.replace(placeholder, this.escapeHtml(row[key]));
            });

            this.bodyContainer.insertAdjacentHTML('beforeend', 
                `<div class="grid-row" data-row-index="${rowIndex}">${rowHTML}</div>`
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
