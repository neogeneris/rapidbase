/**
 * Paginator - Componente simple de paginación
 */
class Paginator {
	constructor(containerSelector, onPageChange) {
		this.container = typeof containerSelector === 'string' 
			? document.querySelector(containerSelector) 
			: containerSelector;

		if (!this.container) {
			throw new Error('Contenedor del paginador no encontrado');
		}

		this.onPageChange = onPageChange;
		this.currentPage = 1;
		this.totalPages = 1;
		this.maxVisible = 5; // Máximo número de páginas visibles

		this.render();
	}

	/**
	 * Actualiza el estado del paginador
	 * @param {number} currentPage - Página actual
	 * @param {number} totalPages - Total de páginas
	 */
	update(currentPage, totalPages) {
		this.currentPage = currentPage;
		this.totalPages = totalPages || 1;
		this.render();
	}

	/**
	 * Renderiza el paginador
	 */
	render() {
		this.container.innerHTML = '';

		if (this.totalPages <= 1) {
			this.container.style.display = 'none';
			return;
		}

		this.container.style.display = 'flex';
		this.container.className = 'paginator';

		// Botón anterior
		const prevBtn = this.createButton('«', this.currentPage > 1);
		prevBtn.addEventListener('click', () => {
			if (this.currentPage > 1) {
				this.goToPage(this.currentPage - 1);
			}
		});
		this.container.appendChild(prevBtn);

		// Números de página
		const pages = this.getVisiblePages();
		pages.forEach(page => {
			if (page === '...') {
				const ellipsis = document.createElement('span');
				ellipsis.className = 'paginator-ellipsis';
				ellipsis.textContent = '...';
				this.container.appendChild(ellipsis);
			} else {
				const btn = this.createButton(page, true);
				btn.className = page === this.currentPage ? 'paginator-btn active' : 'paginator-btn';
				btn.addEventListener('click', () => this.goToPage(page));
				this.container.appendChild(btn);
			}
		});

		// Botón siguiente
		const nextBtn = this.createButton('»', this.currentPage < this.totalPages);
		nextBtn.addEventListener('click', () => {
			if (this.currentPage < this.totalPages) {
				this.goToPage(this.currentPage + 1);
			}
		});
		this.container.appendChild(nextBtn);
	}

	/**
	 * Crea un botón del paginador
	 */
	createButton(text, enabled) {
		const btn = document.createElement('button');
		btn.textContent = text;
		btn.className = 'paginator-btn';
		btn.disabled = !enabled;
		if (!enabled) {
			btn.classList.add('disabled');
		}
		return btn;
	}

	/**
	 * Calcula las páginas visibles
	 */
	getVisiblePages() {
		const pages = [];
		const half = Math.floor(this.maxVisible / 2);
		
		let start = Math.max(1, this.currentPage - half);
		let end = Math.min(this.totalPages, this.currentPage + half);

		// Ajustar para mostrar siempre maxVisible páginas si es posible
		if (end - start < this.maxVisible - 1) {
			if (start === 1) {
				end = Math.min(this.totalPages, start + this.maxVisible - 1);
			} else if (end === this.totalPages) {
				start = Math.max(1, end - this.maxVisible + 1);
			}
		}

		// Agregar elipsis al inicio si es necesario
		if (start > 1) {
			pages.push(1);
			if (start > 2) {
				pages.push('...');
			}
		}

		// Agregar páginas del rango
		for (let i = start; i <= end; i++) {
			pages.push(i);
		}

		// Agregar elipsis al final si es necesario
		if (end < this.totalPages) {
			if (end < this.totalPages - 1) {
				pages.push('...');
			}
			pages.push(this.totalPages);
		}

		return pages;
	}

	/**
	 * Navega a una página específica
	 */
	goToPage(page) {
		if (page < 1 || page > this.totalPages || page === this.currentPage) {
			return;
		}
		this.currentPage = page;
		this.onPageChange(page);
		this.render();
	}
}
