class PageTool {

    static get toolbox() {
        return {
            title: 'Сторінка',
            icon: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>'
        };
    }

    constructor({ data, api, config }) {
        this.data = data || {};
        this.api = api;
        this.config = config || {};
        this.wrapper = null;
        this._searchTimer = null;
        this._dropdownIndex = -1;
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('cdx-page-block');

        if (this.data.pageUrl) {
            this._renderLink();
        } else {
            this._renderInput();
        }

        return this.wrapper;
    }

    _renderLink() {
        const link = document.createElement('a');
        link.classList.add('cdx-page-link');
        link.href = this.config.homeUrl + this.data.pageUrl + '/';

        const icon = document.createElement('span');
        icon.classList.add('cdx-page-link__icon');
        const noteIcon = this.data.icon || '';
        if (noteIcon && !noteIcon.startsWith('<svg') && !noteIcon.startsWith('data:')) {
            icon.textContent = noteIcon;
        } else if (noteIcon && (noteIcon.startsWith('<svg') || noteIcon.startsWith('data:'))) {
            if (noteIcon.startsWith('data:')) {
                icon.innerHTML = '<img src="' + noteIcon + '" width="16" height="16" alt="">';
            } else {
                icon.innerHTML = noteIcon;
            }
        } else {
            icon.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
        }

        const title = document.createElement('span');
        title.classList.add('cdx-page-link__title');
        title.textContent = this.data.title;

        link.appendChild(icon);
        link.appendChild(title);

        link.addEventListener('click', (e) => {
            e.preventDefault();
            window.location.href = link.href;
        });

        this.wrapper.innerHTML = '';
        this.wrapper.appendChild(link);
    }

    _renderInput() {
        const inputWrap = document.createElement('div');
        inputWrap.classList.add('cdx-page-input');

        const icon = document.createElement('span');
        icon.classList.add('cdx-page-input__icon');
        icon.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';

        const input = document.createElement('input');
        input.classList.add('cdx-page-input__field');
        input.type = 'text';
        input.placeholder = 'Знайти або створити сторінку...';

        const btn = document.createElement('button');
        btn.classList.add('cdx-page-input__btn');
        btn.textContent = 'Створити';
        btn.type = 'button';

        // Dropdown for search results
        const dropdown = document.createElement('div');
        dropdown.classList.add('cdx-page-dropdown');

        // Search on input
        input.addEventListener('input', () => {
            clearTimeout(this._searchTimer);
            const q = input.value.trim();
            if (q.length < 2) {
                dropdown.innerHTML = '';
                dropdown.classList.remove('visible');
                this._dropdownIndex = -1;
                return;
            }
            this._searchTimer = setTimeout(() => this._search(q, dropdown, input), 200);
        });

        // Keyboard navigation in dropdown
        input.addEventListener('keydown', (e) => {
            const items = dropdown.querySelectorAll('.cdx-page-dropdown__item');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                this._dropdownIndex = Math.min(this._dropdownIndex + 1, items.length - 1);
                this._highlightDropdown(items);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                this._dropdownIndex = Math.max(this._dropdownIndex - 1, -1);
                this._highlightDropdown(items);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (this._dropdownIndex >= 0 && items[this._dropdownIndex]) {
                    items[this._dropdownIndex].click();
                } else {
                    createPage();
                }
            } else if (e.key === 'Escape') {
                dropdown.innerHTML = '';
                dropdown.classList.remove('visible');
                this._dropdownIndex = -1;
            }
        });

        // Close dropdown on outside click
        document.addEventListener('click', (e) => {
            if (!inputWrap.contains(e.target)) {
                dropdown.innerHTML = '';
                dropdown.classList.remove('visible');
                this._dropdownIndex = -1;
            }
        }, true);

