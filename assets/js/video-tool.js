/**
 * Editor.js video block — an uploaded MP4 (H.264) or WebM file rendered as a native <video> player.
 * Data: { url, caption }
 * Uploads go through config.endpoint in chunks (POST multipart: chunk, upload_id, final),
 * so large files work regardless of PHP's upload_max_filesize / post_max_size.
 */
class VideoTool {

    static get toolbox() {
        return {
            title: 'Video',
            icon: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><polygon points="10 9 15 12 10 15 10 9"/></svg>'
        };
    }

    static get isReadOnlySupported() {
        return true;
    }

    // Lets Editor.js hand dropped/pasted video files to this tool
    static get pasteConfig() {
        return {
            files: {
                mimeTypes: ['video/mp4', 'video/webm', 'video/x-m4v'],
                extensions: ['mp4', 'm4v', 'webm']
            }
        };
    }

    constructor({ data, api, config, readOnly }) {
        this.api = api;
        this.config = config || {};
        this.readOnly = !!readOnly;
        this.chunkSize = this.config.chunkSize || 1536 * 1024; // 1.5 MB fits even a 2M upload_max_filesize
        this.data = {
            url: (data && data.url) || '',
            caption: (data && data.caption) || ''
        };
        this.wrapper = null;
        this.caption = null;
        this.drop = null;
        this.uploading = false;
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('cdx-video');
        if (this.data.url) this._renderPlayer(); else this._renderUploader();
        return this.wrapper;
    }

    _renderPlayer() {
        this.wrapper.innerHTML = '';
        this.wrapper.classList.remove('is-empty');
        this.drop = null;

        const video = document.createElement('video');
        video.src = this.data.url;
        video.controls = true;
        video.preload = 'metadata';
        video.playsInline = true;
        this.wrapper.appendChild(video);

        const caption = document.createElement('div');
        caption.classList.add('cdx-video__caption');
        caption.contentEditable = this.readOnly ? 'false' : 'true';
        caption.dataset.placeholder = 'Caption';
        caption.textContent = this.data.caption || '';
        // Keep Editor.js from treating Enter/Backspace inside the caption as block actions
        caption.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); caption.blur(); }
            e.stopPropagation();
        });
        this.wrapper.appendChild(caption);
        this.caption = caption;
    }

    _renderUploader() {
        this.wrapper.innerHTML = '';
        this.wrapper.classList.add('is-empty');
        this.caption = null;
        if (this.readOnly) return;

        const drop = document.createElement('div');
        drop.classList.add('cdx-video__drop');
        drop.innerHTML =
            '<button type="button" class="cdx-video__btn">' +
                '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><polygon points="10 9 15 12 10 15 10 9"/></svg>' +
                '<span>Choose video</span>' +
            '</button>' +
            '<div class="cdx-video__hint">MP4 (H.264) or WebM — or drop a file here</div>' +
            '<div class="cdx-video__progress" hidden><div class="cdx-video__progress-bar"></div></div>' +
            '<div class="cdx-video__status"></div>';

        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'video/mp4,video/webm,.mp4,.m4v,.webm';
        input.hidden = true;

        drop.querySelector('.cdx-video__btn').addEventListener('click', () => input.click());
        input.addEventListener('change', () => {
            const file = input.files && input.files[0];
            input.value = '';
            if (file) this._upload(file);
        });

        drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('is-dragover'); });
        drop.addEventListener('dragleave', () => drop.classList.remove('is-dragover'));
        drop.addEventListener('drop', e => {
            e.preventDefault();
            e.stopPropagation();
            drop.classList.remove('is-dragover');
            const file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            if (file) this._upload(file);
        });

        this.wrapper.appendChild(drop);
        this.wrapper.appendChild(input);
        this.drop = drop;
    }

    _isVideo(file) {
        return /^video\/(mp4|webm|x-m4v)$/.test(file.type || '') || /\.(mp4|m4v|webm)$/i.test(file.name || '');
    }

    _toast(message) {
        if (typeof window.showToast === 'function') window.showToast(message);
    }

    async _upload(file) {
        if (this.uploading || !this.config.endpoint) return;
        if (!this._isVideo(file)) {
            this._toast('Only MP4 (H.264) or WebM videos are supported');
            return;
        }
        if (!this.drop) this._renderUploader();

        const progress = this.drop.querySelector('.cdx-video__progress');
        const bar = progress.querySelector('.cdx-video__progress-bar');
        const status = this.drop.querySelector('.cdx-video__status');
        this.uploading = true;
        progress.hidden = false;
        bar.style.width = '0';
        status.textContent = 'Uploading ' + file.name + ' (' + Math.round(file.size / 1048576) + ' MB)…';

        try {
            let uploadId = '';
            let url = '';
            const total = Math.max(1, Math.ceil(file.size / this.chunkSize));

            for (let i = 0; i < total; i++) {
                const blob = file.slice(i * this.chunkSize, Math.min(file.size, (i + 1) * this.chunkSize));
                const form = new FormData();
                form.append('chunk', blob, 'chunk.bin');
                if (uploadId) form.append('upload_id', uploadId);
                if (i === total - 1) form.append('final', '1');

                const response = await fetch(this.config.endpoint, { method: 'POST', body: form });
                const json = await response.json();
                if (!json || !json.success) throw new Error((json && json.error) || 'Upload failed');

                if (json.upload_id) uploadId = json.upload_id;
                if (json.file && json.file.url) url = json.file.url;
                bar.style.width = Math.round(((i + 1) / total) * 100) + '%';
            }

            if (!url) throw new Error('Upload failed');
            this.data.url = url;
            this._toast('Video added');
            this._renderPlayer();
        } catch (err) {
            progress.hidden = true;
            bar.style.width = '0';
            status.textContent = '';
            this._toast(err && err.message ? err.message : 'Upload error');
        } finally {
            this.uploading = false;
        }
    }

    onPaste(event) {
        const file = event && event.detail && event.detail.file;
        if (file) this._upload(file);
    }

    save() {
        return {
            url: this.data.url,
            caption: this.caption ? this.caption.textContent.trim() : (this.data.caption || '')
        };
    }

    validate(data) {
        return !!(data && data.url);
    }
}
