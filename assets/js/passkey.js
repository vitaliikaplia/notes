/**
 * Passkeys (WebAuthn) — Touch ID / Face ID / Windows Hello / security keys.
 * Registration and removal live in the Options popup (Account tab); sign-in on the login page.
 * Server side: core/includes/passkeys.php.
 */
(function () {
    'use strict';

    var homeUrl = document.body.dataset.homeUrl || '/';
    var supported = !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.create);

    function b64urlToBuffer(value) {
        var base64 = String(value || '').replace(/-/g, '+').replace(/_/g, '/');
        while (base64.length % 4) base64 += '=';
        var binary = atob(base64);
        var bytes = new Uint8Array(binary.length);
        for (var i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
        return bytes.buffer;
    }

    function bufferToB64url(buffer) {
        var bytes = new Uint8Array(buffer);
        var binary = '';
        for (var i = 0; i < bytes.byteLength; i++) binary += String.fromCharCode(bytes[i]);
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function postJson(action, payload) {
        return fetch(homeUrl + 'api/' + action + '/', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload || {})
        }).then(function (r) { return r.json(); });
    }

    function toast(message) {
        if (window.showToast) window.showToast(message);
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // WebAuthn errors are DOMExceptions with a name, not a readable message
    function errorMessage(err, fallback) {
        if (!err) return fallback;
        if (err.name === 'InvalidStateError') return 'This passkey is already registered on this device';
        if (err.name === 'NotAllowedError' || err.name === 'AbortError') return 'Cancelled or timed out';
        if (err.name === 'SecurityError') return 'Passkeys need a secure (https) origin';
        if (err.name === 'NotSupportedError') return 'This browser does not support passkeys';
        if (err.message && err.message !== 'cancelled' && !err.name) return err.message;
        return fallback;
    }

    // The server sends challenge / ids as base64url strings; the browser wants ArrayBuffers
    function decodeCreateOptions(options) {
        var pk = options.publicKey;
        pk.challenge = b64urlToBuffer(pk.challenge);
        pk.user.id = b64urlToBuffer(pk.user.id);
        (pk.excludeCredentials || []).forEach(function (c) { c.id = b64urlToBuffer(c.id); });
        return options;
    }

    function decodeGetOptions(options) {
        var pk = options.publicKey;
        pk.challenge = b64urlToBuffer(pk.challenge);
        (pk.allowCredentials || []).forEach(function (c) { c.id = b64urlToBuffer(c.id); });
        return options;
    }

    var keyIcon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/></svg>';

    // --- Options popup: list, add, remove ---------------------------------------------

    function formatDate(value) {
        return value ? String(value).slice(0, 16) : '';
    }

    function render(list) {
        var box = document.querySelector('.js-popup-content .js-passkeys-list');
        if (!box) return;
        list = Array.isArray(list) ? list : [];

        if (!list.length) {
            box.innerHTML = '<div class="passkey-empty">No passkeys yet.</div>';
        } else {
            box.innerHTML = list.map(function (p) {
                var meta = 'Added ' + formatDate(p.created_at);
                if (p.last_used_at) meta += ' · Last used ' + formatDate(p.last_used_at);
                return '<div class="passkey-item" data-id="' + parseInt(p.id, 10) + '">'
                    + '<span class="passkey-item-icon">' + keyIcon + '</span>'
                    + '<div class="passkey-item-body">'
                    + '<div class="passkey-item-name">' + escapeHtml(p.name) + '</div>'
                    + '<div class="passkey-item-meta">' + escapeHtml(meta) + '</div>'
                    + '</div>'
                    + '<button type="button" class="btn btn-danger js-passkey-delete">Remove</button>'
                    + '</div>';
            }).join('');
        }

        var addBtn = document.querySelector('.js-popup-content .js-passkey-add');
        if (addBtn && !supported) {
            addBtn.disabled = true;
            addBtn.title = 'This browser does not support passkeys';
        }
    }

    function register() {
        var btn = document.querySelector('.js-popup-content .js-passkey-add');
        if (!btn || btn.disabled) return;
        if (!supported) { toast('This browser does not support passkeys'); return; }

        btn.disabled = true;
        postJson('passkey-register-options')
            .then(function (data) {
                if (!data || !data.success || !data.options) {
                    throw new Error((data && data.error) || 'Could not start passkey registration');
                }
                return navigator.credentials.create(decodeCreateOptions(data.options));
            })
            .then(function (credential) {
                if (!credential || !credential.response) throw new Error('cancelled');
                return postJson('passkey-register', {
                    clientDataJSON: bufferToB64url(credential.response.clientDataJSON),
                    attestationObject: bufferToB64url(credential.response.attestationObject)
                });
            })
            .then(function (data) {
                if (!data || !data.success) {
                    toast((data && data.error) || 'Passkey registration failed');
                    return;
                }
                render(data.passkeys);
                toast('Passkey added');
            })
            .catch(function (err) {
                toast(errorMessage(err, 'Passkey registration failed'));
            })
            .finally(function () {
                btn.disabled = false;
            });
    }

    function remove(id, btn) {
        if (!id) return;
        if (!window.confirm('Remove this passkey? You will no longer be able to sign in with it.')) return;

        btn.disabled = true;
        postJson('passkey-delete', { id: id })
            .then(function (data) {
                if (!data || !data.success) {
                    toast((data && data.error) || 'Could not remove the passkey');
                    btn.disabled = false;
                    return;
                }
                render(data.passkeys);
                toast('Passkey removed');
            })
            .catch(function () {
                toast('Could not remove the passkey');
                btn.disabled = false;
            });
    }

    document.addEventListener('click', function (event) {
        if (!event.target.closest('.js-popup-content')) return;

        if (event.target.closest('.js-passkey-add')) {
            event.preventDefault();
            register();
            return;
        }

        var del = event.target.closest('.js-passkey-delete');
        if (del) {
            event.preventDefault();
            var item = del.closest('.passkey-item');
            remove(item ? parseInt(item.dataset.id, 10) : 0, del);
        }
    });

    // --- Login page -------------------------------------------------------------------

    var loginBtn = document.getElementById('passkey-login');
    if (loginBtn) {
        var errorBox = document.getElementById('passkey-error');
        var rememberEl = document.querySelector('.login-box input[name="remember"]');
        var conditionalAbort = null;

        var showError = function (message) {
            if (!errorBox) return;
            errorBox.textContent = message || '';
            errorBox.hidden = !message;
        };

        var finish = function (assertion) {
            return postJson('passkey-login', {
                id: assertion.id,
                clientDataJSON: bufferToB64url(assertion.response.clientDataJSON),
                authenticatorData: bufferToB64url(assertion.response.authenticatorData),
                signature: bufferToB64url(assertion.response.signature),
                remember: !!(rememberEl && rememberEl.checked)
            }).then(function (data) {
                if (!data || !data.success) {
                    showError((data && data.error) || 'Passkey sign-in failed');
                    return false;
                }
                window.location.href = homeUrl;
                return true;
            });
        };

        var signIn = function () {
            if (!supported) { showError('This browser does not support passkeys'); return; }
            if (conditionalAbort) { conditionalAbort.abort(); conditionalAbort = null; }

            loginBtn.disabled = true;
            showError('');
            postJson('passkey-login-options')
                .then(function (data) {
                    if (!data || !data.success || !data.options) {
                        throw new Error((data && data.error) || 'Passkey sign-in is not available');
                    }
                    return navigator.credentials.get(decodeGetOptions(data.options));
                })
                .then(function (assertion) {
                    if (!assertion) throw new Error('cancelled');
                    return finish(assertion);
                })
                .catch(function (err) {
                    showError(errorMessage(err, 'Passkey sign-in failed'));
                })
                .finally(function () {
                    loginBtn.disabled = false;
                });
        };

        loginBtn.addEventListener('click', signIn);

        // Conditional UI: the passkey is offered in the username field's autofill (Safari, Chrome),
        // so Touch ID / Face ID can be used without pressing the button. Best effort only.
        if (supported && typeof window.PublicKeyCredential.isConditionalMediationAvailable === 'function') {
            window.PublicKeyCredential.isConditionalMediationAvailable()
                .then(function (available) {
                    if (!available) return;
                    return postJson('passkey-login-options').then(function (data) {
                        if (!data || !data.success || !data.options) return;
                        var options = decodeGetOptions(data.options);
                        options.publicKey.allowCredentials = []; // conditional requests list discoverable passkeys only
                        conditionalAbort = new AbortController();
                        options.mediation = 'conditional';
                        options.signal = conditionalAbort.signal;
                        return navigator.credentials.get(options).then(function (assertion) {
                            if (assertion) return finish(assertion);
                        });
                    });
                })
                .catch(function () { /* aborted by the button, unsupported, or cancelled — nothing to show */ });
        }
    }

    window.Passkeys = { render: render, supported: supported };
})();
