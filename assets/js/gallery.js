/**
 * BP Events Sync — Gallery (LightGallery init)
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var galleries = document.querySelectorAll('.bpes-gallery-grid');

        galleries.forEach(function (el) {
            lightGallery(el, {
                selector: '.bpes-gallery-item',
                plugins: [
                    lgZoom,
                    lgThumbnail,
                    lgFullscreen,
                    lgShare
                ],

                // General.
                speed: 400,
                backdropDuration: 300,
                counter: true,
                download: true,
                zIndex: 99999,
                mobileSettings: {
                    controls: true,
                    showCloseIcon: true,
                    download: true
                },

                // Thumbnails.
                thumbnail: false,
                animateThumb: false,

                // Zoom.
                zoom: true,
                scale: 1,
                actualSize: false,
                showZoomInOutIcons: true,
                actualSizeIcons: {
                    zoomIn: 'lg-zoom-in',
                    zoomOut: 'lg-zoom-out',
                },

                // Fullscreen.
                fullScreen: true,

                // Share.
                share: true,
                facebook: true,
                twitter: true,
                pinterest: true,

                // Swipe.
                swipeToClose: false,
                mousewheel: false
            });
        });
    });
})();