(function () {
    'use strict';

    const STORAGE_KEY = 'dc_wishlist';

    // Read server-side session wishlist (source of truth)
    function getServerWishlist() {
        const el = document.getElementById('dc-wishlist-data');
        if (!el) return [];
        try {
            return JSON.parse(el.dataset.uids || '[]').map(Number);
        } catch (e) {
            return [];
        }
    }

    // Sync from server exactly once on page load; afterwards use localStorage only
    var serverSynced = false;

    function getLocalWishlist() {
        var el = document.getElementById('dc-wishlist-data');
        if (el && !serverSynced) {
            serverSynced = true;
            var server = getServerWishlist();
            saveLocalWishlist(server);
            return server;
        }
        try {
            return JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]').map(Number);
        } catch (e) {
            return [];
        }
    }

    function saveLocalWishlist(uids) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(uids));
        } catch (e) {}
    }

    function updateWishlistCountBadge(count) {
        const badge = document.getElementById('dc-wishlist-count');
        if (badge) {
            badge.textContent = count;
            badge.dataset.count = count;

            // Animate
            badge.classList.remove('dc-badge-pop');
            void badge.offsetWidth;
            badge.classList.add('dc-badge-pop');
        }
    }

    // Wishlist toggle: optimistic UI + AJAX (no page reload)
    function initWishlistToggles() {
        document.querySelectorAll('.js-wishlist-toggle').forEach(function (btn) {
            const form = btn.closest('form');
            if (!form) return;

            btn.addEventListener('click', function (e) {
                e.preventDefault();

                const articleUid = parseInt(btn.dataset.articleUid, 10);
                if (!articleUid || btn.dataset.pending) return;

                const card           = btn.closest('.dc-card');
                const wasWishlisted  = card && card.classList.contains('dc-card--wishlisted');

                // Optimistic UI
                var uids = getLocalWishlist();
                if (wasWishlisted) {
                    uids = uids.filter(function (u) { return u !== articleUid; });
                    card && card.classList.remove('dc-card--wishlisted');
                } else {
                    if (!uids.includes(articleUid)) uids.push(articleUid);
                    card && card.classList.add('dc-card--wishlisted');
                }
                saveLocalWishlist(uids);
                updateWishlistCountBadge(uids.length);

                btn.dataset.pending = '1';

                fetch(form.action, {
                    method:  'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body:    new FormData(form),
                })
                .then(function (r) {
                    // Try to parse JSON; if response is HTML (non-propagated),
                    // return null and keep the optimistic state intact
                    if (!r.ok) return Promise.reject('http-error');
                    return r.json().catch(function () { return null; });
                })
                .then(function (data) {
                    if (!data) return; // non-JSON: optimistic state is correct, nothing to do
                    card && card.classList.toggle('dc-card--wishlisted', data.inWishlist);
                    var synced = getLocalWishlist();
                    if (data.inWishlist && !synced.includes(articleUid)) {
                        synced.push(articleUid);
                    } else if (!data.inWishlist) {
                        synced = synced.filter(function (u) { return u !== articleUid; });
                    }
                    saveLocalWishlist(synced);
                    updateWishlistCountBadge(data.count);
                })
                .catch(function (err) {
                    // Only roll back on genuine network/HTTP errors, not on non-JSON responses
                    if (err !== 'http-error') return;
                    var rb = getLocalWishlist();
                    if (wasWishlisted) {
                        if (!rb.includes(articleUid)) rb.push(articleUid);
                        card && card.classList.add('dc-card--wishlisted');
                    } else {
                        rb = rb.filter(function (u) { return u !== articleUid; });
                        card && card.classList.remove('dc-card--wishlisted');
                    }
                    saveLocalWishlist(rb);
                    updateWishlistCountBadge(rb.length);
                })
                .finally(function () {
                    delete btn.dataset.pending;
                });
            });
        });
    }

    // Sync card states with localStorage on load
    function syncCardStates() {
        const uids = getLocalWishlist();
        document.querySelectorAll('.dc-card[data-article-uid]').forEach(function (card) {
            const uid = parseInt(card.dataset.articleUid, 10);
            if (uids.includes(uid)) {
                card.classList.add('dc-card--wishlisted');
            }
        });
        updateWishlistCountBadge(uids.length);
    }

    function initBodyRegionSystemFilter() {
        const bodyRegionSelect = document.getElementById('dc-filter-bodyregion');
        const systemSelect = document.getElementById('dc-filter-system');
        if (!bodyRegionSelect || !systemSelect) return;

        const form = document.querySelector('.dc-filter__form');
        var systemsByArea = {};
        try {
            systemsByArea = JSON.parse((form && form.dataset.systemsByArea) || '{}');
        } catch (e) {}

        const allOptions = Array.prototype.slice.call(systemSelect.querySelectorAll('option')).map(function (option) {
            return {
                value: option.value,
                label: option.textContent.trim(),
            };
        });

        function renderSystemOptions() {
            const selectedBodyRegionValue = bodyRegionSelect.value;
            const selectedSystemValue = systemSelect.value;
            const allowedSystems = selectedBodyRegionValue ? (systemsByArea[selectedBodyRegionValue] || []) : null;

            systemSelect.innerHTML = '';

            allOptions.forEach(function (option) {
                if (option.value === '0') {
                    const defaultOption = document.createElement('option');
                    defaultOption.value = option.value;
                    defaultOption.textContent = option.label;
                    defaultOption.selected = selectedSystemValue === option.value;
                    systemSelect.appendChild(defaultOption);
                    return;
                }

                if (allowedSystems && !allowedSystems.includes(option.label)) {
                    return;
                }

                const nextOption = document.createElement('option');
                nextOption.value = option.value;
                nextOption.textContent = option.label;
                nextOption.selected = selectedSystemValue === option.value;
                systemSelect.appendChild(nextOption);
            });

            if (!Array.prototype.some.call(systemSelect.options, function (option) { return option.selected; })) {
                systemSelect.value = '0';
            }
        }

        renderSystemOptions();
        bodyRegionSelect.addEventListener('change', renderSystemOptions);
    }

    // Intercept filter form submit → build clean URL
    function initFilterForm() {
        const form = document.querySelector('.dc-filter__form');
        if (!form || !form.dataset.baseUrl) return;

        const baseUrl = form.dataset.baseUrl.replace(/\/$/, '');

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            const search = (form.querySelector('[name="tx_digitalcatalog_catalog[search]"]')?.value || '').trim();
            const bodyRegion = (form.querySelector('[name="tx_digitalcatalog_catalog[bodyRegion]"]')?.value || '').trim();
            const system = parseInt(form.querySelector('[name="tx_digitalcatalog_catalog[system]"]')?.value || '0', 10);
            const type = (form.querySelector('[name="tx_digitalcatalog_catalog[type]"]')?.value || '').trim();
            const sterile = (form.querySelector('[name="tx_digitalcatalog_catalog[sterile]"]')?.value || '') === '1';

            let path = baseUrl;

            if (bodyRegion) {
                path += '/area/' + encodeURIComponent(bodyRegion);
                if (system > 0) {
                    path += '/system/' + system;
                }
            } else if (system > 0) {
                path += '/system/' + system;
            }

            if (type) {
                path += '/type/' + encodeURIComponent(type);
            }

            if (sterile) {
                path += '/sterile';
            }

            const params = new URLSearchParams();
            if (search) params.set('tx_digitalcatalog_catalog[search]', search);

            window.location.href = path + '/' + (params.toString() ? '?' + params.toString() : '');
        });
    }

    function initAutocomplete() {
        const searchInput = document.getElementById('dc-filter-search');
        const form = document.querySelector('.dc-filter__form');
        const panel = document.getElementById('dc-autocomplete');
        if (!searchInput || !form || !panel || !searchInput.dataset.autocompleteEndpoint) return;

        const endpoint = searchInput.dataset.autocompleteEndpoint;
        const systemFilter = searchInput.dataset.systemFilter || '0';
        const loadingLabel = searchInput.dataset.loadingLabel || 'Loading...';
        const emptyLabel = searchInput.dataset.emptyLabel || 'No results found.';
        const articlesLabel = searchInput.dataset.articlesLabel || 'Art. No.';
        let debounceTimer = null;
        let abortController = null;
        let activeIndex = -1;

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function getItems() {
            return Array.prototype.slice.call(panel.querySelectorAll('.dc-autocomplete__item'));
        }

        function closePanel() {
            panel.hidden = true;
            panel.innerHTML = '';
            panel.classList.remove('dc-autocomplete--open');
            activeIndex = -1;
            searchInput.setAttribute('aria-expanded', 'false');
        }

        function openPanel() {
            panel.hidden = false;
            panel.classList.add('dc-autocomplete--open');
            searchInput.setAttribute('aria-expanded', 'true');
        }

        function setActiveItem(index) {
            const items = getItems();
            activeIndex = items.length ? Math.max(0, Math.min(index, items.length - 1)) : -1;
            items.forEach(function (item, itemIndex) {
                item.classList.toggle('is-active', itemIndex === activeIndex);
            });
        }

        function renderLoading() {
            panel.innerHTML = '<div class="dc-autocomplete__status">' + escapeHtml(loadingLabel) + '</div>';
            openPanel();
        }

        function renderEmpty() {
            panel.innerHTML = '<div class="dc-autocomplete__status">' + escapeHtml(emptyLabel) + '</div>';
            openPanel();
        }

        function renderItems(items) {
            if (!items.length) {
                renderEmpty();
                return;
            }

            panel.innerHTML = items.map(function (item, index) {
                return (
                    '<a class="dc-autocomplete__item' + (index === 0 ? ' is-active' : '') + '" ' +
                    'href="' + escapeHtml(item.url) + '" ' +
                    'data-index="' + index + '">' +
                        '<span class="dc-autocomplete__title">' + escapeHtml(item.title) + '</span>' +
                        '<span class="dc-autocomplete__meta">' + escapeHtml(articlesLabel) + ' ' + escapeHtml(item.articleNumber) + '</span>' +
                    '</a>'
                );
            }).join('');

            activeIndex = 0;
            openPanel();
        }

        function fetchSuggestions() {
            const term = searchInput.value.trim();
            if (term.length < 2) {
                closePanel();
                return;
            }

            if (abortController) {
                abortController.abort();
            }

            abortController = new AbortController();
            renderLoading();

            const payload = new FormData();
            payload.set('tx_digitalcatalog_catalog[action]', 'suggest');
            payload.set('tx_digitalcatalog_catalog[controller]', 'Catalog');
            payload.set('tx_digitalcatalog_catalog[term]', term);
            payload.set('tx_digitalcatalog_catalog[system]', systemFilter);

            fetch(endpoint, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: payload,
                signal: abortController.signal,
            })
                .then(function (response) {
                    if (!response.ok) {
                        return Promise.reject();
                    }
                    return response.json();
                })
                .then(function (data) {
                    renderItems(Array.isArray(data.items) ? data.items : []);
                })
                .catch(function (error) {
                    if (error && error.name === 'AbortError') {
                        return;
                    }
                    closePanel();
                });
        }

        searchInput.setAttribute('aria-autocomplete', 'list');
        searchInput.setAttribute('aria-expanded', 'false');
        searchInput.setAttribute('aria-controls', 'dc-autocomplete');

        searchInput.addEventListener('input', function () {
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(fetchSuggestions, 220);
        });

        searchInput.addEventListener('keydown', function (event) {
            const items = getItems();
            if (!items.length || panel.hidden) {
                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                setActiveItem(activeIndex + 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                setActiveItem(activeIndex - 1);
            } else if (event.key === 'Enter' && activeIndex >= 0) {
                event.preventDefault();
                items[activeIndex].click();
            } else if (event.key === 'Escape') {
                closePanel();
            }
        });

        searchInput.addEventListener('focus', function () {
            if (searchInput.value.trim().length >= 2 && panel.innerHTML.trim() !== '') {
                openPanel();
            }
        });

        document.addEventListener('click', function (event) {
            if (!panel.contains(event.target) && event.target !== searchInput) {
                closePanel();
            }
        });

        panel.addEventListener('mousemove', function (event) {
            const item = event.target.closest('.dc-autocomplete__item');
            if (!item) return;
            setActiveItem(parseInt(item.dataset.index || '-1', 10));
        });

        form.addEventListener('submit', function () {
            closePanel();
        });
    }

    // Quantity stepper: AJAX update, no page reload
    function initQtySteppers() {
        document.querySelectorAll('.js-qty-form').forEach(function (form) {
            var hidden  = form.querySelector('[name$="[quantity]"]');
            var display = form.querySelector('.dc-qty-display');
            if (!hidden || !display) return;

            function setQty(val) {
                var v = Math.max(1, Math.min(99, val));
                hidden.value        = v;
                display.textContent = v;
                display.classList.add('dc-qty-display--loading');
                form.querySelectorAll('.dc-qty-btn').forEach(function (b) { b.disabled = true; });

                fetch(form.action, {
                    method:  'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body:    new FormData(form),
                })
                .then(function (r) {
                    if (!r.ok) return null;
                    return r.json().catch(function () { return null; });
                })
                .then(function (data) {
                    if (!data) return; // non-JSON: keep optimistic value
                    display.textContent = data.qty;
                    hidden.value        = data.qty;
                    updateWishlistCountBadge(data.count);
                })
                .catch(function () { /* keep optimistic value on network error */ })
                .finally(function () {
                    display.classList.remove('dc-qty-display--loading');
                    form.querySelectorAll('.dc-qty-btn').forEach(function (b) { b.disabled = false; });
                });
            }

            var minusBtn = form.querySelector('.dc-qty-btn--minus');
            var plusBtn  = form.querySelector('.dc-qty-btn--plus');
            if (minusBtn) minusBtn.addEventListener('click', function () {
                setQty(parseInt(hidden.value || '1', 10) - 1);
            });
            if (plusBtn) plusBtn.addEventListener('click', function () {
                setQty(parseInt(hidden.value || '1', 10) + 1);
            });
        });
    }

    function initSterileChip() {
        const chip = document.getElementById('dc-filter-sterile');
        const input = document.getElementById('dc-sterile-input');
        if (!chip || !input) return;

        chip.addEventListener('click', function () {
            const isActive = chip.classList.contains('dc-filter__chip--active');
            chip.classList.toggle('dc-filter__chip--active', !isActive);
            input.value = isActive ? '' : '1';
        });
    }

    // Grid search highlight on filter
    function initSearchHighlight() {
        const searchInput = document.querySelector('.dc-filter__search');
        if (!searchInput) return;

        searchInput.addEventListener('input', function () {
            const val = searchInput.value.toLowerCase().trim();
            document.querySelectorAll('.dc-card').forEach(function (card) {
                if (!val) {
                    card.style.opacity = '1';
                    return;
                }
                const title = (card.querySelector('.dc-card__title')?.textContent || '').toLowerCase();
                const number = (card.querySelector('.dc-card__number')?.textContent || '').toLowerCase();
                card.style.opacity = (title.includes(val) || number.includes(val)) ? '1' : '0.35';
            });
        });
    }

    // Badge pop animation style
    const style = document.createElement('style');
    style.textContent = '@keyframes dc-badge-pop { 0%,100%{transform:scale(1)} 50%{transform:scale(1.35)} } .dc-badge-pop { animation: dc-badge-pop 250ms ease; }';
    document.head.appendChild(style);

    // Language tabs on detail page
    function initDocTabs() {
        var tabsNav = document.querySelector('.dc-docs-tabs__nav');
        if (!tabsNav) return;

        tabsNav.addEventListener('click', function (e) {
            var tab = e.target.closest('.dc-docs-tabs__tab');
            if (!tab) return;

            var lang = tab.dataset.lang;
            var tabs = document.querySelector('#dc-docs-tabs');

            tabs.querySelectorAll('.dc-docs-tabs__tab').forEach(function (t) {
                t.classList.toggle('dc-docs-tabs__tab--active', t.dataset.lang === lang);
                t.setAttribute('aria-selected', t.dataset.lang === lang ? 'true' : 'false');
            });
            tabs.querySelectorAll('.dc-docs-tabs__panel').forEach(function (p) {
                p.classList.toggle('dc-docs-tabs__panel--active', p.dataset.lang === lang);
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        syncCardStates();
        initWishlistToggles();
        initBodyRegionSystemFilter();
        initFilterForm();
        initSterileChip();
        initAutocomplete();
        initQtySteppers();
        initSearchHighlight();
        initDocTabs();
    });

})();
