(function () {
    'use strict';
    function init(el) {
        var data;
        try { data = JSON.parse(el.getAttribute('data-wvm-config') || '{}'); }
        catch (e) { return; }
        var select = el.querySelector('select[name="wvm_size"]');
        var output = el.querySelector('[data-wvm-selected-price]');
        if (!select || !output || !data.prices) return;
        var formatter;
        try {
            formatter = new Intl.NumberFormat(document.documentElement.lang || 'es-ES', {
                style: 'currency', currency: data.currency || 'EUR'
            });
        } catch (e) {
            formatter = { format: function (number) { return String(number); } };
        }
        function update() {
            var raw = Object.prototype.hasOwnProperty.call(data.prices, select.value) ? data.prices[select.value] : null;
            var value = raw === null ? NaN : Number(raw);
            output.textContent = Number.isFinite(value) && value > 0
                ? formatter.format(value)
                : 'Selecciona una medida para ver su precio';
        }
        select.addEventListener('change', update);
        update();
    }
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.wvm-configurator').forEach(init);
    });
}());
