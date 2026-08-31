/* ADN Productos - Pagination sync with BeRocket AJAX filters */
(function ($) {
    'use strict';

    var refreshTimer = null;

    // ── Estado de filtros BeRocket (se actualiza cuando BeRocket dispara) ───────
    var adnBerocketState   = { tax_filters: {}, tax_term_ids: {}, min_price: '', max_price: '' };
    var adnIsUpdating      = false;
    var adnProductObserver = null;

    function adnParseBeRocketFilters(filterStr, result) {
        // BeRocket usa: product_cat[71 134] o product_cat[76-92] o product_cat[71]product_cat[134]
        // Los IDs dentro de los corchetes pueden separarse por espacio, coma o guión
        var re = /([a-zA-Z_][a-zA-Z0-9_]*)\[([\d][\d\s,\-]*)\]/g;
        var m;
        while ((m = re.exec(filterStr)) !== null) {
            var tax  = m[1];
            var tids = m[2].split(/[-\s,]+/).filter(Boolean);
            if (!result.tax_term_ids[tax]) { result.tax_term_ids[tax] = []; }
            tids.forEach(function (tid) {
                if (/^\d+$/.test(tid) && result.tax_term_ids[tax].indexOf(tid) === -1) {
                    result.tax_term_ids[tax].push(tid);
                }
            });
        }
    }

    function adnReadUrlFilters() {
        var result = { tax_filters: {}, tax_term_ids: {}, min_price: '', max_price: '' };
        var params = new URLSearchParams(window.location.search);
        params.forEach(function (value, key) {
            if ( key.indexOf('filter_') === 0 ) {
                result.tax_filters[key.replace('filter_', '')] = value.split(',');
            } else if ( key === 'product_cat' ) {
                result.tax_filters['product_cat'] = value.split(',');
            } else if ( key === 'min_price' || key === 'max_price' ) {
                result[key] = value;
            } else if ( key === 'filters' || key === 'filters[]' ) {
                // Formato BeRocket: ?filters=product_cat[71]
                adnParseBeRocketFilters(value, result);
            }
        });
        return result;
    }

    function adnMergeInto(dest, tax, val) {
        if (!dest[tax]) { dest[tax] = []; }
        if (dest[tax].indexOf(String(val)) === -1) { dest[tax].push(String(val)); }
    }

    function adnReadDomFilters() {
        var result = { tax_filters: {}, tax_term_ids: {}, min_price: '', max_price: '' };
        $('[class*="berocket_aapf"], [class*="aapf_widget"], .widget_berocket_aapf_widget')
            .find('input[type="checkbox"]:checked, input[type="radio"]:checked')
            .each(function () {
                var $el  = $(this);
                var tax  = ($el.data('taxonomy') || $el.attr('name') || '')
                               .replace(/\[\]$/, '').replace(/^filter_/, '');
                if (!tax) { return; }
                var slug = $el.data('slug') || $el.data('term');
                var tid  = $el.data('term_id') || $el.data('term-id');
                var raw  = String($el.val() || '');
                if (slug) { adnMergeInto(result.tax_filters, tax, slug); }
                if (tid)  { adnMergeInto(result.tax_term_ids, tax, tid); }
                if (raw && !slug && !tid) {
                    if (/^\d+$/.test(raw)) { adnMergeInto(result.tax_term_ids, tax, raw); }
                    else                   { adnMergeInto(result.tax_filters,  tax, raw); }
                }
            });

        // Leer precio mínimo/máximo del slider BeRocket desde el DOM
        var priceSelMin = [
            'input[name="min_price"]',
            '.bapf_price_from',
            '.berocket_price_range_min',
            '.berocket_min_price_val',
            '[data-price-type="min"]'
        ].join(',');
        var priceSelMax = [
            'input[name="max_price"]',
            '.bapf_price_to',
            '.berocket_price_range_max',
            '.berocket_max_price_val',
            '[data-price-type="max"]'
        ].join(',');
        var domMinVal = $(priceSelMin).first().val();
        var domMaxVal = $(priceSelMax).first().val();
        if (domMinVal !== undefined && domMinVal !== '') { result.min_price = domMinVal; }
        if (domMaxVal !== undefined && domMaxVal !== '') { result.max_price = domMaxVal; }

        return result;
    }

    // ── Barra de filtros: búsqueda + ordenamiento (AJAX sin recarga) ───────────
    function adnFetchProducts() {
        var $wrapper = $('.adn-productos-wrapper');
        if ( !$wrapper.length || typeof adnAjax === 'undefined' ) { return; }

        var searchVal  = $('#adn-search-input').val().trim();
        var orderbyVal = $('#adn-orderby-select').val();

        var rawFilters = new URLSearchParams(window.location.search).get('filters') || '';
        // Enviar el query string completo para que PHP lo parsee directamente
        var locationSearch = window.location.search || '';

        var adnPaged  = parseInt( new URLSearchParams( window.location.search ).get('adn_paged') || '1', 10 );
        var brandVal  = $('#adn-brand-select').val() || '';
        var brandTax  = $('.adn-productos-wrapper').data('brand-tax') || 'product_brand';

        var data = {
            action:          'adn_filter_products',
            nonce:           adnAjax.nonce,
            s:               searchVal,
            orderby_key:     orderbyVal,
            columns:         $wrapper.data('columns') || 3,
            limit:           $wrapper.data('per-page') || 12,
            tax_filters:     {},
            tax_term_ids:    {},
            raw_filters:     rawFilters,
            location_search: locationSearch,
            location_href:   window.location.href,
            adn_paged:       adnPaged
        };

        // Checkboxes del widget de marcas
        $('.adn-marca-check:checked').each(function () {
            var cbTax  = $(this).data('taxonomy') || brandTax;
            var cbSlug = $(this).data('slug') || $(this).val();
            if (cbTax && cbSlug) {
                if (!data.tax_filters[cbTax]) { data.tax_filters[cbTax] = []; }
                if (data.tax_filters[cbTax].indexOf(cbSlug) === -1) {
                    data.tax_filters[cbTax].push(cbSlug);
                }
            }
        });

        // Filtro de marca del select propio
        if (brandVal && brandTax) {
            if (!data.tax_filters[brandTax]) { data.tax_filters[brandTax] = []; }
            if (data.tax_filters[brandTax].indexOf(brandVal) === -1) {
                data.tax_filters[brandTax].push(brandVal);
            }
        }

        // 1. Filtros desde URL (BeRocket con modo URL)
        var urlFilters = adnReadUrlFilters();
        $.extend(true, data.tax_filters,  urlFilters.tax_filters);
        $.extend(true, data.tax_term_ids, urlFilters.tax_term_ids || {});
        if (urlFilters.min_price) { data.min_price = urlFilters.min_price; }
        if (urlFilters.max_price) { data.max_price = urlFilters.max_price; }

        // 2. Filtros desde DOM (BeRocket con modo AJAX puro, sin URL)
        var domFilters = adnReadDomFilters();
        $.each(domFilters.tax_filters, function (tax, terms) {
            if (!data.tax_filters[tax]) { data.tax_filters[tax] = []; }
            $.each(terms, function (_, t) {
                if (data.tax_filters[tax].indexOf(t) === -1) { data.tax_filters[tax].push(t); }
            });
        });
        $.each(domFilters.tax_term_ids || {}, function (tax, tids) {
            if (!data.tax_term_ids[tax]) { data.tax_term_ids[tax] = []; }
            $.each(tids, function (_, tid) {
                if (data.tax_term_ids[tax].indexOf(tid) === -1) { data.tax_term_ids[tax].push(tid); }
            });
        });

        // 3. Estado capturado del último evento BeRocket
        $.each(adnBerocketState.tax_filters, function (tax, terms) {
            if (!data.tax_filters[tax]) { data.tax_filters[tax] = []; }
            $.each(terms, function (_, t) {
                if (data.tax_filters[tax].indexOf(t) === -1) { data.tax_filters[tax].push(t); }
            });
        });
        $.each(adnBerocketState.tax_term_ids || {}, function (tax, tids) {
            if (!data.tax_term_ids[tax]) { data.tax_term_ids[tax] = []; }
            $.each(tids, function (_, tid) {
                if (data.tax_term_ids[tax].indexOf(tid) === -1) { data.tax_term_ids[tax].push(tid); }
            });
        });
        if (adnBerocketState.min_price && !data.min_price) { data.min_price = adnBerocketState.min_price; }
        if (adnBerocketState.max_price && !data.max_price) { data.max_price = adnBerocketState.max_price; }

        // Lectura directa del DOM como último recurso (BeRocket AJAX puro sin URL)
        if (!data.min_price) {
            var $dMin = $('input[name="min_price"], .bapf_price_from, .berocket_price_range_min').first();
            if ($dMin.length && $dMin.val() !== '') { data.min_price = $dMin.val(); }
        }
        if (!data.max_price) {
            var $dMax = $('input[name="max_price"], .bapf_price_to, .berocket_price_range_max').first();
            if ($dMax.length && $dMax.val() !== '') { data.max_price = $dMax.val(); }
        }

        // Enviar filtros también como JSON para evitar problemas de serialización
        data.filters_json = JSON.stringify({
            term_ids: data.tax_term_ids,
            slugs:    data.tax_filters
        });

        if (window.console && window.console.log) {
            console.log('[ADN] POST data:', { s: searchVal, rawFilters: rawFilters, filters_json: data.filters_json, min_price: data.min_price, max_price: data.max_price });
        }

        // Actualizar URL silenciosamente
        var newUrl = new URL(window.location.href);
        if (searchVal)  { newUrl.searchParams.set('s', searchVal); }
        else            { newUrl.searchParams.delete('s'); }
        if (orderbyVal) { newUrl.searchParams.set('orderby', orderbyVal); }
        else            { newUrl.searchParams.delete('orderby'); }
        newUrl.searchParams.delete('adn_paged');
        history.pushState({}, '', newUrl.toString());

        adnIsUpdating = true;
        // Desconectar observer para que nuestros cambios DOM no disparen el loop
        if ( adnProductObserver ) { adnProductObserver.disconnect(); }
        $wrapper.addClass('adn-cargando');

        $.post(adnAjax.url, data, function (response) {
            $wrapper.removeClass('adn-cargando');
            if ( response && response.success ) {
                if (window.console && response.data.debug) {
                    console.log('[ADN PHP]', response.data.debug);
                }
                $wrapper.find('.adn-productos-grid, .adn-sin-productos, .adn-pagination, ul.products').remove();
                $wrapper.append(response.data.html);
                // Actualizar contador
                var count = response.data.count || 0;
                var label = count === 1
                    ? count + ' producto encontrado'
                    : count + ' productos encontrados';
                $wrapper.find('.adn-resultado-count').text(label);
                $(document.body).trigger('wc_fragment_refresh');
            }
            adnIsUpdating = false;
            // Reconectar observer después de que todos los cambios DOM terminaron
            var obsEl = document.querySelector('.adn-productos-wrapper');
            if ( adnProductObserver && obsEl ) {
                adnProductObserver.observe( obsEl, { childList: true, subtree: true } );
            }
        }).fail(function () {
            adnIsUpdating = false;
            $wrapper.removeClass('adn-cargando');
            var obsEl = document.querySelector('.adn-productos-wrapper');
            if ( adnProductObserver && obsEl ) {
                adnProductObserver.observe( obsEl, { childList: true, subtree: true } );
            }
        });
    }

    $(document).ready(function () {
        var searchTimer = null;

        var adnBerocketTimer = null;

        function adnOnBeRocketDone() {
            clearTimeout(adnBerocketTimer);
            adnBerocketTimer = setTimeout(function () {
                var urlState = adnReadUrlFilters();
                var domState = adnReadDomFilters();
                adnBerocketState = {
                    tax_filters:  $.extend(true, {}, urlState.tax_filters,  domState.tax_filters),
                    tax_term_ids: $.extend(true, {}, urlState.tax_term_ids, domState.tax_term_ids),
                    min_price:    urlState.min_price || domState.min_price,
                    max_price:    urlState.max_price || domState.max_price
                };
                adnFetchProducts();
            }, 120);
        }

        // Detector por eventos personalizados de BeRocket
        $(document).on(
            'berocket_ajax_products_finished berocket_aapf_update_products berocket_after_ajax_request berocket_aapf_ajax_done',
            adnOnBeRocketDone
        );

        // Detector por $.ajaxComplete (para BeRocket con jQuery AJAX)
        $(document).ajaxComplete(function (event, xhr, settings) {
            if ( !settings || !settings.data ) { return; }
            var d = typeof settings.data === 'string' ? settings.data : '';
            if ( d.indexOf('berocket') !== -1 || d.indexOf('aapf') !== -1 ) {
                adnOnBeRocketDone();
            }
        });

        // MutationObserver: detecta cuando BeRocket modifica el grid (fetch nativo, sin jQuery AJAX)
        // subtree:true para capturar cambios dentro de ul.products (li items)
        var $obs = $('.adn-productos-wrapper');
        if ( $obs.length && window.MutationObserver ) {
            adnProductObserver = new MutationObserver(function (mutations) {
                // Guard: ignorar si somos nosotros los que estamos modificando el DOM
                if ( adnIsUpdating ) { return; }
                for ( var i = 0; i < mutations.length; i++ ) {
                    if ( mutations[i].type === 'childList' &&
                         ( mutations[i].addedNodes.length || mutations[i].removedNodes.length ) ) {
                        adnOnBeRocketDone();
                        return;
                    }
                }
            });
            adnProductObserver.observe( $obs[0], { childList: true, subtree: true } );
        }

        // Búsqueda por nombre con debounce de 500ms al escribir
        $(document).on('input', '#adn-search-input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(adnFetchProducts, 500);
        });

        // Enter: dispara búsqueda inmediata
        $(document).on('keydown', '#adn-search-input', function (e) {
            if (e.key === 'Enter' || e.which === 13) {
                e.preventDefault();
                clearTimeout(searchTimer);
                adnFetchProducts();
            }
        });

        // Ordenamiento inmediato al cambiar el select
        $(document).on('change', '#adn-orderby-select', function () {
            adnFetchProducts();
        });

        // Filtro de marca (select de barra)
        $(document).on('change', '#adn-brand-select', function () {
            var newUrl = new URL(window.location.href);
            newUrl.searchParams.delete('adn_paged');
            history.pushState({}, '', newUrl.toString());
            adnFetchProducts();
        });

        // Filtro de marca (checkboxes del widget)
        $(document).on('change', '.adn-marca-check', function () {
            var newUrl = new URL(window.location.href);
            newUrl.searchParams.delete('adn_paged');
            history.pushState({}, '', newUrl.toString());
            adnFetchProducts();
        });

        // Paginación AJAX: interceptar clicks en los links de página
        $(document).on('click', '.adn-pagination a', function (e) {
            e.preventDefault();
            var href = $(this).attr('href') || '';
            var pageMatch = href.match(/[?&]adn_paged=(\d+)/);
            var page = pageMatch ? pageMatch[1] : '1';
            var newUrl = new URL( window.location.href );
            newUrl.searchParams.set( 'adn_paged', page );
            history.pushState( {}, '', newUrl.toString() );
            adnFetchProducts();
        });
    });
    // ──────────────────────────────────────────────────────────────────────────

    function applyPagination($wrapper, $newPag) {
        var $curPag = $wrapper.find('.adn-pagination');
        if ($newPag && $newPag.length) {
            if ($curPag.length) $curPag.replaceWith($newPag);
            else $wrapper.append($newPag);
        } else {
            $curPag.remove();
        }
    }

    // Pide la pagina actual y actualiza solo la paginacion
    function scheduleRefresh(url) {
        clearTimeout(refreshTimer);
        refreshTimer = setTimeout(function () {
            var $wrapper = $('.adn-productos-wrapper');
            if (!$wrapper.length) return;
            var getUrl = url || window.location.href;
            $.get(getUrl, function (html) {
                applyPagination($wrapper, $('<div>').html(html).find('.adn-pagination'));
            });
        }, 350);
    }

    // ── 1. MutationObserver: detecta cambios en el grid sin importar como hace AJAX BeRocket
    $(document).ready(function () {
        var grid = document.querySelector('.adn-productos-grid');
        if (!grid) return;

        var observer = new MutationObserver(function (mutations) {
            var changed = mutations.some(function (m) {
                return m.addedNodes.length > 0 || m.removedNodes.length > 0;
            });
            if (changed) { scheduleRefresh(); }
        });

        observer.observe(grid, { childList: true });
    });

    // ── 2. $.ajaxComplete: captura respuestas HTML completas de BeRocket (GET-style)
    $(document).ajaxComplete(function (event, xhr, settings) {
        if (!xhr.responseText) return;
        var text = xhr.responseText;

        // Solo si la respuesta contiene nuestro grid Y no es JSON puro
        if (text.indexOf('adn-productos-grid') === -1) return;
        try { JSON.parse(text); return; } catch (e) {}  // ignorar respuestas JSON

        var $wrapper = $('.adn-productos-wrapper');
        if (!$wrapper.length) return;

        // Usar la URL del request (ya tiene los params del filtro)
        var requestUrl = (settings && settings.url) ? settings.url : null;
        applyPagination($wrapper, $('<div>').html(text).find('.adn-pagination'));
        void requestUrl; // referencia para depuración futura
    });

    // ── 3. Eventos BeRocket (fallback por si los eventos existen en esta version)
    $(document).on(
        'berocket_ajax_products_finished berocket_aapf_update_products ' +
        'berocket_after_ajax_request berocket_aapf_ajax_done',
        function () { scheduleRefresh(); }
    );

})(jQuery);
