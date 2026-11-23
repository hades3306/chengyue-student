(function () {
    'use strict';

    var body = document.body;
    var toggle = document.getElementById('navToggle');
    var scrim = document.getElementById('navScrim');

    if (toggle) {
        toggle.addEventListener('click', function () {
            body.classList.toggle('nav-open');
        });
    }

    if (scrim) {
        scrim.addEventListener('click', function () {
            body.classList.remove('nav-open');
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            body.classList.remove('nav-open');
        }
    });

    document.querySelectorAll('.flash-close').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var flash = btn.closest('.flash');
            if (flash) {
                flash.remove();
            }
        });
    });

    document.querySelectorAll('.flash').forEach(function (flash) {
        window.setTimeout(function () {
            flash.classList.add('is-hiding');
            window.setTimeout(function () {
                flash.remove();
            }, 300);
        }, 5600);
    });

    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var message = form.getAttribute('data-confirm') || '确认提交本次操作？';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('select.js-auto-submit').forEach(function (select) {
        select.addEventListener('change', function () {
            var form = select.closest('form');
            if (form) {
                form.submit();
            }
        });
    });

    document.querySelectorAll('tr[data-href]').forEach(function (row) {
        row.addEventListener('click', function (event) {
            if (event.target.closest('a, button, input, select, form')) {
                return;
            }
            window.location.href = row.getAttribute('data-href');
        });
    });
})();
