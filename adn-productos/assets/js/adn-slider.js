/* ADN Brands Slider — vanilla JS, sin dependencias */
(function () {
    'use strict';

    function ADNBrandsSlider(wrapper) {
        var track         = wrapper.querySelector('.adn-brands-track');
        var viewport      = wrapper.querySelector('.adn-brands-viewport');
        var prevBtn       = wrapper.querySelector('.adn-brands-prev');
        var nextBtn       = wrapper.querySelector('.adn-brands-next');
        var outer         = wrapper.parentElement;
        var dotsContainer = outer ? outer.querySelector('.adn-brands-dots') : null;

        var desktopCols = parseInt(wrapper.dataset.columns, 10) || 4;
        var totalSlides = parseInt(track.dataset.total, 10)     || 0;
        var current     = 0;
        var cols        = desktopCols;

        /* ---- utilidades ---- */

        function getColumns() {
            if (window.innerWidth <= 540) return 2;
            if (window.innerWidth <= 900) return Math.min(desktopCols, 2);
            return desktopCols;
        }

        function maxCurrent() {
            return Math.max(0, totalSlides - cols);
        }

        /* ---- slide width vía CSS custom property ---- */

        function applySlideWidth() {
            wrapper.style.setProperty('--adn-slide-cols', cols);
        }

        /* ---- translate ---- */

        function goTo(index) {
            current = Math.max(0, Math.min(index, maxCurrent()));

            // Porcentaje relativo al ancho del track completo
            var pct = totalSlides > 0 ? (current / totalSlides) * 100 : 0;
            track.style.transform = 'translateX(-' + pct + '%)';

            prevBtn.disabled = current <= 0;
            nextBtn.disabled = current >= maxCurrent();
            updateDots();
        }

        /* ---- dots ---- */

        function buildDots() {
            if (!dotsContainer) return;
            dotsContainer.innerHTML = '';
            var pages = Math.ceil(totalSlides / cols);
            if (pages <= 1) {
                dotsContainer.style.display = 'none';
                return;
            }
            dotsContainer.style.display = '';
            for (var i = 0; i < pages; i++) {
                var dot = document.createElement('button');
                dot.className = 'adn-brands-dot';
                dot.setAttribute('aria-label', 'Página ' + (i + 1));
                dot.dataset.page = i;
                dotsContainer.appendChild(dot);
            }
            dotsContainer.querySelectorAll('.adn-brands-dot').forEach(function (dot) {
                dot.addEventListener('click', function () {
                    goTo(parseInt(this.dataset.page, 10) * cols);
                });
            });
        }

        function updateDots() {
            if (!dotsContainer) return;
            var activePage = cols > 0 ? Math.round(current / cols) : 0;
            dotsContainer.querySelectorAll('.adn-brands-dot').forEach(function (dot, i) {
                dot.classList.toggle('active', i === activePage);
            });
        }

        /* ---- resize ---- */

        function update() {
            var newCols = getColumns();
            if (newCols !== cols) {
                cols    = newCols;
                current = Math.min(current, maxCurrent());
                applySlideWidth();
                buildDots();
            }
            goTo(current);
        }

        /* ---- eventos ---- */

        prevBtn.addEventListener('click', function () { goTo(current - cols); });
        nextBtn.addEventListener('click', function () { goTo(current + cols); });

        var resizeTimer;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(update, 150);
        });

        // Swipe táctil
        var touchStartX = 0;
        viewport.addEventListener('touchstart', function (e) {
            touchStartX = e.touches[0].clientX;
        }, { passive: true });
        viewport.addEventListener('touchend', function (e) {
            var diff = touchStartX - e.changedTouches[0].clientX;
            if (Math.abs(diff) > 40) {
                goTo(diff > 0 ? current + cols : current - cols);
            }
        }, { passive: true });

        /* ---- init ---- */
        applySlideWidth();
        update();
        buildDots();
    }

    function initAll() {
        document.querySelectorAll('.adn-brands-slider-wrapper').forEach(function (wrapper) {
            if (!wrapper.dataset.adnInit) {
                wrapper.dataset.adnInit = '1';
                new ADNBrandsSlider(wrapper);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

    // Compatibilidad con carga dinámica (widgets, page builders)
    window.ADNBrandsSliderInit = initAll;
})();
