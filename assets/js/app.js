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
        if (currentIcon && !currentIcon.startsWith('<svg')) {
            // Emoji
            iconBtnEl.innerHTML = '<span class="note-icon-emoji">' + currentIcon + '</span>';
            iconBtnEl.classList.add('has-icon');
        } else if (currentIcon && currentIcon.startsWith('<svg')) {
            // Custom SVG
            iconBtnEl.innerHTML = currentIcon;
            iconBtnEl.classList.add('has-icon');
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
            const title = titleEl.value.trim() || 'Без назви';
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
                const noteTitle = titleEl.value.trim() || 'Без назви';
                const noteUrl = homeUrl + result.url + '/';
                const sidebarLink = document.querySelector('.sidebar-note[href="' + noteUrl + '"], .sidebar-note-parent[href="' + noteUrl + '"]');
                if (sidebarLink) {
                    const iconEl = sidebarLink.querySelector('.sidebar-note-icon');
                    if (sidebarLink.classList.contains('sidebar-note')) {
                        // Flat note link — has icon inside
                        const iconHtml = currentIcon
                            ? '<span class="sidebar-note-icon">' + currentIcon + '</span>'
                            : '<span class="sidebar-note-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>';
                        sidebarLink.innerHTML = iconHtml + ' ' + noteTitle;
                    } else {
                        // Parent note link — icon is a sibling before the link
                        sidebarLink.textContent = noteTitle;
                        const header = sidebarLink.closest('.sidebar-folder-header');
                        if (header) {
                            const noteIconEl = header.querySelector('.sidebar-note-icon__icon');
                            if (noteIconEl) {
                                noteIconEl.innerHTML = currentIcon
                                    ? currentIcon
                                    : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
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

            function buildEmojiGrid(filter) {
                emojiContent.innerHTML = '';
                const q = (filter || '').toLowerCase();
                emojiCategories.forEach(cat => {
                    const matched = q ? cat.emojis.filter(em => cat.name.toLowerCase().includes(q)) : cat.emojis;
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
                    let svgText = evt.target.result.trim();
                    if (!svgText.startsWith('<svg')) {
                        alert('Невалідний SVG файл');
                        return;
                    }
                    // Normalize size
                    svgText = svgText.replace(/<svg([^>]*)>/, (match, attrs) => {
                        let cleaned = attrs.replace(/\s*(width|height)="[^"]*"/g, '');
                        return '<svg' + cleaned + ' width="28" height="28">';
                    });
                    currentIcon = svgText;
                    updateIconButton();
                    closePicker();
                    scheduleSave();
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

            // Reset button
            const resetBtn = document.createElement('button');
            resetBtn.type = 'button';
            resetBtn.className = 'note-icon-reset';
            resetBtn.textContent = 'Скинути';
            resetBtn.addEventListener('click', () => {
                currentIcon = '';
                updateIconButton();
                closePicker();
                scheduleSave();
            });
            picker.appendChild(resetBtn);

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
            const title = titleEl.value.trim() || 'untitled';

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
