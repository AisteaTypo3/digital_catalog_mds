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

    // Intercept filter form submit → build clean URL
    function initFilterForm() {
        const form = document.querySelector('.dc-filter__form');
        if (!form || !form.dataset.baseUrl) return;

        const baseUrl = form.dataset.baseUrl.replace(/\/$/, '');

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            const search = (form.querySelector('[name="tx_digitalcatalog_catalog[search]"]')?.value || '').trim();
            const system = parseInt(form.querySelector('[name="tx_digitalcatalog_catalog[system]"]')?.value || '0', 10);

            let path = baseUrl;

            if (system > 0) {
                path += '/system/' + system;
            }

            const params = new URLSearchParams();
            if (search) {
                params.set('tx_digitalcatalog_catalog[search]', search);
            }

            window.location.href = path + (params.toString() ? '?' + params.toString() : '/');
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
        initFilterForm();
        initQtySteppers();
        initSearchHighlight();
        initDocTabs();
    });

})();
