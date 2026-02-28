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

    // Drag-and-drop reordering
    let dragEl = null;
    let dragContainer = null;

    function getDraggableChildren(container) {
        return Array.from(container.children).filter(el => el.hasAttribute('draggable'));
    }

    function clearDragIndicators() {
        document.querySelectorAll('.drag-over-top, .drag-over-bottom').forEach(el => {
            el.classList.remove('drag-over-top', 'drag-over-bottom');
        });
    }

    function getInsertInfo(container, y) {
        const children = getDraggableChildren(container).filter(el => el !== dragEl);
        let closest = null;
        let position = 'after'; // 'before' or 'after'
        let minDist = Infinity;

        for (const child of children) {
            const rect = child.getBoundingClientRect();
            const midY = rect.top + rect.height / 2;
            const dist = Math.abs(y - midY);
            if (dist < minDist) {
                minDist = dist;
                closest = child;
                position = y < midY ? 'before' : 'after';
            }
        }

        return { closest, position };
    }

    // Prevent native link/button drag inside sortable items
    document.querySelectorAll('[data-sortable] a, [data-sortable] button').forEach(el => {
        el.setAttribute('draggable', 'false');
    });

    document.querySelectorAll('[data-sortable]').forEach(container => {
        container.addEventListener('dragstart', (e) => {
            const item = e.target.closest('.sidebar-note-item[draggable="true"], .sidebar-folder[draggable="true"]');
            if (!item || item.closest('[data-sortable]') !== container) return;
            dragEl = item;
            dragContainer = container;
            item.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', '');
        });

        container.addEventListener('dragover', (e) => {
            if (!dragEl || dragContainer !== container) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';

            clearDragIndicators();
            const { closest, position } = getInsertInfo(container, e.clientY);
            if (closest) {
                closest.classList.add(position === 'before' ? 'drag-over-top' : 'drag-over-bottom');
            }
        });

        container.addEventListener('dragleave', (e) => {
            if (!container.contains(e.relatedTarget)) {
                clearDragIndicators();
            }
        });

        container.addEventListener('drop', (e) => {
            e.preventDefault();
            if (!dragEl || dragContainer !== container) return;

            clearDragIndicators();

            const { closest, position } = getInsertInfo(container, e.clientY);
            if (closest) {
                if (position === 'before') {
                    container.insertBefore(dragEl, closest);
                } else {
                    container.insertBefore(dragEl, closest.nextSibling);
                }
            }

            // Collect new order and save
            const slugs = getDraggableChildren(container).map(el => el.dataset.slug).filter(Boolean);
            const folder = container.dataset.sortable;

            fetch(homeUrl + 'api/reorder/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ folder: folder, order: slugs })
            });
        });

        container.addEventListener('dragend', () => {
            if (dragEl) {
                dragEl.classList.remove('dragging');
            }
            clearDragIndicators();
            dragEl = null;
            dragContainer = null;
        });
    });
});
