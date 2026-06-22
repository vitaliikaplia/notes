/**
 * Options popup — opens the settings dialog (built on the popup engine),
 * handles tab switching and saving all settings in one request.
 */
(function () {
    'use strict';

    var homeUrl = document.body.dataset.homeUrl || '/';

    // Show a one-shot toast queued before a reload (e.g. after saving settings)
    try {
        var flash = sessionStorage.getItem('options-flash');
        if (flash) {
            sessionStorage.removeItem('options-flash');
            if (window.showToast) window.showToast(flash);
        }
    } catch (e) {}

    function content() {
        return document.querySelector('.js-popup-content');
    }

    function showError(message) {
        var box = content() && content().querySelector('.options-error');
        if (!box) return;
        box.textContent = message || '';
        box.hidden = !message;
    }

    function switchTab(name) {
        var c = content();
        if (!c) return;
        c.querySelectorAll('.options-tab').forEach(function (tab) {
            tab.classList.toggle('active', tab.dataset.tab === name);
        });
        c.querySelectorAll('.options-panel').forEach(function (panel) {
            panel.classList.toggle('active', panel.dataset.panel === name);
        });
    }

    function saveOptions() {
        var c = content();
        if (!c) return;
        showError('');

        var payload = {};
        c.querySelectorAll('input[name], select[name]').forEach(function (el) {
            payload[el.name] = el.value;
        });

        fetch(homeUrl + 'api/save-options', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    showError((data && data.error) || 'Could not save settings');
                    return;
                }

                // On the dashboard the AI tab is server-rendered from ai_configured;
                // if its visibility changed, reload to reflect it (no unsaved note content here).
                var onDashboard = document.body.classList.contains('page-index');
                var aiTabShown = !!document.querySelector('.index-tabs .index-tab[data-tab="ai"]');
                if (onDashboard && data.ai_configured !== aiTabShown) {
                    try { sessionStorage.setItem('options-flash', 'Settings saved'); } catch (e) {}
                    window.location.reload();
                    return;
                }

                window.Popup.close(true);
                if (window.showToast) window.showToast('Settings saved');
            })
            .catch(function () {
                showError('Could not save settings');
            });
    }

    // Conditional logic: AI sub-fields are only relevant when a provider is selected
    function applyAiVisibility() {
        var c = content();
        if (!c) return;
        var select = c.querySelector('[name="AI_PROVIDER"]');
        var fields = c.querySelector('.options-ai-fields');
        if (!select || !fields) return;
        fields.classList.toggle('is-hidden', select.value === '');
    }

    function loadOptions() {
        var c = content();
        if (!c) return;

        fetch(homeUrl + 'api/options')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data) return;
                Object.keys(data).forEach(function (name) {
                    var field = c.querySelector('[name="' + name + '"]');
                    if (field) field.value = data[name];
                });
                applyAiVisibility();
            })
            .catch(function () {});
    }

    function openOptions() {
        if (!window.Popup) return;
        window.Popup.open({
            title: 'Options',
            content: '#optionsTemplate',
            size: 'medium',
            showClose: true,
            buttons: [
                { label: 'Save', style: 'primary', onClick: saveOptions }
            ]
        });
        // Populate fields with the current settings (fresh each open, no stale DOM)
        loadOptions();
    }

    document.addEventListener('click', function (event) {
        if (event.target.closest('.options-trigger')) {
            event.preventDefault();
            openOptions();
            return;
        }

        var tab = event.target.closest('.options-tab');
        if (tab && tab.closest('.js-popup-content')) {
            event.preventDefault();
            switchTab(tab.dataset.tab);
        }
    });

    document.addEventListener('change', function (event) {
        var select = event.target.closest('select[name="AI_PROVIDER"]');
        if (select && select.closest('.js-popup-content')) {
            applyAiVisibility();
        }
    });
})();
