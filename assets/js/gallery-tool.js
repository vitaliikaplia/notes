/**
 * Editor.js gallery block — a grid of thumbnails (2–4 per row) with optional captions.
 * Data: { items: [{ url, caption }], columns: 3 }
 * Uploads go through the same uploader as the image tool (config.uploader.uploadByFile).
 */
class GalleryTool {

    static get toolbox() {
        return {
            title: 'Gallery',
            icon: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>'
        };
    }

    static get isReadOnlySupported() {
        return true;
    }

    constructor({ data, api, config, readOnly }) {
        this.api = api;
        this.config = config || {};
        this.readOnly = !!readOnly;
        this.data = {
            items: Array.isArray(data && data.items) ? data.items.filter(i => i && i.url) : [],
            columns: GalleryTool._clampColumns(data && data.columns)
        };
        this.wrapper = null;
        this.grid = null;
    }

    static _clampColumns(value) {
        const n = parseInt(value, 10);
        return n >= 2 && n <= 4 ? n : 3;
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('cdx-gallery');

        this.grid = document.createElement('div');
        this.grid.classList.add('cdx-gallery__grid');
        this.wrapper.appendChild(this.grid);
        this._applyColumns();

        this.data.items.forEach(item => this.grid.appendChild(this._renderItem(item)));

        if (!this.readOnly) {
            const add = document.createElement('button');
            add.type = 'button';
            add.classList.add('cdx-gallery__add');
            add.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg><span>Add images</span>';

            const input = document.createElement('input');
            input.type = 'file';
            input.accept = 'image/jpeg,image/png,image/gif,image/webp';
            input.multiple = true;
            input.hidden = true;

            add.addEventListener('click', () => input.click());
            input.addEventListener('change', () => {
                const files = Array.from(input.files || []);
                input.value = '';
                this._uploadFiles(files);
            });

            this.wrapper.appendChild(add);
            this.wrapper.appendChild(input);

            // Drop files straight onto the block
            this.wrapper.addEventListener('dragover', e => { e.preventDefault(); this.wrapper.classList.add('is-dragover'); });
            this.wrapper.addEventListener('dragleave', () => this.wrapper.classList.remove('is-dragover'));
            this.wrapper.addEventListener('drop', e => {
                e.preventDefault();
                this.wrapper.classList.remove('is-dragover');
                const files = Array.from(e.dataTransfer && e.dataTransfer.files || []).filter(f => f.type.startsWith('image/'));
                if (files.length) this._uploadFiles(files);
            });
        }

        return this.wrapper;
    }

    _renderItem(item) {
        const figure = document.createElement('figure');
        figure.classList.add('cdx-gallery__item');
        figure.dataset.url = item.url;

        const img = document.createElement('img');
        img.src = item.url;
        img.alt = item.caption || '';
        img.loading = 'lazy';
        img.dataset.full = item.url;
        figure.appendChild(img);

        const caption = document.createElement('figcaption');
        caption.classList.add('cdx-gallery__caption');
        caption.contentEditable = this.readOnly ? 'false' : 'true';
        caption.dataset.placeholder = 'Caption';
        caption.textContent = item.caption || '';
        // Keep Editor.js from treating Enter/Backspace inside the caption as block actions
        caption.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); caption.blur(); }
            e.stopPropagation();
        });
        figure.appendChild(caption);

        if (!this.readOnly) {
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.classList.add('cdx-gallery__remove');
            remove.title = 'Remove';
            remove.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
            remove.addEventListener('click', e => {
                e.stopPropagation();
                figure.remove();
                this._notifyChange();
            });
            figure.appendChild(remove);
        }

        return figure;
    }

    _uploadFiles(files) {
        const uploader = this.config.uploader && this.config.uploader.uploadByFile;
        if (!uploader || !files.length) return;

        // Upload sequentially so the order in the grid matches the pick order
        files.reduce((chain, file) => chain.then(() => uploader(file).then(result => {
            const url = result && result.success && result.file && result.file.url;
            if (!url) return;
            this.grid.appendChild(this._renderItem({ url: url, caption: '' }));
            this._notifyChange();
        })), Promise.resolve());
    }

    _applyColumns() {
        this.grid.style.setProperty('--gallery-cols', String(this.data.columns));
    }

    _notifyChange() {
        if (this.api && this.api.blocks && typeof this.api.blocks.getCurrentBlockIndex === 'function') {
            // Editor.js picks up DOM mutations for onChange; nothing else needed
        }
    }

    renderSettings() {
        return [2, 3, 4].map(n => ({
            icon: '<span style="font-weight:600;font-size:13px">' + n + '</span>',
            label: n + ' per row',
            closeOnActivate: true,
            isActive: this.data.columns === n,
            onActivate: () => {
                this.data.columns = n;
                this._applyColumns();
            }
        }));
    }

    save() {
        const items = [];
        this.grid.querySelectorAll('.cdx-gallery__item').forEach(figure => {
            const url = figure.dataset.url;
            if (!url) return;
            const cap = figure.querySelector('.cdx-gallery__caption');
            items.push({ url: url, caption: cap ? cap.textContent.trim() : '' });
        });
        return { items: items, columns: this.data.columns };
    }

    validate(data) {
        return Array.isArray(data.items) && data.items.length > 0;
    }
}
