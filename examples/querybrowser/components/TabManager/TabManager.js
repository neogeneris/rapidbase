/**
 * TabManager.js
 * Manages multiple tabs in the central panel.
 * 
 * Usage:
 *   app.tabs = new TabManager('tabs-header-id', 'tabs-content-id');
 */
class TabManager {
    constructor(headerId, contentId, options = {}) {
        this.header = document.getElementById(headerId);
        this.content = document.getElementById(contentId);
        this.tabs = new Map(); // id => { title, component, element, btn }
        this.activeTabId = null;
        this.options = options;
    }

    addTab(id, title, component, options = {}) {
        if (this.tabs.has(id)) {
            this.activateTab(id);
            return;
        }

        // Create Tab Button
        const btn = document.createElement('div');
        btn.className = 'tm-tab';
        btn.dataset.id = id;
        btn.innerHTML = `
            <span class="tm-tab-icon">${options.icon || '📄'}</span>
            <span class="tm-tab-title">${window.escapeHtml(title)}</span>
            <span class="tm-tab-close">×</span>
        `;

        btn.onclick = (e) => {
            if (e.target.classList.contains('tm-tab-close')) {
                this.closeTab(id);
            } else {
                this.activateTab(id);
            }
        };

        this.header.appendChild(btn);

        // Create Content Container
        const pane = document.createElement('div');
        pane.className = 'tm-pane';
        pane.id = `tm-pane-${id}`;
        this.content.appendChild(pane);

        // Initialize Component
        if (component && typeof component.init === 'function') {
            component.init(pane);
        }

        this.tabs.set(id, { title, component, pane, btn });
        this.activateTab(id);
    }

    activateTab(id) {
        const tab = this.tabs.get(id);
        if (!tab) return;

        // UI update
        this.tabs.forEach(t => {
            t.btn.classList.remove('active');
            t.pane.classList.remove('active');
        });

        tab.btn.classList.add('active');
        tab.pane.classList.add('active');
        this.activeTabId = id;

        // Callback if component has onActivate
        if (tab.component && typeof tab.component.onActivate === 'function') {
            tab.component.onActivate();
        }

        if (typeof this.options.onTabActivate === 'function') {
            this.options.onTabActivate(id, tab);
        }
    }

    closeTab(id) {
        const tab = this.tabs.get(id);
        if (!tab) return;

        tab.btn.remove();
        tab.pane.remove();
        this.tabs.delete(id);

        let nextTab = null;
        if (this.activeTabId === id) {
            nextTab = this.tabs.keys().next().value;
            if (nextTab) this.activateTab(nextTab);
            else this.activeTabId = null;
        }

        if (typeof this.options.onTabClose === 'function') {
            this.options.onTabClose(id, nextTab ? this.tabs.get(nextTab) : null);
        }
    }
}
