/* ADN Productos - Pagination sync with BeRocket AJAX filters */
(function ($) {
    'use strict';

    var refreshTimer = null;

    // ── Estado de filtros BeRocket (se actualiza cuando BeRocket dispara) ───────
    var adnBerocketState   = { tax_filters: {}, tax_term_ids: {}, min_price: '', max_price: '' };
    var adnIsUpdating      = false;
    var adnProductObserver = null;
    var adnCurrentPage        = 1;
    var adnLastFetchedFilters = null;

    // ── Interceptar history.pushState/replaceState para capturar precio de BeRocket ──
    // BeRocket llama a pushState con ?filters=price[18_113] antes de su AJAX.
    // Este interceptor captura el precio SÍNCRONAMENTE antes de cualquier timer.
    (function () {
        function adnHandleUrlChange(url) {
            if (!url) { return; }
            try {
                var urlObj = new URL(String(url), window.location.origin);
                var filters = urlObj.searchParams.get('filters') || '';
                var pm = filters.match(/price\[(\d+(?:\.\d+)?)_(\d+(?:\.\d+)?)\]/);
                if (pm) {
                    // Precio encontrado en URL → capturar
                    adnBerocketState.min_price = String(Math.round(parseFloat(pm[1])));
                    adnBerocketState.max_price = String(Math.round(parseFloat(pm[2])));
                }
                // NO limpiar si falta precio en URL: el slider puede estar activo sin URL actualizada.
                // El borrado lo hace exclusivamente el handler mouseup del slider.
            } catch (e) {}
        }
        var origPush    = history.pushState;
        var origReplace = history.replaceState;
        history.pushState = function (s, t, url) {
            adnHandleUrlChange(url);
            return origPush.apply(this, arguments);
        };
        history.replaceState = function (s, t, url) {
            adnHandleUrlChange(url);
            return origReplace.apply(this, arguments);
        };
    })();

    // ── Interceptar XHR nativo (BeRocket no siempre usa jQuery AJAX) ─────────
    (function () {
        function adnExtractPriceFromBody(body) {
            if (!body || typeof body !== 'string') { return; }
            if (body.indexOf('berocket') === -1 && body.indexOf('aapf') === -1) { return; }
            var minM = body.match(/min_price=([^&]+)/);
            var maxM = body.match(/max_price=([^&]+)/);
            if (minM) { adnBerocketState.min_price = decodeURIComponent(minM[1]); }
            if (maxM) { adnBerocketState.max_price = decodeURIComponent(maxM[1]); }
            if (!adnBerocketState.min_price) {
                var fm = body.match(/filters=([^&]+)/);
                if (fm) {
                    var fd = decodeURIComponent(fm[1]);
                    var pm = fd.match(/price\[(\d+(?:\.\d+)?)_(\d+(?:\.\d+)?)\]/);
                    if (pm) { adnBerocketState.min_price = pm[1]; adnBerocketState.max_price = pm[2]; }
                }
            }
        }
        // XHR
        var origSend = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.send = function (body) {
            adnExtractPriceFromBody(typeof body === 'string' ? body : '');
            return origSend.apply(this, arguments);
        };
        // fetch()
        if (window.fetch) {
            var origFetch = window.fetch;
            window.fetch = function (url, opts) {
                if (opts && opts.body) {
                    var b = opts.body;
                    if (b instanceof FormData) {
                        var minFD = b.get ? b.get('min_price') : null;
                        var maxFD = b.get ? b.get('max_price') : null;
                        if (minFD) { adnBerocketState.min_price = String(minFD); }
                        if (maxFD) { adnBerocketState.max_price = String(maxFD); }
                    } else {
                        adnExtractPriceFromBody(String(b));
                    }
                }
                return origFetch.apply(this, arguments);
            };
        }
    })();

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
            } else if ( key === 'adn_s' ) {
                result.adn_s = value;
            } else if ( key === 'filters' || key === 'filters[]' ) {
                // Formato BeRocket: ?filters=product_cat[71] o price[4_113]
                adnParseBeRocketFilters(value, result);
                // Extraer rango de precio del formato price[min_max]
                var priceMatch = value.match(/price\[(\d+(?:\.\d+)?)_(\d+(?:\.\d+)?)\]/);
                if (priceMatch) {
                    result.min_price = priceMatch[1];
                    result.max_price = priceMatch[2];
                }
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

        // 1. Inputs hidden/text de BeRocket
        var priceSelMin = [
            'input[name="min_price"]', 'input.berocket_filter_price_from',
            '.bapf_price_from', '.berocket_price_range_min', '.berocket_min_price_val'
        ].join(',');
        var priceSelMax = [
            'input[name="max_price"]', 'input.berocket_filter_price_to',
            '.bapf_price_to', '.berocket_price_range_max', '.berocket_max_price_val'
        ].join(',');
        var domMinVal = $(priceSelMin).first().val();
        var domMaxVal = $(priceSelMax).first().val();
        if (domMinVal !== undefined && domMinVal !== '') { result.min_price = domMinVal; }
        if (domMaxVal !== undefined && domMaxVal !== '') { result.max_price = domMaxVal; }

        // 2. noUiSlider API — solo si el slider se movió del rango completo
        if (!result.min_price || !result.max_price) {
            document.querySelectorAll('.noUi-target').forEach(function (el) {
                if (result.min_price && result.max_price) { return; }
                if (!el.noUiSlider) { return; }
                var vals = el.noUiSlider.get();
                if (!Array.isArray(vals) || vals.length < 2) { return; }
                var vMin = parseFloat(String(vals[0]).replace(/[^\d.]/g, ''));
                var vMax = parseFloat(String(vals[1]).replace(/[^\d.]/g, ''));
                if (isNaN(vMin) || isNaN(vMax)) { return; }
                var opts   = el.noUiSlider.options || {};
                var rng    = opts.range || {};
                var absMin = parseFloat(rng.min) || 0;
                var absMax = parseFloat(rng.max) || 999999;
                if (vMin > absMin || vMax < absMax) {
                    result.min_price = String(Math.round(vMin));
                    result.max_price = String(Math.round(vMax));
                }
            });
        }

        // 3. aria-valuenow en handles de noUiSlider (fallback robusto)
        if (!result.min_price || !result.max_price) {
            var $lower = $('.noUi-handle-lower').first();
            var $upper = $('.noUi-handle-upper').first();
            if ($lower.length && $upper.length) {
                var aMin = parseFloat($lower.attr('aria-valuenow') || 'NaN');
                var aMax = parseFloat($upper.attr('aria-valuenow') || 'NaN');
                if (!isNaN(aMin) && !isNaN(aMax)) {
                    var $target = $lower.closest('.noUi-target');
                    var hasSlider = $target.length && $target[0].noUiSlider;
                    var tMin = hasSlider ? parseFloat(($target[0].noUiSlider.options.range || {}).min) || 0      : 0;
                    var tMax = hasSlider ? parseFloat(($target[0].noUiSlider.options.range || {}).max) || 999999 : 999999;
                    if (aMin > tMin || aMax < tMax) {
                        result.min_price = String(Math.round(aMin));
                        result.max_price = String(Math.round(aMax));
                    }
                }
            }
        }

        // 4. Texto visible (e.g. "$4" ... "$113")
        if (!result.min_price || !result.max_price) {
            var fromTxt = $('.berocket_filter_price_from_text, .bapf_price_from_text').first().text().replace(/[^\d.]/g, '');
            var toTxt   = $('.berocket_filter_price_to_text,   .bapf_price_to_text').first().text().replace(/[^\d.]/g, '');
            if (fromTxt) { result.min_price = fromTxt; }
            if (toTxt)   { result.max_price = toTxt; }
        }

        return result;
    }

    // ── Barra de filtros: búsqueda + ordenamiento (AJAX sin recarga) ───────────
    // fromBerocket=true: disparado por BeRocket, no tocar la URL
    function adnFetchProducts(fromBerocket) {
        var $wrapper = $('.adn-productos-wrapper');
        if ( !$wrapper.length || typeof adnAjax === 'undefined' ) { return; }

        var searchVal  = $('#adn-search-input').val().trim();
        var orderbyVal = $('#adn-orderby-select').val();

        var rawFilters = new URLSearchParams(window.location.search).get('filters') || '';
        // Enviar el query string completo para que PHP lo parsee directamente
        var locationSearch = window.location.search || '';

        var adnPaged  = adnCurrentPage;

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

        // Checkboxes del widget de marcas (sidebar)
        var brandTax = 'product_brand';
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

        // ── Lectura directa del slider noUiSlider (aria-valuenow en los handles) ──
        // Este es el método más confiable: lee el estado ACTUAL del slider en el DOM.
        if (!data.min_price || !data.max_price) {
            var $sliderTarget = $('.noUi-target').first();
            var $lowerHandle  = $sliderTarget.find('.noUi-handle-lower').first();
            var $upperHandle  = $sliderTarget.find('.noUi-handle-upper').first();
            var sliderMin = parseFloat($lowerHandle.attr('aria-valuenow'));
            var sliderMax = parseFloat($upperHandle.attr('aria-valuenow'));

            if (!isNaN(sliderMin) && !isNaN(sliderMax)) {
                // Obtener el rango completo del slider para detectar si hay filtro activo
                var sliderFullMin = null, sliderFullMax = null;
                try {
                    if ($sliderTarget.length && $sliderTarget[0].noUiSlider) {
                        var rng = $sliderTarget[0].noUiSlider.options.range;
                        sliderFullMin = parseFloat(rng.min);
                        sliderFullMax = parseFloat(rng.max);
                    }
                } catch (e) {}

                var isFullRange = (sliderFullMin !== null) &&
                                  (sliderMin <= sliderFullMin) &&
                                  (sliderMax >= sliderFullMax);

                if (!isFullRange) {
                    if (!data.min_price) { data.min_price = String(Math.round(sliderMin)); }
                    if (!data.max_price) { data.max_price = String(Math.round(sliderMax)); }
                }
            }
        }

        // ── Inputs ocultos de BeRocket (último recurso) ─────────────────────────
        if (!data.min_price) {
            var $dMin = $('input[name="min_price"]').first();
            if ($dMin.length && $dMin.val() !== '') { data.min_price = $dMin.val(); }
        }
        if (!data.max_price) {
            var $dMax = $('input[name="max_price"]').first();
            if ($dMax.length && $dMax.val() !== '') { data.max_price = $dMax.val(); }
        }

        // ── Hash de filtros: resetear página solo si el usuario cambió algo ──────────
        // Si BeRocket se dispara espuriamente (reacción a nuestro propio DOM change),
        // el hash será idéntico y la página actual se conserva.
        var adnFilterHash = JSON.stringify({
            s:   searchVal || '',
            ob:  orderbyVal || '',
            tf:  data.tax_filters,
            ti:  data.tax_term_ids,
            min: data.min_price || '',
            max: data.max_price || ''
        });
        if ( adnLastFetchedFilters !== null && adnFilterHash !== adnLastFetchedFilters ) {
            adnCurrentPage = 1;
            data.adn_paged = 1;
        }
        adnLastFetchedFilters = adnFilterHash;

        // Enviar filtros también como JSON para evitar problemas de serialización
        data.filters_json = JSON.stringify({
            term_ids: data.tax_term_ids,
            slugs:    data.tax_filters
        });

        if (window.console && window.console.log) {
            console.log('[ADN] POST data:', { s: searchVal, rawFilters: rawFilters, filters_json: data.filters_json, min_price: data.min_price, max_price: data.max_price, berocketState_min: adnBerocketState.min_price });
        }

        // Actualizar URL solo cuando es un filtro propio (no de BeRocket)
        if (!fromBerocket) {
            var newUrl = new URL(window.location.href);
            if (searchVal)  { newUrl.searchParams.set('adn_s', searchVal); }
            else            { newUrl.searchParams.delete('adn_s'); }
            newUrl.searchParams.delete('s');
            if (orderbyVal) { newUrl.searchParams.set('orderby', orderbyVal); }
            else            { newUrl.searchParams.delete('orderby'); }
            newUrl.searchParams.delete('adn_paged');
            history.pushState({}, '', newUrl.toString());
        }

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
            // Reconectar observer con delay extendido.
            // 1200ms cubre el tiempo que BeRocket tarda en reaccionar a nuestro DOM change
            // y disparar berocket_ajax_products_finished / ajaxComplete.
            // Durante todo ese tiempo adnIsUpdating=true bloquea re-entradas al loop.
            setTimeout(function () {
                adnIsUpdating = false;
                var obsEl = document.querySelector('.adn-productos-wrapper');
                if ( adnProductObserver && obsEl ) {
                    adnProductObserver.observe( obsEl, { childList: true, subtree: true } );
                }
            }, 1200);
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

        // Valores iniciales del slider (para detectar si se movió del rango completo)
        var adnSliderInitMin = null, adnSliderInitMax = null;
        setTimeout(function () {
            var lh = document.querySelector('.noUi-handle-lower');
            var uh = document.querySelector('.noUi-handle-upper');
            if (lh) { adnSliderInitMin = parseFloat(lh.getAttribute('aria-valuenow')); }
            if (uh) { adnSliderInitMax = parseFloat(uh.getAttribute('aria-valuenow')); }
        }, 1500);

        // Capturar precio cuando el usuario suelta el handle del slider (mouseup/touchend)
        // Funciona sin necesidad de noUiSlider API — lee directamente aria-valuenow del DOM
        $(document).on('mouseup.adnprice touchend.adnprice', '.noUi-handle', function () {
            var $target = $(this).closest('.noUi-target');
            if ( !$target.length ) { return; }
            setTimeout(function () {
                var lh = $target.find('.noUi-handle-lower')[0];
                var uh = $target.find('.noUi-handle-upper')[0];
                if ( !lh || !uh ) { return; }
                var aMin = parseFloat(lh.getAttribute('aria-valuenow') || '');
                var aMax = parseFloat(uh.getAttribute('aria-valuenow') || '');
                if ( isNaN(aMin) || isNaN(aMax) ) { return; }
                // Solo aplicar si el slider se movió del rango inicial
                if ( aMin !== adnSliderInitMin || aMax !== adnSliderInitMax ) {
                    adnBerocketState.min_price = String(Math.round(aMin));
                    adnBerocketState.max_price = String(Math.round(aMax));
                } else {
                    adnBerocketState.min_price = '';
                    adnBerocketState.max_price = '';
                }
            }, 80);
        });

        // Capturar precio desde inputs ocultos que BeRocket actualiza
        $(document).on('change.adnprice input.adnprice', 'input[name="min_price"]', function () {
            if ( $(this).val() ) { adnBerocketState.min_price = $(this).val(); }
        });
        $(document).on('change.adnprice input.adnprice', 'input[name="max_price"]', function () {
            if ( $(this).val() ) { adnBerocketState.max_price = $(this).val(); }
        });

        function adnOnBeRocketDone() {
            if ( adnIsUpdating ) { return; }  // ignorar si nuestro propio fetch modificó el DOM
            clearTimeout(adnBerocketTimer);
            adnBerocketTimer = setTimeout(function () {
                var urlState = adnReadUrlFilters();
                var domState = adnReadDomFilters();
                // Preservar precio capturado del slider (prioridad sobre URL/DOM)
                adnBerocketState.tax_filters  = $.extend(true, {}, urlState.tax_filters,  domState.tax_filters);
                adnBerocketState.tax_term_ids = $.extend(true, {}, urlState.tax_term_ids, domState.tax_term_ids);
                adnBerocketState.min_price = adnBerocketState.min_price || urlState.min_price || domState.min_price;
                adnBerocketState.max_price = adnBerocketState.max_price || urlState.max_price || domState.max_price;

                adnFetchProducts(true);
            }, 300);
        }

        // Detector por eventos personalizados de BeRocket
        $(document).on(
            'berocket_ajax_products_finished berocket_aapf_update_products berocket_after_ajax_request berocket_aapf_ajax_done',
            adnOnBeRocketDone
        );

        // Interceptar el AJAX de BeRocket para capturar precio ANTES de que responda
        $(document).ajaxSend(function (event, xhr, settings) {
            if ( !settings || !settings.data ) { return; }
            var d = typeof settings.data === 'string' ? settings.data : '';
            if ( d.indexOf('berocket') === -1 && d.indexOf('aapf') === -1 ) { return; }

            var priceFrom = '', priceTo = '';

            // Formato: min_price=X&max_price=Y
            var minM = d.match(/min_price=([^&]+)/);
            var maxM = d.match(/max_price=([^&]+)/);
            if (minM) { priceFrom = decodeURIComponent(minM[1]); }
            if (maxM) { priceTo   = decodeURIComponent(maxM[1]); }

            // Formato: filters=price%5BX_Y%5D o filters=price[X_Y]
            if (!priceFrom && !priceTo) {
                var fm = d.match(/filters=([^&]+)/);
                if (fm) {
                    var fd = decodeURIComponent(fm[1]);
                    var pm = fd.match(/price\[(\d+(?:\.\d+)?)_(\d+(?:\.\d+)?)\]/);
                    if (pm) { priceFrom = pm[1]; priceTo = pm[2]; }
                }
            }

            // Formato BeRocket: berocket_args%5Bprice%5D...
            if (!priceFrom && !priceTo) {
                var baFrom = d.match(/berocket_args(?:%5B|\[)price(?:%5D|\])(?:%5B|\[)from(?:%5D|\])=([^&]+)/);
                var baTo   = d.match(/berocket_args(?:%5B|\[)price(?:%5D|\])(?:%5B|\[)to(?:%5D|\])=([^&]+)/);
                if (baFrom) { priceFrom = decodeURIComponent(baFrom[1]); }
                if (baTo)   { priceTo   = decodeURIComponent(baTo[1]); }
            }

            if (priceFrom) { adnBerocketState.min_price = priceFrom; }
            if (priceTo)   { adnBerocketState.max_price = priceTo; }
        });

        // Detector por $.ajaxComplete (para BeRocket con jQuery AJAX)
        $(document).ajaxComplete(function (event, xhr, settings) {
            if ( adnIsUpdating ) { return; }  // guard: ignorar reacciones a nuestros propios cambios
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
            adnCurrentPage = 1;
            clearTimeout(searchTimer);
            searchTimer = setTimeout(adnFetchProducts, 500);
        });

        // Enter: dispara búsqueda inmediata
        $(document).on('keydown', '#adn-search-input', function (e) {
            if (e.key === 'Enter' || e.which === 13) {
                e.preventDefault();
                adnCurrentPage = 1;
                clearTimeout(searchTimer);
                adnFetchProducts();
            }
        });

        // Ordenamiento inmediato al cambiar el select
        $(document).on('change', '#adn-orderby-select', function () {
            adnCurrentPage = 1;
            adnFetchProducts();
        });

        // Filtro de marca (checkboxes del widget)
        $(document).on('change', '.adn-marca-check', function () {
            adnCurrentPage = 1;
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
            var page = pageMatch ? parseInt(pageMatch[1], 10) : 1;
            adnCurrentPage = page;
            var newUrl = new URL( window.location.href );
            newUrl.searchParams.set( 'adn_paged', page );
            history.pushState( {}, '', newUrl.toString() );
            adnFetchProducts( true );
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
            // Ignorar cambios provocados por nuestro propio AJAX para no
            // sobreescribir la paginación filtrada con la paginación sin filtros
            if ( adnIsUpdating ) { return; }
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

    // ── Custom Price Slider ────────────────────────────────────────────────────
    // Reemplaza el slider de precio de BeRocket con uno propio para que la lectura
    // de min_price / max_price sea 100% confiable, sin depender de eventos de BeRocket.
    function adnInitCustomPriceSlider() {
        if ( typeof adnAjax === 'undefined' ) { return; }

        var priceMin = parseInt( adnAjax.priceMin, 10 );
        var priceMax = parseInt( adnAjax.priceMax, 10 );
        if ( isNaN(priceMin) || isNaN(priceMax) || priceMin >= priceMax ) { return; }

        // Encontrar el body del widget de precio de BeRocket
        // Probamos varias clases que BeRocket puede usar según configuración
        var $bapfBody = null;
        var candidates = [
            '.bapf_type_slider .bapf_body',
            '.bapf_type_price .bapf_body',
            '.bapf_sfilter .bapf_body'
        ];
        for ( var i = 0; i < candidates.length; i++ ) {
            var $c = $( candidates[i] ).filter( function () {
                return $( this ).find( '.noUi-target' ).length > 0;
            } ).first();
            if ( $c.length ) { $bapfBody = $c; break; }
        }
        // Fallback: encontrar por el noUi-target directamente
        if ( !$bapfBody ) {
            var $noUiEl = $( '.noUi-target' ).first();
            if ( $noUiEl.length ) {
                $bapfBody = $noUiEl.closest( '.bapf_body' );
                if ( !$bapfBody.length ) { $bapfBody = $noUiEl.parent(); }
            }
        }
        if ( !$bapfBody || !$bapfBody.length ) { return; }

        // Destruir noUiSlider existente de BeRocket para evitar conflictos
        var $existing = $bapfBody.find( '.noUi-target' ).first();
        if ( $existing.length && $existing[0].noUiSlider ) {
            try { $existing[0].noUiSlider.destroy(); } catch (e) {}
        }

        // Inyectar nuestro HTML
        $bapfBody.empty().append(
            '<div id="adn-price-slider"></div>' +
            '<div class="adn-price-display">' +
                '<span class="adn-price-lbl-min"></span>' +
                '<span class="adn-price-sep">&nbsp;&mdash;&nbsp;</span>' +
                '<span class="adn-price-lbl-max"></span>' +
            '</div>'
        );

        var sliderEl = document.getElementById( 'adn-price-slider' );
        if ( !sliderEl || typeof noUiSlider === 'undefined' ) { return; }

        noUiSlider.create( sliderEl, {
            start:   [ priceMin, priceMax ],
            connect: true,
            range:   { min: priceMin, max: priceMax },
            step:    1,
            format: {
                to:   function (v) { return Math.round(v); },
                from: function (v) { return Number(v); }
            }
        } );

        function fmtPrice(v) { return 'Bs.\u00a0' + v; }

        // Actualizar etiquetas en tiempo real mientras se arrastra
        sliderEl.noUiSlider.on( 'update', function (vals) {
            $( '.adn-price-lbl-min' ).text( fmtPrice( vals[0] ) );
            $( '.adn-price-lbl-max' ).text( fmtPrice( vals[1] ) );
        } );

        // Al soltar, actualizar estado y hacer fetch
        var priceDebounce = null;
        sliderEl.noUiSlider.on( 'change', function (vals) {
            var minV = parseInt( vals[0], 10 );
            var maxV = parseInt( vals[1], 10 );
            var isFullRange = ( minV <= priceMin && maxV >= priceMax );
            adnBerocketState.min_price = isFullRange ? '' : String( minV );
            adnBerocketState.max_price = isFullRange ? '' : String( maxV );
            clearTimeout( priceDebounce );
            priceDebounce = setTimeout( function () {
                adnFetchProducts( false );
            }, 400 );
        } );
    }

    // Inicializar con delay para que BeRocket termine de renderizar su slider
    $( window ).on( 'load', function () {
        setTimeout( adnInitCustomPriceSlider, 500 );
    } );

    // ── Imagen placeholder para productos sin imagen ──────────────────────────
    var adnPlaceholder = ( typeof wc_add_to_cart_params !== 'undefined' && wc_add_to_cart_params.wc_ajax_url )
        ? wc_add_to_cart_params.wc_ajax_url.replace( '/?wc-ajax=%%endpoint%%', '' ) + '/wp-content/plugins/woocommerce/assets/images/placeholder.png'
        : '/wp-content/plugins/woocommerce/assets/images/placeholder.png';

    function adnFixMissingImages( $ctx ) {
        $ctx = $ctx || $( document );
        $ctx.find( 'img' ).each( function () {
            var $img = $( this );
            var src  = ( $img.attr( 'src' ) || '' ).trim();
            if ( ! src || src === '' ) {
                $img.attr( 'src', adnPlaceholder );
            }
        } );
        // Escuchar error de carga para imágenes rotas
        $ctx.find( 'img' ).on( 'error', function () {
            if ( this.src !== adnPlaceholder ) {
                this.src = adnPlaceholder;
            }
        } );
    }

    // Ejecutar al cargar la página (productos en el grid de marca)
    $( function () { adnFixMissingImages( $( document ) ); } );

    // ── WP Menu Cart: sincronizar items en el slideout ────────────────────────
    function adnSyncWpMenuCart() {
        var $source = $( '#adn-wc-mini-cart-source .widget_shopping_cart_content' );
        var $target = $( '.wpmenucart-slideout__content' );
        if ( ! $source.length || ! $target.length ) { return; }

        var $list = $source.find( 'ul.woocommerce-mini-cart' );
        if ( $list.length ) {
            $target.html( $list.prop( 'outerHTML' ) );
        } else {
            var $empty = $source.find( '.woocommerce-mini-cart__empty-message' );
            $target.html( $empty.length ? $empty.prop( 'outerHTML' ) : '' );
        }

        // Corregir imágenes sin src en el slideout después de sincronizar
        adnFixMissingImages( $target );
    }

    // Observar cuando el slideout recibe la clase "is-wc-open"
    var _adnSlideoutEl = document.querySelector( '.wpmenucart-slideout' );
    if ( _adnSlideoutEl ) {
        new MutationObserver( function ( muts ) {
            muts.forEach( function ( m ) {
                if ( m.target.classList && m.target.classList.contains( 'is-wc-open' ) ) {
                    adnSyncWpMenuCart();
                }
            } );
        } ).observe( _adnSlideoutEl, { attributes: true, attributeFilter: [ 'class' ] } );
    }

    // Observar cambios de visibilidad en el panel nativo de WP Menu Cart
    // (panel con clase wpmenucart-popup, wpmenucart-hover, etc.)
    var _adnCartPanelObserver = new MutationObserver( function () {
        var $panels = $( '.wpmenucart-popup, .wpmenucart-hover, #wpmenucart-contents, .wpmenucart-slideout' );
        $panels.each( function () {
            adnFixMissingImages( $( this ) );
        } );
    } );
    var _adnCartEl = document.querySelector( '#wpmenucart, .wpmenucart' );
    if ( _adnCartEl ) {
        _adnCartPanelObserver.observe( _adnCartEl, { childList: true, subtree: true, attributes: true } );
    }

    // Mantener sincronizado tras agregar/quitar productos del carrito
    $( document.body ).on(
        'wc_fragments_refreshed wc_fragments_loaded added_to_cart removed_from_cart',
        function () {
            adnSyncWpMenuCart();
            adnFixMissingImages( $( '#wpmenucart, .wpmenucart, .wpmenucart-slideout' ) );
        }
    );

})(jQuery);
