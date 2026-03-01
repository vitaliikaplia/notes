// Sidebar
document.addEventListener('DOMContentLoaded', () => {
    const homeUrl = document.body.dataset.homeUrl || '/';

    // Folder toggle with persistent state
    const collapsedKey = 'sidebar-collapsed';
    let collapsed = JSON.parse(localStorage.getItem(collapsedKey) || '[]');

    // Restore collapsed state on load
    document.querySelectorAll('.sidebar-folder[data-slug]').forEach(folder => {
        if (collapsed.includes(folder.dataset.slug)) {
            folder.classList.add('collapsed');
        }
    });
    // Remove early-hide style now that classes are applied
    const initStyle = document.getElementById('collapsed-init');
    if (initStyle) initStyle.remove();

    // Mark active note in sidebar and auto-expand ancestors
    const currentUrl = window.location.origin + window.location.pathname.replace(/\/$/, '') + '/';
    let activeLink = null;
    document.querySelectorAll('.sidebar-note, .sidebar-note-parent').forEach(link => {
        if (link.href === currentUrl) activeLink = link;
    });
    if (activeLink) {
        activeLink.classList.add('active');
        const container = activeLink.closest('.sidebar-note-item') || activeLink.closest('.sidebar-folder');
        if (container) container.classList.add('active');
        let parent = activeLink.closest('.sidebar-folder');
        let changed = false;
        while (parent) {
            if (parent.classList.contains('collapsed')) {
                parent.classList.remove('collapsed');
                changed = true;
            }
            parent = parent.parentElement.closest('.sidebar-folder');
        }
        if (changed) {
            const slugs = [];
            document.querySelectorAll('.sidebar-folder.collapsed[data-slug]').forEach(f => slugs.push(f.dataset.slug));
            localStorage.setItem(collapsedKey, JSON.stringify(slugs));
        }
    }

    function saveCollapsedState() {
        const slugs = [];
        document.querySelectorAll('.sidebar-folder.collapsed[data-slug]').forEach(f => {
            slugs.push(f.dataset.slug);
        });
        localStorage.setItem(collapsedKey, JSON.stringify(slugs));
    }

    // Collapse/Expand all folders
    const collapseAllBtn = document.getElementById('collapseAllFolders');

    function updateCollapseAllBtn() {
        if (!collapseAllBtn) return;
        const allFolders = document.querySelectorAll('.sidebar-folder');
        const collapsedFolders = document.querySelectorAll('.sidebar-folder.collapsed');
        collapseAllBtn.classList.toggle('collapsed',
            allFolders.length > 0 && collapsedFolders.length === allFolders.length);
    }

    if (collapseAllBtn) {
        collapseAllBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const allFolders = document.querySelectorAll('.sidebar-folder');
            const allCollapsed = document.querySelectorAll('.sidebar-folder.collapsed').length === allFolders.length;

            allFolders.forEach(f => {
                if (allCollapsed) {
                    f.classList.remove('collapsed');
                } else {
                    f.classList.add('collapsed');
                }
            });

            saveCollapsedState();
            updateCollapseAllBtn();
        });

        updateCollapseAllBtn();
    }

    document.querySelectorAll('[data-toggle="folder"]').forEach(el => {
        el.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            el.closest('.sidebar-folder').classList.toggle('collapsed');
            saveCollapsedState();
            updateCollapseAllBtn();
        });
    });

    // Toast notification (use global)
    const showToast = window.showToast;

    // Visibility toggle (private → unlisted → public → private)
    document.querySelectorAll('.sidebar-note-visibility').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            e.stopPropagation();

            const cycle = ['private', 'unlisted', 'public'];
            const current = btn.dataset.visibility || 'private';
            const next = cycle[(cycle.indexOf(current) + 1) % cycle.length];

            const response = await fetch(homeUrl + 'api/visibility/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ path: btn.dataset.path, visibility: next })
            });

            const result = await response.json();
            if (result.success) {
                btn.dataset.visibility = next;

                // Copy URL to clipboard when switching to unlisted or public
                if (next === 'unlisted' || next === 'public') {
                    const link = btn.closest('.sidebar-folder-header')?.querySelector('.sidebar-note-parent')
                              || btn.closest('.sidebar-note-item')?.querySelector('.sidebar-note');
                    if (link) {
                        const url = link.href;
                        await navigator.clipboard.writeText(url).catch(() => {});
                        const label = next === 'unlisted' ? 'за посиланням' : 'для всіх';
                        showToast('Посилання скопійовано — доступ ' + label);
                    }
                }
            }
        });
    });

    // Delete note from sidebar (double-click confirm)
    let deleteTimer = null;
    document.querySelectorAll('.sidebar-note-delete').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            e.stopPropagation();
            const path = btn.dataset.path;
            if (!path) return;

            if (!btn.classList.contains('confirm')) {
                // First click — arm
                document.querySelectorAll('.sidebar-note-delete.confirm').forEach(b => b.classList.remove('confirm'));
                btn.classList.add('confirm');
                if (deleteTimer) clearTimeout(deleteTimer);
                deleteTimer = setTimeout(() => btn.classList.remove('confirm'), 3000);
                return;
            }

            // Second click — delete
            btn.classList.remove('confirm');
            const response = await fetch(homeUrl + 'api/delete/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ path })
            });
            const result = await response.json();
            if (result.success) {
                // Check if we're currently viewing the deleted note
                const noteUrl = 'note/' + path.replace(/\.json$/, '');
                if (window.location.pathname.includes(noteUrl)) {
                    window.location.href = homeUrl;
                } else {
                    // Remove from sidebar without redirect
                    const item = btn.closest('.sidebar-note-item') || btn.closest('.sidebar-folder');
                    if (item) item.remove();

                    // Remove card from dashboard grid/list
                    const cardUrl = homeUrl + noteUrl + '/';
                    const card = document.querySelector('.note-card[href="' + cardUrl + '"]');
                    if (card) card.remove();
                }
            } else {
                alert('Помилка видалення');
            }
        });
    });

    // Drag-and-drop reordering (cross-level)
    let dragEl = null;
    let dragContainer = null;
    let dragPath = null;
    let autoExpandTimer = null;
    let autoExpandTarget = null;

    const sidebarNav = document.querySelector('.sidebar-nav');

    function getDraggableChildren(container) {
        return Array.from(container.children).filter(el => el.hasAttribute('draggable'));
    }

    function clearDragIndicators() {
        document.querySelectorAll('.drag-over-top, .drag-over-bottom, .drag-over-inside').forEach(el => {
            el.classList.remove('drag-over-top', 'drag-over-bottom', 'drag-over-inside');
        });
    }

    function clearAutoExpand() {
        if (autoExpandTimer) {
            clearTimeout(autoExpandTimer);
            autoExpandTimer = null;
        }
        autoExpandTarget = null;
    }

    function startAutoExpand(folder) {
        if (autoExpandTarget === folder) return;
        clearAutoExpand();
        autoExpandTarget = folder;
        autoExpandTimer = setTimeout(() => {
            folder.classList.remove('collapsed');
            saveCollapsedState();
            updateCollapseAllBtn();
            autoExpandTarget = null;
        }, 600);
    }

    function getDropInfo(e) {
        const elUnder = document.elementFromPoint(e.clientX, e.clientY);
        if (!elUnder) return { mode: null };

        const item = elUnder.closest('.sidebar-note-item[draggable="true"], .sidebar-folder[draggable="true"]');
        if (!item || item === dragEl || dragEl.contains(item)) return { mode: null };

        const rect = item.getBoundingClientRect();
        const relativeY = e.clientY - rect.top;
        const threshold = rect.height * 0.25;
        const container = item.closest('[data-sortable]');

        if (relativeY < threshold) {
            return { mode: 'reorder', item, container, position: 'before' };
        } else if (relativeY > rect.height - threshold) {
            return { mode: 'reorder', item, container, position: 'after' };
        } else {
            return { mode: 'nest', item, container };
        }
    }

    function performMove(sourcePath, targetFolder, position) {
        // Prevent circular: cannot move into self or own descendants
        const sourceFolder = sourcePath.replace(/\.json$/, '');
        if (targetFolder === sourceFolder || targetFolder.startsWith(sourceFolder + '/')) {
            return;
        }

        fetch(homeUrl + 'api/move/', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                source_path: sourcePath,
                target_folder: targetFolder,
                position: position
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // If viewing the moved note, redirect to new URL
                const oldUrl = 'note/' + sourcePath.replace(/\.json$/, '');
                if (window.location.pathname.includes(oldUrl)) {
                    window.location.href = homeUrl + 'note/' + data.new_path.replace(/\.json$/, '') + '/';
                } else {
                    window.location.reload();
                }
            } else {
                console.error('Move failed:', data.error);
            }
        })
        .catch(err => console.error('Move error:', err));
    }

    // Prevent native link/button drag inside sortable items
    document.querySelectorAll('[data-sortable] a, [data-sortable] button').forEach(el => {
        el.setAttribute('draggable', 'false');
    });

    // Per-container: dragstart only
    document.querySelectorAll('[data-sortable]').forEach(container => {
        container.addEventListener('dragstart', (e) => {
            const item = e.target.closest('.sidebar-note-item[draggable="true"], .sidebar-folder[draggable="true"]');
            if (!item || item.closest('[data-sortable]') !== container) return;
            dragEl = item;
            dragContainer = container;
            dragPath = item.dataset.path || null;
            item.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', '');

            // Store note data for editor drop (page block insertion)
            const noteLink = item.querySelector('.sidebar-note, .sidebar-note-parent');
            if (noteLink) {
                const iconEl = item.querySelector('.sidebar-note-icon__icon') || item.querySelector('.sidebar-note-icon');
                // Clone link and remove icon span to get clean title
                const titleClone = noteLink.cloneNode(true);
                const iconInClone = titleClone.querySelector('.sidebar-note-icon');
                if (iconInClone) iconInClone.remove();
                e.dataTransfer.setData('application/x-note', JSON.stringify({
                    title: titleClone.textContent.trim(),
                    path: item.dataset.path || '',
                    url: noteLink.getAttribute('href') || '',
                    icon: iconEl ? iconEl.textContent.trim() : ''
                }));
            }
        });
    });

    // Document-level: dragover
    document.addEventListener('dragover', (e) => {
        if (!dragEl || !sidebarNav || !sidebarNav.contains(e.target)) return;

        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';

        clearDragIndicators();

        const info = getDropInfo(e);

        if (info.mode === 'reorder') {
            clearAutoExpand();
            info.item.classList.add(info.position === 'before' ? 'drag-over-top' : 'drag-over-bottom');
        } else if (info.mode === 'nest') {
            info.item.classList.add('drag-over-inside');
            // Auto-expand collapsed folders on hover
            const folder = info.item.closest('.sidebar-folder');
            if (folder && folder.classList.contains('collapsed') && folder !== dragEl) {
                startAutoExpand(folder);
            } else {
                clearAutoExpand();
            }
        } else {
            clearAutoExpand();
        }
    });

    // Document-level: drop
    document.addEventListener('drop', (e) => {
        if (!dragEl || !sidebarNav || !sidebarNav.contains(e.target)) return;

        e.preventDefault();
        clearDragIndicators();
        clearAutoExpand();

        const info = getDropInfo(e);
        if (!info.mode) return;

        if (info.mode === 'reorder' && info.container === dragContainer) {
            // Same container reorder — DOM update, no reload
            const container = info.container;
            if (info.position === 'before') {
                container.insertBefore(dragEl, info.item);
            } else {
                container.insertBefore(dragEl, info.item.nextSibling);
            }

            const slugs = getDraggableChildren(container).map(el => el.dataset.slug).filter(Boolean);
            const folder = container.dataset.sortable;

            fetch(homeUrl + 'api/reorder/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ folder: folder, order: slugs })
            });

        } else if (info.mode === 'reorder' && info.container !== dragContainer && dragPath) {
            // Cross-container reorder — move to different level at specific position
            const targetFolder = info.container.dataset.sortable;
            const position = info.position === 'before'
                ? { before_slug: info.item.dataset.slug }
                : { after_slug: info.item.dataset.slug };

            performMove(dragPath, targetFolder, position);

        } else if (info.mode === 'nest' && dragPath) {
            // Nest inside target note
            const targetPath = info.item.dataset.path;
            if (!targetPath) return;
            const targetFolder = targetPath.replace(/\.json$/, '');

            performMove(dragPath, targetFolder, null);
        }
    });

    // Document-level: dragleave
    document.addEventListener('dragleave', (e) => {
        if (sidebarNav && !sidebarNav.contains(e.relatedTarget)) {
            clearDragIndicators();
            clearAutoExpand();
        }
    });

    // Document-level: dragend
    document.addEventListener('dragend', () => {
        if (dragEl) {
            dragEl.classList.remove('dragging');
        }
        clearDragIndicators();
        clearAutoExpand();
        dragEl = null;
        dragContainer = null;
        dragPath = null;
    });
});
