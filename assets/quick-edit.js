/* Inline tariff editing. Zero dependencies; WooCommerce/PHP own the prices. */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('wvm-quick-form');
        if (!form) return;

        var fields = Array.prototype.slice.call(form.querySelectorAll('.wvm-quick-price'));
        var counter = form.querySelector('[data-wvm-changed-count]');
        var save = document.getElementById('wvm-quick-save');
        var undo = document.getElementById('wvm-quick-undo');
        var dirty = false;
        var submitting = false;
        var precision = Number(form.getAttribute('data-wvm-decimals') || '2');
        if (!Number.isInteger(precision) || precision < 0 || precision > 4) precision = 2;

        function normalized(value) {
            var text = String(value).trim().replace(',', '.');
            if (!/^\d{1,9}([.]\d{1,4})?$/.test(text)) return null;
            var price = Number(text);
            return Number.isFinite(price) && price > 0
                ? Math.round(price * Math.pow(10, precision))
                : null;
        }

        function refresh() {
            var changed = 0;
            var invalid = 0;
            fields.forEach(function (field) {
                var original = normalized(field.getAttribute('data-original'));
                var current = normalized(field.value);
                var broken = current === null;
                var differs = current !== original;
                if (broken) invalid++;
                if (differs) changed++;
                field.classList.toggle('wvm-quick-changed', differs && !broken);
                field.classList.toggle('wvm-quick-invalid', broken);
                field.setAttribute('aria-invalid', broken ? 'true' : 'false');
                field.setCustomValidity(broken ? 'Introduce un importe mayor que cero, con hasta cuatro decimales.' : '');
            });
            dirty = changed > 0;
            if (counter) counter.textContent = String(changed);
            if (save) save.disabled = !dirty || invalid > 0;
            if (undo) undo.disabled = !dirty;
        }

        form.addEventListener('input', refresh);

        if (undo) {
            undo.addEventListener('click', function () {
                fields.forEach(function (field) {
                    field.value = field.getAttribute('data-original') || '';
                    field.setCustomValidity('');
                });
                refresh();
            });
        }

        form.addEventListener('submit', function (event) {
            refresh();
            if (save && save.disabled) {
                event.preventDefault();
                return;
            }
            submitting = true;
        });

        window.addEventListener('beforeunload', function (event) {
            if (dirty && !submitting) {
                event.preventDefault();
                event.returnValue = '';
            }
        });

        refresh();
    });
}());