        const createPage = async () => {
            const title = input.value.trim();
            if (!title) {
                input.focus();
                return;
            }

            btn.disabled = true;
            btn.textContent = 'Створення...';
            input.disabled = true;

            try {
                let parentPath = this.config.getCurrentPath();

                if (!parentPath && this.config.forceSave) {
                    parentPath = await this.config.forceSave();
                }

                if (!parentPath) {
                    btn.disabled = false;
                    btn.textContent = 'Створити';
                    input.disabled = false;
                    alert('Спочатку збережіть батьківську нотатку');
                    return;
                }

                const response = await fetch(this.config.homeUrl + 'api/create-page/', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        title: title,
                        parent_path: parentPath
                    })
                });

                const result = await response.json();

                if (result.success) {
                    this.data = {
                        title: result.title,
                        pageUrl: result.url,
                        pagePath: result.path
                    };
                    this._renderLink();
                    this._addToSidebar(result.title, result.url, result.path);
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Створити';
                    input.disabled = false;
                    alert('Помилка: ' + (result.error || 'невідома помилка'));
                }
            } catch (e) {
                btn.disabled = false;
                btn.textContent = 'Створити';
                input.disabled = false;
                alert('Помилка мережі');
            }
        };

        btn.addEventListener('click', createPage);

        inputWrap.appendChild(icon);
        inputWrap.appendChild(input);
        inputWrap.appendChild(btn);
        inputWrap.appendChild(dropdown);
        this.wrapper.appendChild(inputWrap);

        setTimeout(() => input.focus(), 50);
    }

    async _search(query, dropdown, input) {
        try {
            const resp = await fetch(this.config.homeUrl + 'api/search/?q=' + encodeURIComponent(query));
            const results = await resp.json();

            dropdown.innerHTML = '';
            this._dropdownIndex = -1;

            if (!results.length) {
                dropdown.classList.remove('visible');
                return;
            }

            // Filter out the current note
            const currentPath = this.config.getCurrentPath ? this.config.getCurrentPath() : null;
            const filtered = results.filter(r => r.file !== currentPath);

            if (!filtered.length) {
                dropdown.classList.remove('visible');
                return;
            }

            filtered.forEach(result => {
                const item = document.createElement('div');
                item.classList.add('cdx-page-dropdown__item');

                const iconEl = document.createElement('span');
                iconEl.classList.add('cdx-page-dropdown__icon');
                if (result.icon && !result.icon.startsWith('<svg') && !result.icon.startsWith('data:')) {
                    iconEl.textContent = result.icon;
                } else if (result.icon && result.icon.startsWith('data:')) {
                    iconEl.innerHTML = '<img src="' + result.icon + '" width="16" height="16" alt="">';
                } else {
                    iconEl.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
                }

                const textWrap = document.createElement('div');
                textWrap.classList.add('cdx-page-dropdown__text');

                const titleEl = document.createElement('span');
                titleEl.classList.add('cdx-page-dropdown__title');
                titleEl.textContent = result.title;

                textWrap.appendChild(titleEl);

                if (result.path) {
                    const pathEl = document.createElement('span');
                    pathEl.classList.add('cdx-page-dropdown__path');
                    pathEl.textContent = result.path;
                    textWrap.appendChild(pathEl);
                }

                item.appendChild(iconEl);
                item.appendChild(textWrap);

                item.addEventListener('click', () => {
                    // Link to existing note
                    const pageUrl = result.url.replace(this.config.homeUrl, '').replace(/\/$/, '');
                    this.data = {
                        title: result.title,
                        icon: result.icon || '',
                        pageUrl: pageUrl,
                        pagePath: result.file
                    };
                    dropdown.innerHTML = '';
                    dropdown.classList.remove('visible');
                    this._renderLink();
                });

                dropdown.appendChild(item);
            });

            dropdown.classList.add('visible');
        } catch (e) {
            console.error('Page search error:', e);
        }
    }

    _highlightDropdown(items) {
        items.forEach((el, i) => {
            el.classList.toggle('highlighted', i === this._dropdownIndex);
        });
    }

    _addToSidebar(title, url, path) {
        const homeUrl = this.config.homeUrl;
        const parentPath = this.config.getCurrentPath();
        if (!parentPath) return;

        const parentUrl = homeUrl + 'note/' + parentPath.replace(/\.json$/, '') + '/';

        // Build child note-item HTML
        const childItem = document.createElement('div');
        childItem.className = 'sidebar-note-item';
        childItem.innerHTML =
            '<a href="' + homeUrl + url + '/" class="sidebar-note">' + title + '</a>' +
            '<button class="sidebar-note-delete" data-path="' + path + '" title="Видалити">' +
                '<svg class="icon-trash" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>' +
                '<svg class="icon-check" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>' +
            '</button>';

        // Bind delete handler on the new button
        this._bindDeleteHandler(childItem.querySelector('.sidebar-note-delete'), homeUrl);

        // Find parent in sidebar
        const parentLink = document.querySelector('.sidebar-note[href="' + parentUrl + '"], .sidebar-note-parent[href="' + parentUrl + '"]');
        if (!parentLink) return;

        const parentFolder = parentLink.closest('.sidebar-folder');
        if (parentFolder) {
            // Parent already has children — append to existing container
            const children = parentFolder.querySelector('.sidebar-folder-children');
            if (children) {
                children.appendChild(childItem);
            }
        } else {
            // Parent is a flat note-item — convert to folder with toggle
            const parentItem = parentLink.closest('.sidebar-note-item');
            if (!parentItem) return;

            const deleteBtn = parentItem.querySelector('.sidebar-note-delete');

            const folder = document.createElement('div');
            folder.className = 'sidebar-folder';
            folder.innerHTML =
                '<div class="sidebar-folder-header">' +
                    '<button class="folder-toggle" data-toggle="folder"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></button>' +
                '</div>' +
                '<div class="sidebar-folder-children"></div>';

            // Move parent link as sidebar-note-parent
            parentLink.className = 'sidebar-note-parent';
            folder.querySelector('.sidebar-folder-header').appendChild(parentLink);

            // Move delete button
            if (deleteBtn) {
                folder.querySelector('.sidebar-folder-header').appendChild(deleteBtn);
            }

            // Add child
            folder.querySelector('.sidebar-folder-children').appendChild(childItem);

            // Bind toggle
            const toggleBtn = folder.querySelector('[data-toggle="folder"]');
            toggleBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                folder.classList.toggle('collapsed');
            });

            parentItem.replaceWith(folder);
        }
    }

    _bindDeleteHandler(btn, homeUrl) {
        let deleteTimer = null;
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            e.stopPropagation();
            const path = btn.dataset.path;
            if (!path) return;

            if (!btn.classList.contains('confirm')) {
                document.querySelectorAll('.sidebar-note-delete.confirm').forEach(b => b.classList.remove('confirm'));
                btn.classList.add('confirm');
                if (deleteTimer) clearTimeout(deleteTimer);
                deleteTimer = setTimeout(() => btn.classList.remove('confirm'), 3000);
                return;
            }

            btn.classList.remove('confirm');
            const response = await fetch(homeUrl + 'api/delete/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ path })
            });
            const result = await response.json();
            if (result.success) {
                const noteUrl = 'note/' + path.replace(/\.json$/, '');
                if (window.location.pathname.includes(noteUrl)) {
                    window.location.href = homeUrl;
                } else {
                    const item = btn.closest('.sidebar-folder') || btn.closest('.sidebar-note-item');
                    if (item) item.remove();
                }
            } else {
                alert('Помилка видалення');
            }
        });
    }

    save() {
        return this.data;
    }

    validate(savedData) {
        return !!(savedData.pageUrl && savedData.title);
    }
}
