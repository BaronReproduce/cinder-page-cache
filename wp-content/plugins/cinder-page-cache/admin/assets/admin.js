(function () {
    'use strict';

    var admin = window.cpcAdmin || { ajax: '', nonce: '' };

    function post(action) {
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('nonce', admin.nonce);

        return fetch(admin.ajax, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body
        }).then(function (r) { return r.json(); });
    }

    var tabs = document.querySelectorAll('.cinder-page-cache-tabs .nav-tab');
    var panels = document.querySelectorAll('.cinder-page-cache-panel');

    function activate(hash) {
        var id = (hash || '#dashboard').replace('#', '');
        var found = false;

        panels.forEach(function (panel) {
            var on = panel.id === id;
            panel.classList.toggle('is-active', on);
            found = found || on;
        });

        tabs.forEach(function (tab) {
            tab.classList.toggle('nav-tab-active', tab.getAttribute('href') === '#' + id);
        });

        if (!found && panels.length) {
            panels[0].classList.add('is-active');
        }
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function (e) {
            e.preventDefault();
            var hash = tab.getAttribute('href');
            history.replaceState(null, '', hash);
            activate(hash);
        });
    });

    activate(window.location.hash);

    function bind(id, action, render) {
        var btn = document.getElementById(id);
        if (!btn) {
            return;
        }

        btn.addEventListener('click', function () {
            btn.disabled = true;
            post(action).then(function (res) {
                btn.disabled = false;
                render(res);
            }).catch(function () {
                btn.disabled = false;
            });
        });
    }

    bind('cinder-page-cache-clear', 'cpc_clear_cache', function (res) {
        var out = document.getElementById('cinder-page-cache-clear-result');
        if (out) {
            out.textContent = res && res.success ? 'Purged ' + (res.data.removed || 0) + ' file(s).' : 'Failed.';
        }
    });

    bind('cinder-page-cache-db', 'cpc_db_run', function (res) {
        var out = document.getElementById('cinder-page-cache-db-result');
        if (out) {
            out.textContent = res && res.success ? 'Cleaned and optimized.' : 'Failed.';
        }
    });

    bind('cinder-page-cache-webp', 'cpc_bulk_webp', function (res) {
        var out = document.getElementById('cinder-page-cache-webp-result');
        if (!out) {
            return;
        }

        if (!res || !res.success) {
            out.textContent = 'Server lacks GD WebP support.';
            return;
        }

        out.textContent = 'Processed ' + res.data.processed + ' image(s).';
    });

    bind('cinder-page-cache-refresh', 'cpc_refresh_env', function (res) {
        if (!res || !res.success) {
            return;
        }

        var map = {
            'cinder-page-cache-config': res.data.config_readable ? 'Available' : 'None',
            'cinder-page-cache-env': res.data.env_detected ? 'Available' : 'None',
            'cinder-page-cache-entries': String(res.data.entries)
        };

        Object.keys(map).forEach(function (id) {
            var el = document.getElementById(id);
            if (el) {
                el.textContent = map[id];
            }
        });
    });

    bind('cinder-page-cache-cloud', 'cpc_cloud_check', function (res) {
        var note = document.getElementById('cinder-page-cache-cloud-result');
        var score = document.getElementById('cinder-page-cache-score');
        var list = document.getElementById('cinder-page-cache-notes');

        if (!res || !res.success) {
            if (note) { note.textContent = 'Failed.'; }
            return;
        }

        if (score) {
            score.textContent = res.data.score === null ? 'No score' : res.data.score + '/100';
        }

        if (list) {
            list.innerHTML = '';
            (res.data.notes || []).forEach(function (text) {
                var li = document.createElement('li');
                li.textContent = text;
                list.appendChild(li);
            });
        }

        var consent = document.querySelector('input[name="cpc_consent"]');
        if (consent) {
            consent.checked = true;
        }

        if (note) {
            note.textContent = 'Sent. Consent saved.';
        }
    });
})();
