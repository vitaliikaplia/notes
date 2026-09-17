/**
 * Minimal lightbox for gallery thumbnails — works in the editor and in the public view.
 * Any click on an <img> inside .cdx-gallery__item or .gallery-item opens the full image.
 */
(function () {
    'use strict';

    var overlay = null;

    function build() {
        overlay = document.createElement('div');
        overlay.className = 'lightbox';
        overlay.innerHTML = '<button type="button" class="lightbox__close" aria-label="Close">&times;</button><img class="lightbox__img" alt="">';
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay || e.target.classList.contains('lightbox__close')) close();
        });
        document.body.appendChild(overlay);
    }

    function open(src, alt) {
        if (!overlay) build();
        var img = overlay.querySelector('.lightbox__img');
        img.src = src;
        img.alt = alt || '';
        overlay.classList.add('is-open');
        document.body.classList.add('lightbox-open');
    }

    function close() {
        if (!overlay) return;
        overlay.classList.remove('is-open');
        document.body.classList.remove('lightbox-open');
    }

    document.addEventListener('click', function (e) {
        var img = e.target.closest('.cdx-gallery__item img, .gallery-item img');
        if (!img) return;
        e.preventDefault();
        e.stopPropagation();
        open(img.dataset.full || img.src, img.alt);
    }, true);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') close();
    });

    window.openLightbox = open;
})();
