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

    // --- MCP token management ---

    function renderMcpStatus(status, plainToken) {
        var c = content();
        if (!c) return;

        var statusBox = c.querySelector('.js-mcp-status');
        var tokenBox = c.querySelector('.js-mcp-token');
        var tokenValue = c.querySelector('.js-mcp-token-value');
        var generateBtn = c.querySelector('.js-mcp-generate');
        var revokeBtn = c.querySelector('.js-mcp-revoke');
        if (!statusBox || !generateBtn) return;

        var configured = !!(status && status.configured);

        if (configured) {
            var text = 'Token active';
            if (status.hint) text += ': ' + status.hint;
            if (status.created_at) text += ' (created ' + status.created_at.slice(0, 10) + ')';
            statusBox.textContent = text;
        } else {
            statusBox.textContent = 'No token — MCP server is disabled.';
        }

        generateBtn.textContent = configured ? 'Regenerate token' : 'Generate token';
        if (revokeBtn) revokeBtn.hidden = !configured;

        if (tokenBox && tokenValue) {
            if (plainToken) {
                tokenValue.textContent = plainToken;
                tokenBox.hidden = false;
            } else {
                tokenValue.textContent = '';
                tokenBox.hidden = true;
            }
        }
    }

    function mcpTokenRequest(op) {
        showError('');
        fetch(homeUrl + 'api/mcp-token', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ op: op })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    showError((data && data.error) || 'Could not update the MCP token');
                    return;
                }
                renderMcpStatus(data.status, data.token || null);
                if (window.showToast) {
                    window.showToast(op === 'revoke' ? 'MCP token revoked' : 'MCP token generated — copy it now');
                }
            })
            .catch(function () {
                showError('Could not update the MCP token');
            });
    }

    function copyMcpToken() {
        var c = content();
        var value = c && c.querySelector('.js-mcp-token-value');
        var token = value ? value.textContent : '';
        if (!token) return;

        var done = function () {
            if (window.showToast) window.showToast('Token copied');
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(token).then(done).catch(function () {});
        } else {
            var range = document.createRange();
            range.selectNodeContents(value);
            var selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            try { document.execCommand('copy'); done(); } catch (e) {}
            selection.removeAllRanges();
        }
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
                renderMcpStatus(data.mcp_token || null, null);
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
            return;
        }

        if (event.target.closest('.js-popup-content')) {
            if (event.target.closest('.js-mcp-generate')) {
                event.preventDefault();
                var configured = !content().querySelector('.js-mcp-revoke').hidden;
                if (!configured || window.confirm('Generating a new token invalidates the current one. Continue?')) {
                    mcpTokenRequest('generate');
                }
                return;
            }
            if (event.target.closest('.js-mcp-revoke')) {
                event.preventDefault();
                if (window.confirm('Revoke the MCP token? Connected clients will lose access.')) {
                    mcpTokenRequest('revoke');
                }
                return;
            }
            if (event.target.closest('.js-mcp-copy')) {
                event.preventDefault();
                copyMcpToken();
            }
        }
    });

    document.addEventListener('change', function (event) {
        var select = event.target.closest('select[name="AI_PROVIDER"]');
        if (select && select.closest('.js-popup-content')) {
            applyAiVisibility();
        }
    });
})();
