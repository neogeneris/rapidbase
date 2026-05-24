class ConnectionFooter {
    constructor(containerId, connectionId) {
        this.container = document.getElementById(containerId);
        this.connectionId = connectionId;
        if (!this.container) return;
        this.renderLoading();
        this.fetchInfo();
    }

    async fetchInfo() {
        try {
            const resp = await fetch(`api/v1/index.php?ep=ConnectionManager&action=ping&connectionId=${this.connectionId}`);
            const data = await resp.json();
            if (data.error) throw new Error(data.error);
            this.render(data);
        } catch (e) {
            this.container.innerHTML = `<span>Error al cargar info de conexión</span>`;
        }
    }

    renderLoading() {
        this.container.innerHTML = `<span>Cargando información...</span>`;
    }

    render(info) {
        let html = '';
        if (info.name) html += `<span class="footer-item">🔌 ${escapeHtml(info.name)}</span>`;
        if (info.driver) html += `<span class="footer-item">Driver: ${escapeHtml(info.driver)}</span>`;
        if (info.host) html += `<span class="footer-item">Host: ${escapeHtml(info.host)}${info.port ? ':' + info.port : ''}</span>`;
        if (info.database_name) html += `<span class="footer-item">BD: ${escapeHtml(info.database_name)}</span>`;
        this.container.innerHTML = html;
    }
}
// escapeHtml debe estar definida globalmente (ya existe en index.html)