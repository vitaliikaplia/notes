/**
 * Popup engine — universal modal / confirmation dialogs.
 *
 * Vanilla port of the reference engine. Markup lives in views/overall/popup.twig.
 *
 * Declarative API (data attributes):
 *   .js-popup-confirm         link/button -> confirm dialog, then navigate to its href
 *   .js-popup-confirm-submit  button -> confirm dialog, then submit data-popup-form
 *   .js-popup-open            open a content popup from data-popup-source (#selector or HTML)
 *   [data-popup-close]        close the popup
 *   data-popup-title / -message / -size / -confirm-label / -cancel-label / -confirm-style
 *
 * Programmatic API:
 *   Popup.open({ title, content, size, showClose, closeOnEsc, buttons, onClose })
 *   Popup.confirm({ title, message, size, confirmLabel, cancelLabel, confirmStyle }) -> Promise<bool>
 *   Popup.close(value)
 */
(function () {
    'use strict';

    // Map engine button styles to this project's button classes.
    var BTN_CLASS = {
        primary: 'btn btn-primary',
        danger: 'btn btn-danger',
        secondary: 'btn',
        ghost: 'btn'
    };

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = (value === null || value === undefined) ? '' : String(value);
        return div.innerHTML;
    }

    var Popup = {
        element: null,
        dialog: null,
        header: null,
        title: null,
        content: null,
        actions: null,
        closeButton: null,
        previousFocus: null,
        options: {},

        init: function () {
            this.element = document.querySelector('.js-popup');
            if (!this.element) return;

            this.dialog = this.element.querySelector('.js-popup-dialog');
            this.header = this.element.querySelector('.js-popup-header');
            this.title = this.element.querySelector('.js-popup-title');
            this.content = this.element.querySelector('.js-popup-content');
            this.actions = this.element.querySelector('.js-popup-actions');
            this.closeButton = this.element.querySelector('.js-popup-close');

            var self = this;

            document.addEventListener('click', function (event) {
                // Close
                if (event.target.closest('[data-popup-close]')) {
                    event.preventDefault();
                    self.close(false);
                    return;
                }

                // Confirm -> navigate to href
                var confirmTrigger = event.target.closest('.js-popup-confirm');
                if (confirmTrigger) {
                    event.preventDefault();
                    self.confirm(self.readConfirmOptions(confirmTrigger)).then(function (confirmed) {
                        var href = confirmTrigger.getAttribute('href');
                        if (confirmed && href) {
                            window.location.href = href;
                        }
                    });
                    return;
                }

                // Confirm -> submit a form
                var submitTrigger = event.target.closest('.js-popup-confirm-submit');
                if (submitTrigger) {
                    event.preventDefault();
                    var form = document.querySelector(submitTrigger.getAttribute('data-popup-form') || '');
                    if (!form) return;
                    self.confirm(self.readConfirmOptions(submitTrigger)).then(function (confirmed) {
                        if (confirmed) form.submit();
                    });
                    return;
                }

                // Open a content popup
                var openTrigger = event.target.closest('.js-popup-open');
                if (openTrigger) {
                    event.preventDefault();
                    self.open({
                        title: openTrigger.getAttribute('data-popup-title') || '',
                        content: openTrigger.getAttribute('data-popup-source') || '',
                        size: openTrigger.getAttribute('data-popup-size') || 'medium',
                        showClose: openTrigger.getAttribute('data-popup-close') !== 'false'
                    });
                    return;
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && self.element.classList.contains('show') && self.options.closeOnEsc !== false) {
                    self.close(false);
                }
            });
        },

        readConfirmOptions: function (trigger) {
            return {
                title: trigger.getAttribute('data-popup-title') || '',
                message: trigger.getAttribute('data-popup-message') || '',
                size: trigger.getAttribute('data-popup-size') || 'narrow',
                confirmLabel: trigger.getAttribute('data-popup-confirm-label') || 'OK',
                cancelLabel: trigger.getAttribute('data-popup-cancel-label') || 'Cancel',
                confirmStyle: trigger.getAttribute('data-popup-confirm-style') || 'primary'
            };
        },

        getContentHtml: function (content) {
            if (content instanceof Element) {
                return content.innerHTML;
            }

            if (typeof content === 'string') {
                if (content.trim().charAt(0) === '#') {
                    var source = document.querySelector(content);
                    return source ? source.innerHTML : content;
                }
                return content;
            }

            return '';
        },

        renderButtons: function (buttons) {
            this.actions.innerHTML = '';

            if (!buttons || !buttons.length) {
                this.actions.classList.remove('show');
                return;
            }

            var self = this;

            buttons.forEach(function (button) {
                var item = document.createElement('button');
                item.type = 'button';
                item.className = BTN_CLASS[button.style] || BTN_CLASS.secondary;
                item.textContent = button.label || '';

                item.addEventListener('click', function () {
                    if (typeof button.onClick === 'function') {
                        button.onClick();
                        return;
                    }
                    self.close(button.value || false);
                });

                self.actions.appendChild(item);
            });

            this.actions.classList.add('show');
        },

        open: function (options) {
            if (!this.element) return;

            var defaults = {
                title: '',
                content: '',
                size: 'medium',
                variant: '',
                showClose: true,
                closeOnEsc: true,
                buttons: []
            };

            this.options = Object.assign({}, defaults, options || {});
            this.previousFocus = document.activeElement;

            // Variant modifier on the overlay (e.g. 'spotlight' = top-aligned search palette)
            this.element.classList.toggle('popup--spotlight', this.options.variant === 'spotlight');

            this.dialog.classList.remove('popupDialog--narrow', 'popupDialog--medium', 'popupDialog--wide');
            this.dialog.classList.add('popupDialog--' + this.options.size);
            this.content.innerHTML = this.getContentHtml(this.options.content);
            this.renderButtons(this.options.buttons);

            this.title.textContent = this.options.title;
            this.header.classList.toggle('show', Boolean(this.options.title) || this.options.showClose);
            this.title.style.display = this.options.title ? '' : 'none';
            this.closeButton.style.display = this.options.showClose ? '' : 'none';

            if (this.options.title) {
                this.dialog.setAttribute('aria-labelledby', 'popup-title');
            } else {
                this.dialog.removeAttribute('aria-labelledby');
            }

            this.element.setAttribute('aria-hidden', 'false');
            this.element.classList.add('show');
            document.body.classList.add('popup-open');
            window.dispatchEvent(new CustomEvent('popup-open'));

            var self = this;
            setTimeout(function () {
                var candidates = self.dialog.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
                var focusable = null;
                for (var i = 0; i < candidates.length; i++) {
                    if (candidates[i].offsetParent !== null) { focusable = candidates[i]; break; }
                }
                (focusable || self.dialog).focus();
            }, 20);
        },

        close: function (value) {
            if (!this.element) return;

            var onClose = this.options.onClose;

            this.element.setAttribute('aria-hidden', 'true');
            this.element.classList.remove('show');
            document.body.classList.remove('popup-open');

            var self = this;
            setTimeout(function () {
                self.content.innerHTML = '';
                self.actions.innerHTML = '';
                self.actions.classList.remove('show');
            }, 220);

            if (this.previousFocus && this.previousFocus.focus) {
                this.previousFocus.focus();
            }

            if (typeof onClose === 'function') {
                onClose(value);
            }
        },

        confirm: function (options) {
            var self = this;

            return new Promise(function (resolve) {
                var settings = Object.assign({
                    title: '',
                    message: '',
                    size: 'narrow',
                    confirmLabel: 'OK',
                    cancelLabel: 'Cancel',
                    confirmStyle: 'primary'
                }, options || {});

                self.open({
                    title: settings.title,
                    content: '<p class="popupMessage">' + escapeHtml(settings.message) + '</p>',
                    size: settings.size,
                    showClose: true,
                    buttons: [
                        {
                            label: settings.cancelLabel,
                            style: 'ghost',
                            onClick: function () { self.close(false); }
                        },
                        {
                            label: settings.confirmLabel,
                            style: settings.confirmStyle,
                            onClick: function () { self.close(true); }
                        }
                    ],
                    onClose: resolve
                });
            });
        }
    };

    window.Popup = Popup;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { Popup.init(); });
    } else {
        Popup.init();
    }
})();
