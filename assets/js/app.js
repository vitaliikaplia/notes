function initEditor(config) {
    const { noteData, oldPath, createdAt, homeUrl, isNew, noteFolder, noteIcon, noteCover, coverPosition, noteColor, notePinned, children } = config;

    let currentPath = oldPath;
    let saveTimeout = null;
    let isSaving = false;
    let currentIcon = noteIcon || '';
    let currentCover = noteCover || '';
    let coverPosY = coverPosition ?? 50;
    let currentColor = noteColor || '';
    let currentPinned = notePinned || false;
    let isInternalChange = false;
    const statusEl = document.getElementById('save-status');
    const titleEl = document.getElementById('note-title');
    const iconBtnEl = document.getElementById('note-icon-btn');

    const defaultIconSvg = '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';

    function updateIconButton() {
        if (!iconBtnEl) return;
        if (currentIcon && currentIcon.startsWith('data:')) {
            // SVG as base64 data URI
            iconBtnEl.innerHTML = '<img src="' + currentIcon + '" width="34" height="34" alt="">';
            iconBtnEl.classList.add('has-icon');
        } else if (currentIcon && !currentIcon.startsWith('<')) {
            // Emoji
            iconBtnEl.innerHTML = '<span class="note-icon-emoji">' + currentIcon + '</span>';
            iconBtnEl.classList.add('has-icon');
        } else if (currentIcon && currentIcon.startsWith('<svg')) {
            // Legacy raw SVG — convert to data URI
            currentIcon = 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(currentIcon)));
            iconBtnEl.innerHTML = '<img src="' + currentIcon + '" width="34" height="34" alt="">';
            iconBtnEl.classList.add('has-icon');
            scheduleSave();
        } else {
            iconBtnEl.innerHTML = defaultIconSvg;
            iconBtnEl.classList.remove('has-icon');
        }
    }

    // Set initial icon
    updateIconButton();

    // Note background color
    // Light/dark theme variable sets for note color override
    var lightThemeVars = {
        '--bg': null, '--bg-secondary': '#f2ede4', '--bg-hover': '#e3d9c8',
        '--text': '#302b25', '--text-secondary': '#6f6459', '--border': '#ddd2bf',
        '--card-shadow': 'rgba(67, 49, 24, 0.12)', '--saved-color': '#2f7a44',
        '--selection-bg': '#d7eef7', '--selection-text': '#12202a'
    };
    var darkThemeVars = {
        '--bg': null, '--bg-secondary': '#202020', '--bg-hover': '#2c2c2c',
        '--text': '#e0e0e0', '--text-secondary': '#9b9a97', '--border': '#363636',
        '--card-shadow': 'rgba(0, 0, 0, 0.3)', '--saved-color': '#66bb6a',
        '--selection-bg': '#2a5a72', '--selection-text': '#ffffff'
    };

    function getLuminance(hex) {
        hex = hex.replace('#', '');
        var r = parseInt(hex.substring(0, 2), 16) / 255;
        var g = parseInt(hex.substring(2, 4), 16) / 255;
        var b = parseInt(hex.substring(4, 6), 16) / 255;
        r = r <= 0.03928 ? r / 12.92 : Math.pow((r + 0.055) / 1.055, 2.4);
        g = g <= 0.03928 ? g / 12.92 : Math.pow((g + 0.055) / 1.055, 2.4);
        b = b <= 0.03928 ? b / 12.92 : Math.pow((b + 0.055) / 1.055, 2.4);
        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    }

    function getContrastColor(hex) {
        return getLuminance(hex) > 0.4 ? '#302b25' : '#f5f5f5';
    }

    var noteColorVarNames = Object.keys(lightThemeVars);

    function applyNoteColor(color) {
        var contentEl = document.querySelector('.page-editor .content');
        var footerEl = document.querySelector('.editor-footer');
        if (!contentEl) return;

        // Save original theme values for footer
        var rootStyle = getComputedStyle(document.documentElement);
        var origVars = {};
        noteColorVarNames.forEach(function(v) {
            origVars[v] = rootStyle.getPropertyValue(v).trim();
        });

        if (color) {
            // Pick light or dark variable set based on note bg luminance
            var isLight = getLuminance(color) > 0.4;
            var vars = isLight ? lightThemeVars : darkThemeVars;
            vars['--bg'] = color;

            noteColorVarNames.forEach(function(v) {
                contentEl.style.setProperty(v, vars[v]);
            });
            contentEl.style.background = color;
            contentEl.style.color = vars['--text'];

            // Reset footer to original theme
            if (footerEl) {
                noteColorVarNames.forEach(function(v) {
                    footerEl.style.setProperty(v, origVars[v]);
                });
                footerEl.style.background = origVars['--bg'];
                footerEl.style.color = origVars['--text'];
            }
        } else {
            noteColorVarNames.forEach(function(v) {
                contentEl.style.removeProperty(v);
            });
            contentEl.style.background = '';
            contentEl.style.color = '';
            if (footerEl) {
                noteColorVarNames.forEach(function(v) {
                    footerEl.style.removeProperty(v);
                });
                footerEl.style.background = '';
                footerEl.style.color = '';
            }
        }
    }

    applyNoteColor(currentColor);

    // Pin toggle
    const pinBtn = document.getElementById('pin-btn');
    if (pinBtn) {
        function updatePinBtn() {
            if (currentPinned) {
                pinBtn.classList.add('active');
                pinBtn.title = 'Відкріпити';
            } else {
                pinBtn.classList.remove('active');
                pinBtn.title = 'Закріпити';
            }
        }
        updatePinBtn();
        pinBtn.addEventListener('click', function() {
            currentPinned = !currentPinned;
            updatePinBtn();
            if (saveTimeout) clearTimeout(saveTimeout);
            saveNote();
        });
    }

    // Cover image
    const coverEl = document.getElementById('editor-cover');
    const coverImgEl = document.getElementById('editor-cover-img');
    const coverAddBtn = document.getElementById('cover-add');
    const coverChangeBtn = document.getElementById('cover-change');
    const coverRemoveBtn = document.getElementById('cover-remove');
    const coverRepositionBtn = document.getElementById('cover-reposition');
    const coverActionsEl = document.getElementById('cover-actions');
    const coverHintEl = document.getElementById('cover-reposition-hint');

    function applyCoverPosition() {
        if (coverImgEl) coverImgEl.style.objectPosition = 'center ' + coverPosY + '%';
    }

    function setCover(url) {
        currentCover = url;
        if (url) {
            coverImgEl.src = url;
            applyCoverPosition();
            coverEl.style.display = '';
            coverAddBtn.style.display = 'none';
        } else {
            coverEl.style.display = 'none';
            coverAddBtn.style.display = '';
            coverPosY = 50;
        }
    }

    function uploadCover() {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/jpeg,image/png,image/gif,image/webp';
        input.addEventListener('change', function() {
            const file = input.files[0];
            if (!file) return;
            window.showToast('Завантаження обкладинки...');
            const formData = new FormData();
            formData.append('image', file);
            fetch(homeUrl + 'api/upload-image/', { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success && data.file && data.file.url) {
                        coverPosY = 50;
                        setCover(data.file.url);
                        window.showToast('Обкладинку додано');
                        scheduleSave();
                    } else {
                        window.showToast('Помилка завантаження');
                    }
                })
                .catch(function() { window.showToast('Помилка завантаження'); });
        });
        input.click();
    }

    // Cover reposition (drag Y only)
    let isRepositioning = false;
    let dragStartY = 0;
    let dragStartPos = 0;

    function startReposition() {
        isRepositioning = true;
        dragStartPos = coverPosY;
        coverEl.classList.add('repositioning');
        coverActionsEl.style.display = 'none';
        coverHintEl.style.display = '';
    }

    function stopReposition() {
        if (!isRepositioning) return;
        isRepositioning = false;
        coverEl.classList.remove('repositioning');
        coverActionsEl.style.display = '';
        coverHintEl.style.display = 'none';
        scheduleSave();
    }

    if (coverRepositionBtn) coverRepositionBtn.addEventListener('click', startReposition);

    if (coverEl) {
        coverEl.addEventListener('mousedown', function(e) {
            if (!isRepositioning) return;
            e.preventDefault();
            dragStartY = e.clientY;
            dragStartPos = coverPosY;

            function onMove(ev) {
                const dy = ev.clientY - dragStartY;
                const containerH = coverEl.offsetHeight;
                coverPosY = Math.min(100, Math.max(0, dragStartPos - (dy / containerH) * 100));
                applyCoverPosition();
            }
            function onUp() {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                stopReposition();
            }
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });

        // Touch support
        coverEl.addEventListener('touchstart', function(e) {
            if (!isRepositioning) return;
            e.preventDefault();
            dragStartY = e.touches[0].clientY;
            dragStartPos = coverPosY;

            function onTouchMove(ev) {
                const dy = ev.touches[0].clientY - dragStartY;
                const containerH = coverEl.offsetHeight;
                coverPosY = Math.min(100, Math.max(0, dragStartPos - (dy / containerH) * 100));
                applyCoverPosition();
            }
            function onTouchEnd() {
                coverEl.removeEventListener('touchmove', onTouchMove);
                coverEl.removeEventListener('touchend', onTouchEnd);
                stopReposition();
            }
            coverEl.addEventListener('touchmove', onTouchMove);
            coverEl.addEventListener('touchend', onTouchEnd);
        });
    }

    // Init cover
    setCover(currentCover);

    if (coverAddBtn) coverAddBtn.addEventListener('click', uploadCover);
    if (coverChangeBtn) coverChangeBtn.addEventListener('click', uploadCover);
    if (coverRemoveBtn) coverRemoveBtn.addEventListener('click', function() {
        setCover('');
        window.showToast('Обкладинку видалено');
        scheduleSave();
    });

    const statusTexts = {
        saving: 'Збереження...',
        saved: 'Збережено',
        error: 'Помилка збереження',
    };

    function setStatus(text, type) {
        statusEl.textContent = statusTexts[type] || '';
        statusEl.className = 'save-status' + (type ? ' save-status--' + type : '');
    }

    async function saveNote() {
        if (isSaving) return;
        isSaving = true;
        setStatus('Зберігається...', 'saving');

        try {
            const outputData = await editor.save();

            // Inject custom image widths from DOM
            const imageBlocks = document.querySelectorAll('.image-tool__image');
            let imgIdx = 0;
            outputData.blocks.forEach(block => {
                if (block.type === 'image' && imageBlocks[imgIdx]) {
                    const img = imageBlocks[imgIdx].querySelector('img');
                    if (img && img.style.width) {
                        block.data.width = parseInt(img.style.width);
                    }
                    imgIdx++;
                }
            });

            const title = titleEl.textContent.trim() || 'Без назви';
            const folder = noteFolder || '';

            const response = await fetch(homeUrl + 'api/save/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    title: title,
                    folder: folder,
                    old_path: currentPath,
                    created_at: createdAt || '',
                    icon: currentIcon || '',
                    cover: currentCover || '',
                    cover_position: Math.round(coverPosY),
                    color: currentColor || '',
                    pinned: currentPinned,
                    content: outputData
                })
            });

            const result = await response.json();

            if (result.success) {
                const wasNew = !currentPath;
                currentPath = result.path;

                // First save of a new note — full redirect to update sidebar
                if (wasNew) {
                    window.location.href = homeUrl + result.url + '/';
                    return;
                }

                // Slug changed (rename) — full redirect to update sidebar & URL
                if (!decodeURIComponent(window.location.pathname).includes(result.url)) {
                    window.location.href = homeUrl + result.url + '/';
                    return;
                }

                // Update sidebar title and icon
                const noteTitle = titleEl.textContent.trim() || 'Без назви';
                const noteUrl = homeUrl + result.url + '/';
                const sidebarLink = document.querySelector('.sidebar-note[href="' + noteUrl + '"], .sidebar-note-parent[href="' + noteUrl + '"]');
                if (sidebarLink) {
                    const defaultSvg = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
                    const sidebarIconHtml = currentIcon
                        ? (currentIcon.startsWith('data:') ? '<img src="' + currentIcon + '" width="14" height="14" alt="">' : currentIcon)
                        : defaultSvg;
                    if (sidebarLink.classList.contains('sidebar-note')) {
                        // Flat note link — has icon inside
                        sidebarLink.innerHTML = '<span class="sidebar-note-icon">' + sidebarIconHtml + '</span> ' + noteTitle;
                    } else {
                        // Parent note link — icon is a sibling before the link
                        sidebarLink.textContent = noteTitle;
                        const header = sidebarLink.closest('.sidebar-folder-header');
                        if (header) {
                            const noteIconEl = header.querySelector('.sidebar-note-icon__icon');
                            if (noteIconEl) {
                                noteIconEl.innerHTML = sidebarIconHtml;
                            }
                        }
                    }
                }

                // Update breadcrumb current title
                const crumbCurrent = document.querySelector('.breadcrumb-current');
                if (crumbCurrent) {
                    crumbCurrent.textContent = noteTitle;
                }

                setStatus('Збережено', 'saved');
                setTimeout(() => setStatus(''), 2000);
            } else {
                setStatus('Помилка збереження', 'error');
            }
        } catch (e) {
            setStatus('Помилка збереження', 'error');
        }

        isSaving = false;
    }

    // Debounced autosave
    function scheduleSave() {
        if (saveTimeout) clearTimeout(saveTimeout);
        setStatus('Редагування...', '');
        saveTimeout = setTimeout(saveNote, 1500);
    }

    // Auto-link bare URLs in paragraph blocks before loading
    function autoLinkUrls(data) {
        if (!data || !data.blocks) return data;
        data.blocks.forEach(block => {
            if (block.type === 'paragraph' && block.data && block.data.text) {
                block.data.text = block.data.text.replace(
                    /(?<![="'>])(https?:\/\/[^\s<]+)/g,
                    '<a href="$1">$1</a>'
                );
            }
        });
        return data;
    }

    const editorData = noteData && noteData.blocks ? autoLinkUrls(noteData) : { blocks: [] };

    const editor = window.noteEditor = new EditorJS({
        holder: 'editorjs',
        placeholder: 'Почніть писати...',
        data: editorData,
        tools: {
            header: {
                class: Header,
                config: {
                    placeholder: 'Заголовок',
                    levels: [1, 2, 3, 4],
                    defaultLevel: 2
                }
            },
            list: {
                class: EditorjsList,
                inlineToolbar: true
            },
            checklist: {
                class: Checklist,
                inlineToolbar: true
            },
            code: {
                class: editorJsCodeCup,
                config: {
                    showlinenumbers: true,
                    languages: {
                        none: 'Plain Text',
                        javascript: 'JavaScript',
                        typescript: 'TypeScript',
                        php: 'PHP',
                        python: 'Python',
                        html: 'HTML',
                        css: 'CSS',
                        json: 'JSON',
                        sql: 'SQL',
                        bash: 'Bash',
                        go: 'Go',
                        rust: 'Rust',
                        java: 'Java',
                        csharp: 'C#',
                        cpp: 'C++',
                        ruby: 'Ruby',
                        swift: 'Swift',
                        kotlin: 'Kotlin'
                    }
                }
            },
            quote: {
                class: Quote,
                config: {
                    quotePlaceholder: 'Цитата',
                    captionPlaceholder: 'Автор'
                }
            },
            linkTool: {
                class: LinkTool,
                config: {
                    endpoint: homeUrl + 'api/fetch-url/'
                }
            },
            image: {
                class: ImageTool,
                config: {
                    uploader: {
                        uploadByFile: function(file) {
                            window.showToast('Завантаження зображення...');
                            var formData = new FormData();
                            formData.append('image', file);
                            return fetch(homeUrl + 'api/upload-image/', {
                                method: 'POST',
                                body: formData
                            })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data.success) window.showToast('Зображення додано');
                                else window.showToast('Помилка завантаження');
                                return data;
                            })
                            .catch(function() { window.showToast('Помилка завантаження'); });
                        },
                        uploadByUrl: function(url) {
                            window.showToast('Завантаження зображення...');
                            return fetch(homeUrl + 'api/fetch-image/', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ url: url })
                            })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data.success) window.showToast('Зображення додано');
                                else window.showToast('Помилка завантаження');
                                return data;
                            })
                            .catch(function() { window.showToast('Помилка завантаження'); });
                        }
                    },
                    types: 'image/jpeg,image/png,image/gif,image/webp',
                    buttonContent: 'Вибрати зображення',
                    captionPlaceholder: ''
                }
            },
            embed: {
                class: Embed,
                config: {
                    services: {
                        youtube: true,
                        vimeo: true
                    }
                }
            },
            delimiter: Delimiter,
            inlineCode: InlineCode,
            marker: Marker,
            underline: Underline,
            strikethrough: Strikethrough,
            table: {
                class: Table,
                inlineToolbar: true,
                config: {
                    rows: 2,
                    cols: 3
                }
            },
            alert: {
                class: Alert,
                inlineToolbar: true,
                config: {
                    defaultType: 'info',
                    messagePlaceholder: 'Введіть повідомлення'
                }
            },
            toggle: {
                class: ToggleBlock,
                inlineToolbar: true
            },
            page: {
                class: PageTool,
                config: {
                    homeUrl: homeUrl,
                    getCurrentPath: () => currentPath,
                    forceSave: () => {
                        return new Promise((resolve, reject) => {
                            if (currentPath) {
                                resolve(currentPath);
                                return;
                            }
                            if (saveTimeout) clearTimeout(saveTimeout);
                            saveNote().then(() => {
                                if (currentPath) {
                                    resolve(currentPath);
                                } else {
                                    reject(new Error('Could not save parent note'));
                                }
                            }).catch(reject);
                        });
                    }
                }
            }
        },
        onReady: () => {
            new DragDrop(editor);
            new Undo({ editor });
            initImageResize();
        },
        onChange: () => {
            if (!isInternalChange) scheduleSave();
        },
        i18n: {
            messages: {
                ui: {
                    blockTunes: {
                        toggler: { 'Click to tune': 'Налаштування' }
                    },
                    inlineToolbar: {
                        converter: { 'Convert to': 'Перетворити на' }
                    },
                    toolbar: {
                        toolbox: { 'Add': 'Додати' }
                    }
                },
                toolNames: {
                    'Text': 'Текст',
                    'Heading': 'Заголовок',
                    'List': 'Список',
                    'Checklist': 'Чеклист',
                    'Code': 'Код',
                    'Quote': 'Цитата',
                    'Delimiter': 'Розділювач',
                    'Bold': 'Жирний',
                    'Italic': 'Курсив',
                    'InlineCode': 'Код',
                    'Marker': 'Маркер',
                    'Link': 'Посилання',
                    'Link Tool': 'Посилання',
                    'Сторінка': 'Сторінка',
                    'Table': 'Таблиця',
                    'Underline': 'Підкреслений',
                    'Strikethrough': 'Закреслений',
                    'Alert': 'Сповіщення',
                    'Toggle': 'Розгортуваний блок'
                },
                blockTunes: {
                    delete: { 'Delete': 'Видалити', 'Click to delete': 'Натисніть для видалення' },
                    moveUp: { 'Move up': 'Вгору' },
                    moveDown: { 'Move down': 'Вниз' }
                }
            }
        }
    });

    // Auto-link bare URLs in paragraphs after paste
    const editorHolder = document.getElementById('editorjs');
    if (editorHolder) {
        const urlRe = /(?<![="'\/\w>])(https?:\/\/[^\s<]+)/g;
        editorHolder.addEventListener('paste', () => {
            setTimeout(() => {
                const sel = window.getSelection();
                if (!sel.rangeCount) return;
                const block = sel.anchorNode?.closest?.('.ce-paragraph') || sel.anchorNode?.parentElement?.closest?.('.ce-paragraph');
                if (!block) return;
                const html = block.innerHTML;
                if (!urlRe.test(html)) return;
                urlRe.lastIndex = 0;
                const newHtml = html.replace(urlRe, '<a href="$1">$1</a>');
                if (newHtml !== html) {
                    block.innerHTML = newHtml;
                }
            }, 100);
        });
    }

    // Image resize handles
    function initImageResize() {
        function addResizeHandle(imageWrapper) {
            if (imageWrapper.querySelector('.image-resize-handle')) return;
            const img = imageWrapper.querySelector('img');
            if (!img) return;

            isInternalChange = true;
            const handle = document.createElement('div');
            handle.className = 'image-resize-handle';
            imageWrapper.style.position = 'relative';
            imageWrapper.style.display = 'inline-block';
            imageWrapper.appendChild(handle);
            isInternalChange = false;

            let startX, startWidth;

            handle.addEventListener('mousedown', (e) => {
                e.preventDefault();
                e.stopPropagation();
                startX = e.clientX;
                startWidth = img.offsetWidth;

                function onMouseMove(e) {
                    const newWidth = Math.max(100, startWidth + (e.clientX - startX));
                    img.style.width = newWidth + 'px';
                    img.style.maxWidth = '100%';
                }

                function onMouseUp() {
                    document.removeEventListener('mousemove', onMouseMove);
                    document.removeEventListener('mouseup', onMouseUp);
                    scheduleSave();
                }

                document.addEventListener('mousemove', onMouseMove);
                document.addEventListener('mouseup', onMouseUp);
            });
        }

        // Restore saved widths from block data
        if (editorData && editorData.blocks) {
            const imageWidths = editorData.blocks
                .filter(b => b.type === 'image' && b.data.width)
                .map(b => b.data.width);
            if (imageWidths.length) {
                setTimeout(() => {
                    isInternalChange = true;
                    const wrappers = document.querySelectorAll('.image-tool__image');
                    let idx = 0;
                    editorData.blocks.forEach(b => {
                        if (b.type === 'image') {
                            if (b.data.width && wrappers[idx]) {
                                const img = wrappers[idx].querySelector('img');
                                if (img) {
                                    img.style.width = b.data.width + 'px';
                                    img.style.maxWidth = '100%';
                                }
                            }
                            idx++;
                        }
                    });
                    isInternalChange = false;
                }, 300);
            }
        }

        // Add handles to existing images
        document.querySelectorAll('.image-tool__image').forEach(addResizeHandle);

        // Watch for new image blocks
        const editorEl = document.getElementById('editorjs');
        if (editorEl) {
            const observer = new MutationObserver(() => {
                isInternalChange = true;
                editorEl.querySelectorAll('.image-tool__image').forEach(addResizeHandle);
                isInternalChange = false;
            });
            observer.observe(editorEl, { childList: true, subtree: true });
        }
    }

    // Navigate links in editor on regular click
    if (editorHolder) {
        editorHolder.addEventListener('click', (e) => {
            const link = e.target.closest('a[href]');
            if (!link) return;
            // Ignore inline-tool UI links (toolbar, popover, etc.)
            if (link.closest('.ce-toolbar, .ce-inline-toolbar, .ce-popover, .ct')) return;
            e.preventDefault();
            e.stopPropagation();
            window.open(link.href, '_blank', 'noopener');
        });
    }

    // Ctrl+S / Cmd+S manual save
    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            if (saveTimeout) clearTimeout(saveTimeout);
            saveNote();
        }
    });

    // Title input triggers autosave
    titleEl.addEventListener('input', () => {
        scheduleSave();
    });

    // Enter in title focuses editor
    titleEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            editor.focus();
        }
    });

    // Paste only plain text in title
    titleEl.addEventListener('paste', (e) => {
        e.preventDefault();
        const text = (e.clipboardData || window.clipboardData).getData('text/plain').replace(/\n/g, ' ');
        document.execCommand('insertText', false, text);
    });

    // Auto-focus title for new notes (after Editor.js ready)
    if (isNew) {
        editor.isReady.then(() => titleEl.focus());
    }

    // Icon picker
    if (iconBtnEl) {
        const emojiCategories = [
            { name: 'Обличчя', emojis: ['😀','😃','😄','😁','😆','😅','🤣','😂','🙂','😊','😇','🥰','😍','🤩','😘','😗','😚','😙','🥲','😋','😛','😜','🤪','😝','🤑','🤗','🤭','🤫','🤔','🫡','🤐','🤨','😐','😑','😶','🫥','😏','😒','🙄','😬','🤥','😌','😔','😪','🤤','😴','😷','🤒','🤕','🤢','🤮','🥵','🥶','🥴','😵','🤯','🤠','🥳','🥸','😎','🤓','🧐','😕','🫤','😟','🙁','☹️','😮','😯','😲','😳','🥺','🥹','😦','😧','😨','😰','😥','😢','😭','😱','😖','😣','😞','😓','😩','😫','🥱','😤','😡','😠','🤬','😈','👿','💀','☠️','💩','🤡','👹','👺','👻','👽','👾','🤖'] },
            { name: 'Жести', emojis: ['👋','🤚','🖐️','✋','🖖','🫱','🫲','🫳','🫴','👌','🤌','🤏','✌️','🤞','🫰','🤟','🤘','🤙','👈','👉','👆','🖕','👇','☝️','🫵','👍','👎','✊','👊','🤛','🤜','👏','🙌','🫶','👐','🤲','🤝','🙏','✍️','💪','🦾','🦿'] },
            { name: 'Люди', emojis: ['👶','👧','🧒','👦','👩','🧑','👨','👩‍🦱','🧑‍🦱','👨‍🦱','👩‍🦰','🧑‍🦰','👨‍🦰','👱‍♀️','👱','👱‍♂️','👩‍🦳','🧑‍🦳','👨‍🦳','👩‍🦲','🧑‍🦲','👨‍🦲','🧔‍♀️','🧔','🧔‍♂️','👵','🧓','👴','👮','🕵️','💂','🥷','👷','🫅','🤴','👸','🧙','🧝','🧛','🧟','🧞','🧜','👼','🤰','🫃','🤱','🎅','🤶'] },
            { name: 'Тварини', emojis: ['🐶','🐱','🐭','🐹','🐰','🦊','🐻','🐼','🐻‍❄️','🐨','🐯','🦁','🐮','🐷','🐸','🐵','🙈','🙉','🙊','🐒','🐔','🐧','🐦','🐤','🐣','🐥','🦆','🦅','🦉','🦇','🐺','🐗','🐴','🦄','🐝','🪱','🐛','🦋','🐌','🐞','🐜','🪰','🪲','🪳','🦟','🦗','🕷️','🦂','🐢','🐍','🦎','🦖','🦕','🐙','🦑','🦐','🦞','🦀','🪼','🐡','🐠','🐟','🐬','🐳','🐋','🦈','🪸','🐊','🐅','🐆','🦓','🫏','🦍','🦧','🐘','🦣','🦛','🦏','🐪','🐫','🦒','🦘','🦬','🐃','🐂','🐄','🐎','🐖','🐏','🐑','🦙','🐐','🦌','🫎','🐕','🐩','🦮','🐈','🐈‍⬛','🪿','🐓','🦃','🦤','🦚','🦜','🦢','🦩','🕊️','🐇','🦝','🦨','🦡','🦫','🦦','🦥','🐁','🐀','🐿️','🦔'] },
            { name: 'Природа', emojis: ['🌵','🎄','🌲','🌳','🌴','🪹','🌱','🌿','☘️','🍀','🎍','🪴','🎋','🍃','🍂','🍁','🪺','🪻','🌾','🌺','🌻','🌹','🥀','🌷','🌼','💐','🍄','🌰','🐚','🪨','🌎','🌍','🌏','🌕','🌖','🌗','🌘','🌑','🌒','🌓','🌔','🌙','🌚','🌝','🌛','🌜','☀️','🌞','⭐','🌟','💫','✨','🌈','☁️','⛅','⛈️','🌤️','🌥️','🌦️','🌧️','🌨️','🌩️','⚡','🔥','💥','❄️','🌊','💧','💦','🫧'] },
            { name: 'Їжа', emojis: ['🍏','🍎','🍐','🍊','🍋','🍌','🍉','🍇','🍓','🫐','🍈','🍒','🍑','🥭','🍍','🥥','🥝','🍅','🍆','🥑','🫛','🥦','🥬','🥒','🌶️','🫑','🌽','🥕','🫒','🧄','🧅','🫚','🥔','🍠','🫘','🥐','🥖','🍞','🥨','🥯','🧀','🥚','🍳','🧈','🥞','🧇','🥓','🥩','🍗','🍖','🌭','🍔','🍟','🍕','🫓','🥪','🥙','🧆','🌮','🌯','🫔','🥗','🥘','🫕','🍝','🍜','🍲','🍛','🍣','🍱','🥟','🦪','🍤','🍙','🍚','🍘','🍥','🥠','🥮','🍢','🍡','🍧','🍨','🍦','🥧','🧁','🍰','🎂','🍮','🍭','🍬','🍫','🍿','🍩','🍪','🌰','🥜','🫘','🍯','🥛','☕','🫖','🍵','🧃','🥤','🧋','🍶','🍺','🍻','🥂','🍷','🫗','🥃','🍸','🍹','🧉','🍾','🧊'] },
            { name: 'Спорт', emojis: ['⚽','🏀','🏈','⚾','🥎','🎾','🏐','🏉','🥏','🎱','🪀','🏓','🏸','🏒','🏑','🥍','🏏','🪃','🥅','⛳','🪁','🏹','🎣','🤿','🥊','🥋','🎽','🛹','🛼','🛷','⛸️','🥌','🎿','⛷️','🏂','🪂','🏋️','🤸','🤼','🤽','🤾','🤺','🏄','🏊','🚣','🧘','🏇','🚴','🚵','🎖️','🏅','🥇','🥈','🥉','🏆'] },
            { name: 'Подорожі', emojis: ['🚗','🚕','🚙','🚌','🚎','🏎️','🚓','🚑','🚒','🚐','🛻','🚚','🚛','🚜','🛵','🏍️','🛺','🚲','🛴','🚏','🛣️','🛤️','🛞','🚨','🚥','🚦','🛑','🚧','⚓','🛟','⛵','🚤','🛥️','🛳️','⛴️','🚢','✈️','🛩️','🛫','🛬','🪂','💺','🚁','🚟','🚠','🚡','🛰️','🚀','🛸','🏠','🏡','🏢','🏣','🏤','🏥','🏦','🏨','🏩','🏪','🏫','🏬','🏭','🏯','🏰','💒','🗼','🗽','⛪','🕌','🛕','🕍','⛩️','🕋','⛲','⛺','🏕️','🌁','🌃','🏙️','🌄','🌅','🌆','🌇','🎠','🎡','🎢','🗻','🏔️','🌋','🗾','🏖️','🏜️','🏝️','🧭','🗺️','🌐','🌍','🌎','🌏'] },
            { name: 'Обʼєкти', emojis: ['📱','💻','🖥️','🖨️','⌨️','🖱️','🖲️','💾','💿','📀','📼','📷','📸','📹','🎥','📽️','🎞️','📞','☎️','📟','📠','📺','📻','🎙️','🎚️','🎛️','🧭','⏱️','⏲️','⏰','🕰️','⌛','⏳','📡','🔋','🪫','🔌','💡','🔦','🕯️','🧯','🛢️','🪙','💵','💴','💶','💷','💰','💳','💎','⚖️','🪜','🧰','🪛','🔧','🔨','⚒️','🛠️','⛏️','🪚','🔩','⚙️','🪤','🧱','⛓️','🧲','🔫','💣','🪓','🔪','🗡️','⚔️','🛡️','🚬','⚰️','🪦','⚱️','🏺','🔮','📿','🧿','🪬','💈','⚗️','🔭','🔬','🕳️','🩹','🩺','🩻','🩼','💊','💉','🩸','🧬','🦠','🧫','🧪','🌡️','🧹','🪠','🧺','🧻','🚽','🚰','🚿','🛁','🛀','🧼','🪥','🪒','🪮','🧽','🪣','🧴','🛎️','🔑','🗝️','🚪','🪑','🛋️','🛏️','🛌','🧸','🪆','🖼️','🪞','🪟','🛍️','🛒','🎁','🎈','🎏','🎀','🪄','🪅','🎊','🎉','🎎','🏮','🎐','🧧','✉️','📩','📨','📧','💌','📥','📤','📦','🏷️','🪧','📪','📫','📬','📭','📮','📯','📜','📃','📄','📑','🧾','📊','📈','📉','🗒️','🗓️','📆','📅','🗑️','📇','🗃️','🗳️','🗄️','📋','📁','📂','🗂️','🗞️','📰','📓','📔','📒','📕','📗','📘','📙','📚','📖','🔖','🧷','🔗','📎','🖇️','📐','📏','🧮','📌','📍','✂️','🖊️','🖋️','✒️','🖌️','🖍️','📝','✏️','🔍','🔎','🔏','🔐','🔒','🔓'] },
            { name: 'Символи', emojis: ['❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❤️‍🔥','❤️‍🩹','❣️','💕','💞','💓','💗','💖','💘','💝','💟','☮️','✝️','☪️','🕉️','☸️','✡️','🔯','🕎','☯️','☦️','🛐','⛎','♈','♉','♊','♋','♌','♍','♎','♏','♐','♑','♒','♓','🆔','⚛️','🉑','☢️','☣️','📴','📳','🈶','🈚','🈸','🈺','🈷️','✴️','🆚','💮','🉐','㊙️','㊗️','🈴','🈵','🈹','🈲','🅰️','🅱️','🆎','🆑','🅾️','🆘','❗','❕','❓','❔','‼️','⁉️','🔅','🔆','〽️','⚠️','🚸','🔱','⚜️','🔰','♻️','✅','🈯','💹','❇️','✳️','❎','🌐','💠','Ⓜ️','🌀','💤','🏧','🚾','♿','🅿️','🛗','🈳','🈂️','🛂','🛃','🛄','🛅','🚹','🚺','🚼','⚧️','🚻','🚮','🎦','📶','🈁','🔣','ℹ️','🔤','🔡','🔠','🆖','🆗','🆙','🆒','🆕','🆓','0️⃣','1️⃣','2️⃣','3️⃣','4️⃣','5️⃣','6️⃣','7️⃣','8️⃣','9️⃣','🔟','🔢','#️⃣','*️⃣','⏏️','▶️','⏸️','⏯️','⏹️','⏺️','⏭️','⏮️','⏩','⏪','⏫','⏬','◀️','🔼','🔽','➡️','⬅️','⬆️','⬇️','↗️','↘️','↙️','↖️','↕️','↔️','↪️','↩️','⤴️','⤵️','🔀','🔁','🔂','🔄','🔃','🎵','🎶','➕','➖','➗','✖️','🟰','♾️','💲','💱','™️','©️','®️','〰️','➰','➿','🔚','🔙','🔛','🔝','🔜','✔️','☑️','🔘','🔴','🟠','🟡','🟢','🔵','🟣','⚫','⚪','🟤','🔺','🔻','🔸','🔹','🔶','🔷','🔳','🔲','▪️','▫️','◾','◽','◼️','◻️','🟥','🟧','🟨','🟩','🟦','🟪','⬛','⬜','🟫','🔈','🔇','🔉','🔊','🔔','🔕','📣','📢','💬','💭','🗯️','♠️','♣️','♥️','♦️','🃏','🎴','🀄','🕐','🕑','🕒','🕓','🕔','🕕','🕖','🕗','🕘','🕙','🕚','🕛'] },
            { name: 'Прапори', emojis: ['🏳️','🏴','🏁','🚩','🏳️‍🌈','🏳️‍⚧️','🇺🇦','🇺🇸','🇬🇧','🇩🇪','🇫🇷','🇪🇸','🇮🇹','🇵🇱','🇯🇵','🇰🇷','🇨🇳','🇧🇷','🇨🇦','🇦🇺','🇮🇳','🇹🇷','🇳🇱','🇸🇪','🇳🇴','🇩🇰','🇫🇮','🇨🇿','🇦🇹','🇨🇭','🇧🇪','🇵🇹','🇬🇷','🇷🇴','🇭🇺','🇭🇷','🇸🇰','🇧🇬','🇱🇹','🇱🇻','🇪🇪','🇬🇪','🇲🇩','🇦🇿','🇦🇲','🇰🇿','🇺🇿','🇮🇱','🇦🇪','🇸🇦','🇪🇬','🇿🇦','🇳🇬','🇰🇪','🇲🇽','🇦🇷','🇨🇱','🇨🇴','🇵🇪','🇻🇪','🇹🇭','🇻🇳','🇮🇩','🇲🇾','🇵🇭','🇸🇬','🇳🇿'] }
        ];

        let pickerEl = null;

        function createPicker() {
            const picker = document.createElement('div');
            picker.className = 'note-icon-picker';

            // Tabs
            const tabs = document.createElement('div');
            tabs.className = 'note-icon-picker-tabs';

            const emojiTab = document.createElement('button');
            emojiTab.type = 'button';
            emojiTab.className = 'note-icon-picker-tab active';
            emojiTab.textContent = 'Емоджі';

            const svgTab = document.createElement('button');
            svgTab.type = 'button';
            svgTab.className = 'note-icon-picker-tab';
            svgTab.textContent = 'SVG';

            tabs.appendChild(emojiTab);
            tabs.appendChild(svgTab);
            picker.appendChild(tabs);

            // Emoji panel
            const emojiPanel = document.createElement('div');
            emojiPanel.className = 'note-icon-panel';

            // Search input
            const searchInput = document.createElement('input');
            searchInput.type = 'text';
            searchInput.className = 'note-icon-search';
            searchInput.placeholder = 'Пошук...';
            emojiPanel.appendChild(searchInput);

            const emojiContent = document.createElement('div');
            emojiContent.className = 'note-icon-emoji-content';

            function makeEmojiBtn(em, title) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'note-icon-emoji-btn';
                btn.textContent = em;
                if (title) btn.title = title;
                btn.addEventListener('click', () => {
                    currentIcon = em;
                    updateIconButton();
                    closePicker();
                    scheduleSave();
                });
                return btn;
            }

            function buildEmojiGrid() {
                emojiContent.innerHTML = '';
                emojiCategories.forEach(cat => {
                    const label = document.createElement('div');
                    label.className = 'note-icon-emoji-category';
                    label.textContent = cat.name;
                    emojiContent.appendChild(label);

                    const grid = document.createElement('div');
                    grid.className = 'note-icon-emoji-grid';
                    cat.emojis.forEach(em => grid.appendChild(makeEmojiBtn(em)));
                    emojiContent.appendChild(grid);
                });
            }

            function renderSearchResults(results) {
                emojiContent.innerHTML = '';
                if (!results.length) {
                    const empty = document.createElement('div');
                    empty.className = 'note-icon-emoji-category';
                    empty.textContent = 'Нічого не знайдено';
                    emojiContent.appendChild(empty);
                    return;
                }
                const label = document.createElement('div');
                label.className = 'note-icon-emoji-category';
                label.textContent = 'Результати пошуку';
                emojiContent.appendChild(label);

                const grid = document.createElement('div');
                grid.className = 'note-icon-emoji-grid';
                results.forEach(item => grid.appendChild(makeEmojiBtn(item.emoji, item.name)));
                emojiContent.appendChild(grid);
            }

            let emojiSearchTimeout = null;

            buildEmojiGrid();
            searchInput.addEventListener('input', () => {
                const q = searchInput.value.trim();
                if (emojiSearchTimeout) clearTimeout(emojiSearchTimeout);
                if (!q) { buildEmojiGrid(); return; }
                emojiSearchTimeout = setTimeout(() => {
                    fetch(homeUrl + 'api/emoji-search/?q=' + encodeURIComponent(q))
                        .then(r => r.json())
                        .then(results => renderSearchResults(results))
                        .catch(() => {});
                }, 250);
            });

            emojiPanel.appendChild(emojiContent);
            picker.appendChild(emojiPanel);

            // SVG panel
            const svgPanel = document.createElement('div');
            svgPanel.className = 'note-icon-panel';
            svgPanel.style.display = 'none';

            const uploadLabel = document.createElement('label');
            uploadLabel.className = 'note-icon-svg-upload';
            uploadLabel.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg><span>Завантажити SVG</span>';

            const fileInput = document.createElement('input');
            fileInput.type = 'file';
            fileInput.accept = '.svg';
            fileInput.style.display = 'none';
            fileInput.addEventListener('change', () => {
                const file = fileInput.files[0];
                if (!file) return;
                const reader = new FileReader();
                reader.onload = (evt) => {
                    const svgText = evt.target.result.trim();
                    // Send to server for minification and base64 conversion
                    fetch(homeUrl + 'api/process-svg/', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ svg: svgText })
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            currentIcon = data.data_uri;
                            updateIconButton();
                            closePicker();
                            scheduleSave();
                        } else {
                            alert(data.error || 'Невалідний SVG файл');
                        }
                    })
                    .catch(() => alert('Помилка обробки SVG'));
                };
                reader.readAsText(file);
            });
            uploadLabel.appendChild(fileInput);
            svgPanel.appendChild(uploadLabel);
            picker.appendChild(svgPanel);

            // Tab switching
            emojiTab.addEventListener('click', () => {
                emojiTab.classList.add('active');
                svgTab.classList.remove('active');
                emojiPanel.style.display = '';
                svgPanel.style.display = 'none';
            });
            svgTab.addEventListener('click', () => {
                svgTab.classList.add('active');
                emojiTab.classList.remove('active');
                svgPanel.style.display = '';
                emojiPanel.style.display = 'none';
            });

            // Reset button (only when icon is set)
            if (currentIcon) {
                const resetBtn = document.createElement('button');
                resetBtn.type = 'button';
                resetBtn.className = 'note-icon-reset';
                resetBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Видалити іконку';
                resetBtn.addEventListener('click', () => {
                    currentIcon = '';
                    updateIconButton();
                    closePicker();
                    saveNote();
                });
                picker.appendChild(resetBtn);
            }

            return picker;
        }

        function closePicker() {
            if (pickerEl) {
                pickerEl.remove();
                pickerEl = null;
            }
        }

        iconBtnEl.addEventListener('click', (e) => {
            e.stopPropagation();
            if (pickerEl) {
                closePicker();
                return;
            }
            pickerEl = createPicker();
            iconBtnEl.parentElement.appendChild(pickerEl);
        });

        document.addEventListener('click', (e) => {
            if (pickerEl && !pickerEl.contains(e.target) && e.target !== iconBtnEl) {
                closePicker();
            }
        });
    }

    // Color picker
    var colorAddBtn = document.getElementById('color-add');
    if (colorAddBtn) {
        var colorPalette = [
            // Пастельні (світлі)
            { name: 'Червоний', hex: '#FDECEC' },
            { name: 'Помаранчевий', hex: '#FEF0E0' },
            { name: 'Жовтий', hex: '#FEF9E0' },
            { name: 'Зелений', hex: '#E7F5E6' },
            { name: 'М\'ятний', hex: '#E0F5F0' },
            { name: 'Блакитний', hex: '#E0F0FE' },
            { name: 'Синій', hex: '#E4E8FD' },
            { name: 'Фіолетовий', hex: '#F0E6FA' },
            { name: 'Рожевий', hex: '#FDE6F2' },
            { name: 'Бежевий', hex: '#F0EAE0' },
            // Насичені (середні)
            { name: 'Корал', hex: '#F4A7A3' },
            { name: 'Апельсин', hex: '#F5C27A' },
            { name: 'Сонце', hex: '#F5E27A' },
            { name: 'Трава', hex: '#8FD4A4' },
            { name: 'Бірюза', hex: '#7AC8C4' },
            { name: 'Небо', hex: '#7AB8E0' },
            { name: 'Індиго', hex: '#9BA3E0' },
            { name: 'Лаванда', hex: '#C4A3E0' },
            { name: 'Фуксія', hex: '#E0A3C4' },
            { name: 'Пісок', hex: '#D4C4A8' },
            // Темні
            { name: 'Темно-червоний', hex: '#3D2020' },
            { name: 'Темно-помаранчевий', hex: '#3D3020' },
            { name: 'Темно-жовтий', hex: '#3D3A20' },
            { name: 'Темно-зелений', hex: '#203D28' },
            { name: 'Темно-бірюзовий', hex: '#203D3A' },
            { name: 'Темно-синій', hex: '#20283D' },
            { name: 'Темно-індиго', hex: '#282040' },
            { name: 'Темно-фіолетовий', hex: '#30203D' },
            { name: 'Темно-рожевий', hex: '#3D2030' },
            { name: 'Графіт', hex: '#2A2A2A' },
        ];

        var colorPickerEl = null;

        function createColorPicker() {
            var picker = document.createElement('div');
            picker.className = 'note-color-picker';

            var label = document.createElement('div');
            label.className = 'note-color-picker-label';
            label.textContent = 'Фон нотатки';
            picker.appendChild(label);

            var grid = document.createElement('div');
            grid.className = 'note-color-grid';

            colorPalette.forEach(function(c) {
                var swatch = document.createElement('button');
                swatch.type = 'button';
                swatch.className = 'note-color-swatch';
                swatch.style.background = c.hex;
                swatch.title = c.name;
                if (currentColor === c.hex) {
                    swatch.classList.add('active');
                }
                swatch.addEventListener('click', function() {
                    currentColor = c.hex;
                    applyNoteColor(currentColor);
                    closeColorPicker();
                    scheduleSave();
                });
                grid.appendChild(swatch);
            });

            // Custom color swatch with native input
            var customSwatch = document.createElement('button');
            customSwatch.type = 'button';
            customSwatch.className = 'note-color-swatch note-color-custom';
            customSwatch.title = 'Свій колір';
            customSwatch.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
            var isCustom = currentColor && !colorPalette.some(function(c) { return c.hex === currentColor; });
            if (isCustom) {
                customSwatch.style.background = currentColor;
                customSwatch.classList.add('active');
                customSwatch.innerHTML = '';
            }
            var colorInput = document.createElement('input');
            colorInput.type = 'color';
            colorInput.value = currentColor || '#ffffff';
            colorInput.className = 'note-color-input-hidden';
            customSwatch.appendChild(colorInput);
            customSwatch.addEventListener('click', function(e) {
                if (e.target !== colorInput) {
                    colorInput.click();
                }
            });
            colorInput.addEventListener('input', function() {
                currentColor = colorInput.value;
                applyNoteColor(currentColor);
            });
            colorInput.addEventListener('change', function() {
                currentColor = colorInput.value;
                applyNoteColor(currentColor);
                closeColorPicker();
                scheduleSave();
            });
            grid.appendChild(customSwatch);

            picker.appendChild(grid);

            if (currentColor) {
                var resetBtn = document.createElement('button');
                resetBtn.type = 'button';
                resetBtn.className = 'note-color-reset';
                resetBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Скинути колір';
                resetBtn.addEventListener('click', function() {
                    currentColor = '';
                    applyNoteColor('');
                    closeColorPicker();
                    scheduleSave();
                });
                picker.appendChild(resetBtn);
            }

            return picker;
        }

        function closeColorPicker() {
            if (colorPickerEl) {
                colorPickerEl.remove();
                colorPickerEl = null;
            }
        }

        colorAddBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            if (colorPickerEl) {
                closeColorPicker();
                return;
            }
            colorPickerEl = createColorPicker();
            colorAddBtn.parentElement.appendChild(colorPickerEl);
        });

        document.addEventListener('click', function(e) {
            if (colorPickerEl && !colorPickerEl.contains(e.target) && e.target !== colorAddBtn) {
                closeColorPicker();
            }
        });
    }

    // Children sync popup
    var childrenBtn = document.getElementById('children-btn');
    if (childrenBtn && children && children.length) {
        var childrenPopupEl = null;

        function getLinkedPagePaths() {
            // Scan editor DOM for existing page blocks to find which children are already linked
            var paths = new Set();
            document.querySelectorAll('.cdx-page-link').forEach(function(link) {
                var href = link.href || '';
                var match = href.match(/note\/(.+?)\/?$/);
                if (match) {
                    paths.add(match[1].replace(/\\/g, '/') + '.json');
                }
            });
            return paths;
        }

        function createChildrenPopup() {
            var popup = document.createElement('div');
            popup.className = 'note-children-popup';

            var label = document.createElement('div');
            label.className = 'note-children-popup-label';
            label.textContent = 'Дочірні нотатки';
            popup.appendChild(label);

            var linkedPaths = getLinkedPagePaths();
            var list = document.createElement('div');
            list.className = 'note-children-list';

            // Track which children exist (for detecting broken links)
            var childPathSet = new Set();
            children.forEach(function(c) {
                childPathSet.add(c.path.replace(/\\/g, '/'));
            });

            // Find broken links: page blocks pointing to children that no longer exist
            var brokenLinks = [];
            document.querySelectorAll('.cdx-page-link').forEach(function(link) {
                var href = link.href || '';
                var match = href.match(/note\/(.+?)\/?$/);
                if (!match) return;
                var linkPath = match[1].replace(/\\/g, '/');
                // Check if this link points to a path under the current note's child dir
                var parentBase = currentPath.replace(/\.json$/, '').replace(/\\/g, '/');
                if (linkPath.startsWith(parentBase + '/') && !childPathSet.has(linkPath + '.json')) {
                    var titleEl = link.querySelector('.cdx-page-link__title');
                    brokenLinks.push({
                        title: titleEl ? titleEl.textContent : linkPath.split('/').pop(),
                        path: linkPath + '.json',
                        url: 'note/' + linkPath
                    });
                }
            });

            var unlinkedCount = 0;

            children.forEach(function(child) {
                var normalizedPath = child.path.replace(/\\/g, '/');
                var isLinked = linkedPaths.has(normalizedPath);
                if (!isLinked) unlinkedCount++;

                var item = document.createElement('div');
                item.className = 'note-children-item' + (isLinked ? ' linked' : '');

                var iconEl = document.createElement('span');
                iconEl.className = 'note-children-item-icon';
                if (child.icon && !child.icon.startsWith('<svg') && !child.icon.startsWith('data:')) {
                    iconEl.textContent = child.icon;
                } else if (child.icon && child.icon.startsWith('data:')) {
                    iconEl.innerHTML = '<img src="' + child.icon + '" width="14" height="14" alt="">';
                } else {
                    iconEl.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
                }

                var titleSpan = document.createElement('span');
                titleSpan.className = 'note-children-item-title';
                titleSpan.textContent = child.title;

                var statusEl = document.createElement('span');
                statusEl.className = 'note-children-item-status';
                if (isLinked) {
                    statusEl.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
                    statusEl.title = 'Додано';
                }

                item.appendChild(iconEl);
                item.appendChild(titleSpan);
                item.appendChild(statusEl);
                list.appendChild(item);
            });

            // Show broken links
            brokenLinks.forEach(function(broken) {
                var item = document.createElement('div');
                item.className = 'note-children-item broken';

                var iconEl = document.createElement('span');
                iconEl.className = 'note-children-item-icon';
                iconEl.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

                var titleSpan = document.createElement('span');
                titleSpan.className = 'note-children-item-title';
                titleSpan.textContent = broken.title;

                var statusEl = document.createElement('span');
                statusEl.className = 'note-children-item-status';
                statusEl.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#e55" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
                statusEl.title = 'Видалено';

                item.appendChild(iconEl);
                item.appendChild(titleSpan);
                item.appendChild(statusEl);
                list.appendChild(item);
            });

            popup.appendChild(list);

            // Sync button — add missing + remove broken
            if (unlinkedCount > 0 || brokenLinks.length > 0) {
                var syncBtn = document.createElement('button');
                syncBtn.type = 'button';
                syncBtn.className = 'note-children-add-btn';
                syncBtn.textContent = 'Синхронізувати';
                syncBtn.addEventListener('click', async function() {
                    var changed = false;

                    // Remove broken page links (iterate backwards to keep indices valid)
                    if (brokenLinks.length > 0) {
                        var brokenPathSet = new Set(brokenLinks.map(function(b) { return b.path.replace(/\\/g, '/'); }));
                        var savedData = await editor.save();
                        var toDelete = [];
                        savedData.blocks.forEach(function(block, idx) {
                            if (block.type === 'page' && block.data.pagePath) {
                                var p = block.data.pagePath.replace(/\\/g, '/');
                                if (brokenPathSet.has(p)) {
                                    toDelete.push(idx);
                                }
                            }
                        });
                        for (var d = toDelete.length - 1; d >= 0; d--) {
                            editor.blocks.delete(toDelete[d]);
                        }
                        if (toDelete.length > 0) changed = true;
                    }

                    // Add missing children
                    var linked = getLinkedPagePaths();
                    for (var i = 0; i < children.length; i++) {
                        var child = children[i];
                        var normalizedPath = child.path.replace(/\\/g, '/');
                        if (linked.has(normalizedPath)) continue;

                        await editor.blocks.insert('page', {
                            title: child.title,
                            icon: child.icon || '',
                            pageUrl: child.url,
                            pagePath: child.path
                        });
                        changed = true;
                    }

                    if (changed) {
                        closeChildrenPopup();
                        saveNote();
                    }
                });
                popup.appendChild(syncBtn);
            }

            return popup;
        }

        function pluralize(n, one, few, many) {
            var abs = Math.abs(n) % 100;
            var n1 = abs % 10;
            if (abs > 10 && abs < 20) return many;
            if (n1 > 1 && n1 < 5) return few;
            if (n1 === 1) return one;
            return many;
        }

        function closeChildrenPopup() {
            if (childrenPopupEl) {
                childrenPopupEl.remove();
                childrenPopupEl = null;
            }
        }

        childrenBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            if (childrenPopupEl) {
                closeChildrenPopup();
                return;
            }
            childrenPopupEl = createChildrenPopup();
            childrenBtn.parentElement.appendChild(childrenPopupEl);
        });

        document.addEventListener('click', function(e) {
            if (childrenPopupEl && !childrenPopupEl.contains(e.target) && e.target !== childrenBtn) {
                closeChildrenPopup();
            }
        });
    }

    // Export as Markdown
    const exportBtn = document.getElementById('export-md');
    if (exportBtn) {
        exportBtn.addEventListener('click', async () => {
            const data = await editor.save();
            const title = titleEl.textContent.trim() || 'untitled';

            const resp = await fetch(homeUrl + 'api/export-md/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ title, blocks: data.blocks })
            });
            const result = await resp.json();
            if (!result.markdown) return;

            const blob = new Blob([result.markdown], { type: 'text/markdown;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = title.replace(/[\/\\:*?"<>|]/g, '-') + '.md';
            a.click();
            URL.revokeObjectURL(url);
        });
    }

}

// Language dropdown search
(function() {
    const observer = new MutationObserver((mutations) => {
        for (const m of mutations) {
            for (const node of m.addedNodes) {
                if (node.nodeType !== 1) continue;
                const dropdown = node.classList?.contains('editorjs-codeCup_languageDropdown')
                    ? node
                    : node.querySelector?.('.editorjs-codeCup_languageDropdown');
                if (dropdown && !dropdown.querySelector('.lang-search')) {
                    const input = document.createElement('input');
                    input.type = 'text';
                    input.className = 'lang-search';
                    input.placeholder = 'Пошук...';
                    dropdown.prepend(input);
                    setTimeout(() => input.focus(), 0);

                    const options = dropdown.querySelectorAll('.editorjs-codeCup_languageOption');
                    input.addEventListener('input', () => {
                        const q = input.value.toLowerCase();
                        options.forEach(opt => {
                            opt.style.display = opt.textContent.toLowerCase().includes(q) ? '' : 'none';
                        });
                    });
                    input.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter') {
                            const visible = [...options].find(o => o.style.display !== 'none');
                            if (visible) visible.click();
                        }
                    });
                }
            }
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });
})();
