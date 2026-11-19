/* Configurator is progressive enhancement; the server validates every purchase. */
(function () {
    'use strict';

    function init(el) {
        var config;
        try { config = JSON.parse(el.getAttribute('data-wvm-config') || '{}'); }
        catch (e) { return; }
        var selects = Array.prototype.slice.call(el.querySelectorAll('select[name^="wvm_"]'));
        var output = el.querySelector('[data-wvm-selected-price]');
        var warning = el.querySelector('[data-wvm-warning]');
        var form = el.closest('form.cart');
        var button = form ? form.querySelector('button.single_add_to_cart_button') : null;
        if (!selects.length || !output || !config.prices) return;
        var formatter;
        try {
            formatter = new Intl.NumberFormat(document.documentElement.lang || 'es-ES', {
                style: 'currency', currency: config.currency || 'EUR'
            });
        } catch (e) {
            formatter = {format: function (number) { return String(number); }};
        }

        function selected() {
            var values = {};
            selects.forEach(function (input) {
                values[input.name.slice(4)] = input.value;
            });
            return values;
        }

        function incompatible(values) {
            return (config.exclusions || []).some(function (rule) {
                return values[rule.a_attr] === rule.a_value &&
                       values[rule.b_attr] === rule.b_value;
            });
        }

        function setBlocked(blocked) {
            if (!button) return;
            if (blocked && !button.disabled) {
                button.disabled = true;
                button.dataset.wvmDisabled = '1';
            } else if (!blocked && button.dataset.wvmDisabled === '1') {
                button.disabled = false;
                delete button.dataset.wvmDisabled;
            }
        }

        function update() {
            var choices = selected();
            var complete = selects.every(function (input) { return input.value !== ''; });
            var bad = complete && incompatible(choices);
            if (warning) {
                warning.hidden = !bad;
                warning.textContent = bad
                    ? 'Esta combinación no está disponible. Cambia alguna de las opciones.'
                    : '';
            }
            setBlocked(bad);
            if (!choices.size || !Object.prototype.hasOwnProperty.call(config.prices, choices.size)) {
                output.textContent = 'Selecciona una medida para ver su precio';
                return;
            }
            var price = Number(config.prices[choices.size]);
            selects.forEach(function (input) {
                var key = input.name.slice(4);
                if (key === 'size' || !input.value) return;
                var byAttribute = (config.surcharges || {})[key] || {};
                if (Object.prototype.hasOwnProperty.call(byAttribute, input.value)) {
                    price += Number(byAttribute[input.value]);
                }
            });
            if (!Number.isFinite(price) || price <= 0) {
                output.textContent = 'Precio no disponible';
                return;
            }
            output.textContent = bad
                ? 'Combinación no disponible'
                : (complete ? 'Precio configurado: ' : 'Precio parcial: ') + formatter.format(price);
        }
        selects.forEach(function (input) { input.addEventListener('change', update); });
        if (form) {
            form.addEventListener('submit', function (event) {
                if (incompatible(selected())) {
                    event.preventDefault();
                    update();
                }
            });
        }
        update();
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.wvm-configurator').forEach(init);
    });
}());
