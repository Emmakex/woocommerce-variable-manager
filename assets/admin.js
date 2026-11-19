/* WooCommerce classic product editor only. No jQuery or page builder required. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var select = document.getElementById('wvm_template_id');
        var tbody = document.getElementById('wvm-price-rows');
        if (!select || !tbody || typeof wvmAdminData === 'undefined') {
            return;
        }

        var originalTemplate = String(select.value);
        var originalPrices = wvmAdminData.savedPrices || {};

        function makeCell(row, text) {
            var td = document.createElement('td');
            td.textContent = text;
            row.appendChild(td);
            return td;
        }

        function render() {
            tbody.replaceChildren();
            var template = wvmAdminData.templates[String(select.value)] || {};
            var sizes = Array.isArray(template.size) ? template.size : [];
            if (!sizes.length) {
                var empty = document.createElement('tr');
                var message = makeCell(empty, select.value === '0' ? 'Selecciona una plantilla.' : 'La plantilla no tiene medidas.');
                message.colSpan = 2;
                tbody.appendChild(empty);
                return;
            }
            sizes.forEach(function (size) {
                var row = document.createElement('tr');
                makeCell(row, size);

                var inputSize = document.createElement('input');
                inputSize.type = 'hidden';
                inputSize.name = 'wvm_matrix_sizes[]';
                inputSize.value = size;

                var inputPrice = document.createElement('input');
                inputPrice.type = 'text';
                inputPrice.name = 'wvm_matrix_prices[]';
                inputPrice.inputMode = 'decimal';
                inputPrice.autocomplete = 'off';
                inputPrice.placeholder = '0,00';
                inputPrice.required = true;
                inputPrice.value = String(select.value) === originalTemplate && Object.prototype.hasOwnProperty.call(originalPrices, size)
                    ? String(originalPrices[size])
                    : '';

                var priceCell = document.createElement('td');
                priceCell.appendChild(inputSize);
                priceCell.appendChild(inputPrice);
                row.appendChild(priceCell);
                tbody.appendChild(row);
            });
        }

        select.addEventListener('change', render);
        render();
    });
}());
