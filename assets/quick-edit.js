/* Lightweight inline editor feedback. Server alone validates and saves prices. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('wvm-quick-form');
        if (!form) return;
        var fields = Array.prototype.slice.call(form.querySelectorAll('.wvm-quick-price'));
        var counter = form.querySelector('[data-wvm-changed-count]');
        function cents(value) {
            var normalized = String(value).trim().replace(',', '.');
            if (!/^\d{1,9}([.]\d{1,4})?$/.test(normalized)) return null;
            return Math.round(Number(normalized) * 100);
        }
        function refresh() {
            var changed = 0;
            fields.forEach(function (field) {
                var original = cents(field.dataset.original);
                var current = cents(field.value);
                var differs = current !== original;
                field.classList.toggle('wvm-quick-changed', differs);
                if (differs) changed++;
            });
            if (counter) counter.textContent = String(changed);
        }
        form.addEventListener('input', refresh);
        refresh();
    });
}());
