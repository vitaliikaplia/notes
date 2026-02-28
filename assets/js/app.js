function initEditor(config) {
    const { noteData, oldPath, createdAt, homeUrl, isNew, noteFolder, noteIcon } = config;

    let currentPath = oldPath;
    let saveTimeout = null;
    let isSaving = false;
    let currentIcon = noteIcon || '';
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
                if (!window.location.pathname.includes(result.url)) {
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

    const editor = new EditorJS({
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
                    endpoints: {
                        byFile: homeUrl + 'api/upload-image/',
                        byUrl: homeUrl + 'api/fetch-image/'
                    },
                    field: 'image',
                    types: 'image/jpeg,image/png,image/gif,image/webp',
                    buttonContent: 'Вибрати зображення',
                    captionPlaceholder: ''
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
            scheduleSave();
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

            const handle = document.createElement('div');
            handle.className = 'image-resize-handle';
            imageWrapper.style.position = 'relative';
            imageWrapper.style.display = 'inline-block';
            imageWrapper.appendChild(handle);

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
                }, 300);
            }
        }

        // Add handles to existing images
        document.querySelectorAll('.image-tool__image').forEach(addResizeHandle);

        // Watch for new image blocks
        const editorEl = document.getElementById('editorjs');
        if (editorEl) {
            const observer = new MutationObserver(() => {
                editorEl.querySelectorAll('.image-tool__image').forEach(addResizeHandle);
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
            { name: 'Обличчя', tags: 'smile face happy sad angry cry laugh wink love kiss посмішка радість сум злість плач сміх підморг кохання поцілунок емоції смайл весело сумно хворий маска окуляри розум думати привид робот череп', emojis: ['😀','😃','😄','😁','😆','😅','🤣','😂','🙂','😊','😇','🥰','😍','🤩','😘','😗','😚','😙','🥲','😋','😛','😜','🤪','😝','🤑','🤗','🤭','🤫','🤔','🫡','🤐','🤨','😐','😑','😶','🫥','😏','😒','🙄','😬','🤥','😌','😔','😪','🤤','😴','😷','🤒','🤕','🤢','🤮','🥵','🥶','🥴','😵','🤯','🤠','🥳','🥸','😎','🤓','🧐','😕','🫤','😟','🙁','☹️','😮','😯','😲','😳','🥺','🥹','😦','😧','😨','😰','😥','😢','😭','😱','😖','😣','😞','😓','😩','😫','🥱','😤','😡','😠','🤬','😈','👿','💀','☠️','💩','🤡','👹','👺','👻','👽','👾','🤖'] },
            { name: 'Жести', tags: 'hand wave ok thumbs up down clap pray point fist рука хвиля ок палець вгору вниз оплески молитва кулак мʼязи сила', emojis: ['👋','🤚','🖐️','✋','🖖','🫱','🫲','🫳','🫴','👌','🤌','🤏','✌️','🤞','🫰','🤟','🤘','🤙','👈','👉','👆','🖕','👇','☝️','🫵','👍','👎','✊','👊','🤛','🤜','👏','🙌','🫶','👐','🤲','🤝','🙏','✍️','💪','🦾','🦿'] },
            { name: 'Люди', tags: 'person man woman boy girl baby child old king queen ninja detective guard worker prince princess people людина чоловік жінка хлопець дівчина дитина старий король королева ніндзя детектив лікар робітник принц принцеса', emojis: ['👶','👧','🧒','👦','👩','🧑','👨','👩‍🦱','🧑‍🦱','👨‍🦱','👩‍🦰','🧑‍🦰','👨‍🦰','👱‍♀️','👱','👱‍♂️','👩‍🦳','🧑‍🦳','👨‍🦳','👩‍🦲','🧑‍🦲','👨‍🦲','🧔‍♀️','🧔','🧔‍♂️','👵','🧓','👴','👮','🕵️','💂','🥷','👷','🫅','🤴','👸','🧙','🧝','🧛','🧟','🧞','🧜','👼','🤰','🫃','🤱','🎅','🤶'] },
            { name: 'Тварини', tags: 'animal dog cat bird fish bear lion tiger monkey rabbit fox horse cow pig snake elephant whale dolphin shark тварина собака пес кіт кішка птах риба ведмідь лев тигр мавпа кролик лисиця кінь корова свиня змія слон кит дельфін акула черепаха жаба метелик бджола павук краб динозавр', emojis: ['🐶','🐱','🐭','🐹','🐰','🦊','🐻','🐼','🐻‍❄️','🐨','🐯','🦁','🐮','🐷','🐸','🐵','🙈','🙉','🙊','🐒','🐔','🐧','🐦','🐤','🐣','🐥','🦆','🦅','🦉','🦇','🐺','🐗','🐴','🦄','🐝','🪱','🐛','🦋','🐌','🐞','🐜','🪰','🪲','🪳','🦟','🦗','🕷️','🦂','🐢','🐍','🦎','🦖','🦕','🐙','🦑','🦐','🦞','🦀','🪼','🐡','🐠','🐟','🐬','🐳','🐋','🦈','🪸','🐊','🐅','🐆','🦓','🫏','🦍','🦧','🐘','🦣','🦛','🦏','🐪','🐫','🦒','🦘','🦬','🐃','🐂','🐄','🐎','🐖','🐏','🐑','🦙','🐐','🦌','🫎','🐕','🐩','🦮','🐈','🐈‍⬛','🪿','🐓','🦃','🦤','🦚','🦜','🦢','🦩','🕊️','🐇','🦝','🦨','🦡','🦫','🦦','🦥','🐁','🐀','🐿️','🦔'] },
            { name: 'Природа', tags: 'nature tree flower sun moon star rain snow fire water cloud rainbow plant leaf mushroom weather погода природа дерево квітка сонце місяць зірка дощ сніг вогонь вода хмара веселка рослина листок гриб блискавка океан', emojis: ['🌵','🎄','🌲','🌳','🌴','🪹','🌱','🌿','☘️','🍀','🎍','🪴','🎋','🍃','🍂','🍁','🪺','🪻','🌾','🌺','🌻','🌹','🥀','🌷','🌼','💐','🍄','🌰','🐚','🪨','🌎','🌍','🌏','🌕','🌖','🌗','🌘','🌑','🌒','🌓','🌔','🌙','🌚','🌝','🌛','🌜','☀️','🌞','⭐','🌟','💫','✨','🌈','☁️','⛅','⛈️','🌤️','🌥️','🌦️','🌧️','🌨️','🌩️','⚡','🔥','💥','❄️','🌊','💧','💦','🫧'] },
            { name: 'Їжа', tags: 'food fruit vegetable meat bread pizza burger sushi cake coffee tea drink beer wine їжа фрукт овоч мʼясо хліб піца бургер суші торт кава чай напій пиво вино яблуко банан виноград полуниця морозиво шоколад цукерка їсти ресторан кухня', emojis: ['🍏','🍎','🍐','🍊','🍋','🍌','🍉','🍇','🍓','🫐','🍈','🍒','🍑','🥭','🍍','🥥','🥝','🍅','🍆','🥑','🫛','🥦','🥬','🥒','🌶️','🫑','🌽','🥕','🫒','🧄','🧅','🫚','🥔','🍠','🫘','🥐','🥖','🍞','🥨','🥯','🧀','🥚','🍳','🧈','🥞','🧇','🥓','🥩','🍗','🍖','🌭','🍔','🍟','🍕','🫓','🥪','🥙','🧆','🌮','🌯','🫔','🥗','🥘','🫕','🍝','🍜','🍲','🍛','🍣','🍱','🥟','🦪','🍤','🍙','🍚','🍘','🍥','🥠','🥮','🍢','🍡','🍧','🍨','🍦','🥧','🧁','🍰','🎂','🍮','🍭','🍬','🍫','🍿','🍩','🍪','🌰','🥜','🫘','🍯','🥛','☕','🫖','🍵','🧃','🥤','🧋','🍶','🍺','🍻','🥂','🍷','🫗','🥃','🍸','🍹','🧉','🍾','🧊'] },
            { name: 'Спорт', tags: 'sport ball football soccer basketball tennis golf hockey ski swim run fitness gym medal trophy спорт мʼяч футбол баскетбол теніс гольф хокей лижі плавання біг фітнес зал медаль трофей кубок бокс', emojis: ['⚽','🏀','🏈','⚾','🥎','🎾','🏐','🏉','🥏','🎱','🪀','🏓','🏸','🏒','🏑','🥍','🏏','🪃','🥅','⛳','🪁','🏹','🎣','🤿','🥊','🥋','🎽','🛹','🛼','🛷','⛸️','🥌','🎿','⛷️','🏂','🪂','🏋️','🤸','🤼','🤽','🤾','🤺','🏄','🏊','🚣','🧘','🏇','🚴','🚵','🎖️','🏅','🥇','🥈','🥉','🏆'] },
            { name: 'Подорожі', tags: 'travel transport car auto vehicle bus train plane ship boat house building city авто машина автомобіль транспорт автобус потяг поїзд літак корабель човен будинок місто подорож таксі мотоцикл велосипед ракета вертоліт аеропорт замок церква гори пляж car truck taxi motorcycle bike rocket helicopter airport castle', emojis: ['🚗','🚕','🚙','🚌','🚎','🏎️','🚓','🚑','🚒','🚐','🛻','🚚','🚛','🚜','🛵','🏍️','🛺','🚲','🛴','🚏','🛣️','🛤️','🛞','🚨','🚥','🚦','🛑','🚧','⚓','🛟','⛵','🚤','🛥️','🛳️','⛴️','🚢','✈️','🛩️','🛫','🛬','🪂','💺','🚁','🚟','🚠','🚡','🛰️','🚀','🛸','🏠','🏡','🏢','🏣','🏤','🏥','🏦','🏨','🏩','🏪','🏫','🏬','🏭','🏯','🏰','💒','🗼','🗽','⛪','🕌','🛕','🕍','⛩️','🕋','⛲','⛺','🏕️','🌁','🌃','🏙️','🌄','🌅','🌆','🌇','🎠','🎡','🎢','🗻','🏔️','🌋','🗾','🏖️','🏜️','🏝️','🧭','🗺️','🌐','🌍','🌎','🌏'] },
            { name: 'Обʼєкти', tags: 'object phone computer laptop camera tv clock watch light tool key lock money book pen pencil обʼєкт телефон компʼютер ноутбук камера телевізор годинник ліхтар інструмент ключ замок гроші книга ручка олівець лампа батарея ножиці пошта лист конверт коробка подарунок', emojis: ['📱','💻','🖥️','🖨️','⌨️','🖱️','🖲️','💾','💿','📀','📼','📷','📸','📹','🎥','📽️','🎞️','📞','☎️','📟','📠','📺','📻','🎙️','🎚️','🎛️','🧭','⏱️','⏲️','⏰','🕰️','⌛','⏳','📡','🔋','🪫','🔌','💡','🔦','🕯️','🧯','🛢️','🪙','💵','💴','💶','💷','💰','💳','💎','⚖️','🪜','🧰','🪛','🔧','🔨','⚒️','🛠️','⛏️','🪚','🔩','⚙️','🪤','🧱','⛓️','🧲','🔫','💣','🪓','🔪','🗡️','⚔️','🛡️','🚬','⚰️','🪦','⚱️','🏺','🔮','📿','🧿','🪬','💈','⚗️','🔭','🔬','🕳️','🩹','🩺','🩻','🩼','💊','💉','🩸','🧬','🦠','🧫','🧪','🌡️','🧹','🪠','🧺','🧻','🚽','🚰','🚿','🛁','🛀','🧼','🪥','🪒','🪮','🧽','🪣','🧴','🛎️','🔑','🗝️','🚪','🪑','🛋️','🛏️','🛌','🧸','🪆','🖼️','🪞','🪟','🛍️','🛒','🎁','🎈','🎏','🎀','🪄','🪅','🎊','🎉','🎎','🏮','🎐','🧧','✉️','📩','📨','📧','💌','📥','📤','📦','🏷️','🪧','📪','📫','📬','📭','📮','📯','📜','📃','📄','📑','🧾','📊','📈','📉','🗒️','🗓️','📆','📅','🗑️','📇','🗃️','🗳️','🗄️','📋','📁','📂','🗂️','🗞️','📰','📓','📔','📒','📕','📗','📘','📙','📚','📖','🔖','🧷','🔗','📎','🖇️','📐','📏','🧮','📌','📍','✂️','🖊️','🖋️','✒️','🖌️','🖍️','📝','✏️','🔍','🔎','🔏','🔐','🔒','🔓'] },
            { name: 'Символи', tags: 'symbol heart love check cross arrow circle square number music note warning sign символ серце кохання любов галочка хрест стрілка коло квадрат номер музика нота попередження знак зодіак', emojis: ['❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❤️‍🔥','❤️‍🩹','❣️','💕','💞','💓','💗','💖','💘','💝','💟','☮️','✝️','☪️','🕉️','☸️','✡️','🔯','🕎','☯️','☦️','🛐','⛎','♈','♉','♊','♋','♌','♍','♎','♏','♐','♑','♒','♓','🆔','⚛️','🉑','☢️','☣️','📴','📳','🈶','🈚','🈸','🈺','🈷️','✴️','🆚','💮','🉐','㊙️','㊗️','🈴','🈵','🈹','🈲','🅰️','🅱️','🆎','🆑','🅾️','🆘','❗','❕','❓','❔','‼️','⁉️','🔅','🔆','〽️','⚠️','🚸','🔱','⚜️','🔰','♻️','✅','🈯','💹','❇️','✳️','❎','🌐','💠','Ⓜ️','🌀','💤','🏧','🚾','♿','🅿️','🛗','🈳','🈂️','🛂','🛃','🛄','🛅','🚹','🚺','🚼','⚧️','🚻','🚮','🎦','📶','🈁','🔣','ℹ️','🔤','🔡','🔠','🆖','🆗','🆙','🆒','🆕','🆓','0️⃣','1️⃣','2️⃣','3️⃣','4️⃣','5️⃣','6️⃣','7️⃣','8️⃣','9️⃣','🔟','🔢','#️⃣','*️⃣','⏏️','▶️','⏸️','⏯️','⏹️','⏺️','⏭️','⏮️','⏩','⏪','⏫','⏬','◀️','🔼','🔽','➡️','⬅️','⬆️','⬇️','↗️','↘️','↙️','↖️','↕️','↔️','↪️','↩️','⤴️','⤵️','🔀','🔁','🔂','🔄','🔃','🎵','🎶','➕','➖','➗','✖️','🟰','♾️','💲','💱','™️','©️','®️','〰️','➰','➿','🔚','🔙','🔛','🔝','🔜','✔️','☑️','🔘','🔴','🟠','🟡','🟢','🔵','🟣','⚫','⚪','🟤','🔺','🔻','🔸','🔹','🔶','🔷','🔳','🔲','▪️','▫️','◾','◽','◼️','◻️','🟥','🟧','🟨','🟩','🟦','🟪','⬛','⬜','🟫','🔈','🔇','🔉','🔊','🔔','🔕','📣','📢','💬','💭','🗯️','♠️','♣️','♥️','♦️','🃏','🎴','🀄','🕐','🕑','🕒','🕓','🕔','🕕','🕖','🕗','🕘','🕙','🕚','🕛'] },
            { name: 'Прапори', tags: 'flag country україна сша британія німеччина франція прапор країна ukraine usa uk germany france japan korea flag nation', emojis: ['🏳️','🏴','🏁','🚩','🏳️‍🌈','🏳️‍⚧️','🇺🇦','🇺🇸','🇬🇧','🇩🇪','🇫🇷','🇪🇸','🇮🇹','🇵🇱','🇯🇵','🇰🇷','🇨🇳','🇧🇷','🇨🇦','🇦🇺','🇮🇳','🇹🇷','🇳🇱','🇸🇪','🇳🇴','🇩🇰','🇫🇮','🇨🇿','🇦🇹','🇨🇭','🇧🇪','🇵🇹','🇬🇷','🇷🇴','🇭🇺','🇭🇷','🇸🇰','🇧🇬','🇱🇹','🇱🇻','🇪🇪','🇬🇪','🇲🇩','🇦🇿','🇦🇲','🇰🇿','🇺🇿','🇮🇱','🇦🇪','🇸🇦','🇪🇬','🇿🇦','🇳🇬','🇰🇪','🇲🇽','🇦🇷','🇨🇱','🇨🇴','🇵🇪','🇻🇪','🇹🇭','🇻🇳','🇮🇩','🇲🇾','🇵🇭','🇸🇬','🇳🇿'] }
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

            function buildEmojiGrid(filter) {
                emojiContent.innerHTML = '';
                const q = (filter || '').toLowerCase();
                emojiCategories.forEach(cat => {
                    const catMatch = !q || cat.name.toLowerCase().includes(q) || (cat.tags && cat.tags.toLowerCase().includes(q));
                    const matched = catMatch ? cat.emojis : [];
                    if (!matched.length) return;

                    const label = document.createElement('div');
                    label.className = 'note-icon-emoji-category';
                    label.textContent = cat.name;
                    emojiContent.appendChild(label);

                    const grid = document.createElement('div');
                    grid.className = 'note-icon-emoji-grid';
                    matched.forEach(em => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'note-icon-emoji-btn';
                        btn.textContent = em;
                        btn.addEventListener('click', () => {
                            currentIcon = em;
                            updateIconButton();
                            closePicker();
                            scheduleSave();
                        });
                        grid.appendChild(btn);
                    });
                    emojiContent.appendChild(grid);
                });
            }

            buildEmojiGrid('');
            searchInput.addEventListener('input', () => buildEmojiGrid(searchInput.value));

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
