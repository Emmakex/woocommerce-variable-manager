/* Plugin-owned dashboard: light, accessible, no Gutenberg or page builder. */
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
        var cache = {};

        function makeCell(row, text) {
            var td = document.createElement('td');
            td.textContent = text;
            row.appendChild(td);
            return td;
        }

        function remember(template) {
            if (!template) return;
            var current = {};
            tbody.querySelectorAll('tr').forEach(function (row) {
                var size = row.querySelector('input[name="wvm_matrix_sizes[]"]');
                var price = row.querySelector('input[name="wvm_matrix_prices[]"]');
                if (size && price) current[size.value] = price.value;
            });
            cache[template] = current;
        }

        function render() {
            tbody.replaceChildren();
            var template = wvmAdminData.templates[String(select.value)] || {};
            var sizes = Array.isArray(template.size) ? template.size : [];
            if (!sizes.length) {
                var empty = document.createElement('tr');
                var message = makeCell(empty, select.value === '' ? 'Selecciona una plantilla.' : 'La plantilla no tiene medidas.');
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
                inputPrice.setAttribute('aria-label', 'Precio para ' + size);
                if (cache[String(select.value)] && Object.prototype.hasOwnProperty.call(cache[String(select.value)], size)) {
                    inputPrice.value = cache[String(select.value)][size];
                } else if (String(select.value) === originalTemplate && Object.prototype.hasOwnProperty.call(originalPrices, size)) {
                    inputPrice.value = String(originalPrices[size]);
                } else {
                    inputPrice.value = '';
                }

                var priceCell = document.createElement('td');
                priceCell.appendChild(inputSize);
                priceCell.appendChild(inputPrice);
                row.appendChild(priceCell);
                tbody.appendChild(row);
            });
        }

        var previous = String(select.value);
        select.addEventListener('change', function () {
            remember(previous);
            previous = String(select.value);
            render();
        });
        render();

        var bulkButton = document.getElementById('wvm_apply_bulk');
        var bulkInput = document.getElementById('wvm_bulk_prices');
        var bulkStatus = document.getElementById('wvm_bulk_status');
        if (bulkButton && bulkInput && bulkStatus) {
            bulkButton.addEventListener('click', function () {
                var inputMap = {};
                tbody.querySelectorAll('tr').forEach(function (row) {
                    var size = row.querySelector('input[name="wvm_matrix_sizes[]"]');
                    var price = row.querySelector('input[name="wvm_matrix_prices[]"]');
                    if (size && price) inputMap[size.value] = price;
                });
                var applied = 0;
                var skipped = 0;
                bulkInput.value.split(/\r?\n/).forEach(function (line) {
                    if (!line.trim()) return;
                    var separator = line.indexOf(';');
                    if (separator === -1) { skipped++; return; }
                    var size = line.slice(0, separator).trim();
                    var price = line.slice(separator + 1).trim();
                    if (!Object.prototype.hasOwnProperty.call(inputMap, size) ||
                        !/^\d{1,9}([.,]\d{1,4})?$/.test(price) || Number(price.replace(',', '.')) <= 0) {
                        skipped++;
                        return;
                    }
                    inputMap[size].value = price;
                    applied++;
                });
                bulkStatus.textContent = applied + ' precios aplicados' + (skipped ? ', ' + skipped + ' líneas ignoradas.' : '.');
                remember(String(select.value));
            });
        }

        var imageButton = document.getElementById('wvm_choose_image');
        var removeImage = document.getElementById('wvm_remove_image');
        var imageInput = document.getElementById('wvm_image_id');
        var imagePreview = document.getElementById('wvm_image_preview');
        if (imageButton && imageInput && imagePreview && window.wp && window.wp.media) {
            var frame;
            imageButton.addEventListener('click', function () {
                if (!frame) {
                    frame = window.wp.media({
                        title: 'Seleccionar imagen del producto',
                        button: { text: 'Usar imagen' },
                        library: { type: 'image' },
                        multiple: false
                    });
                    frame.on('select', function () {
                        var selected = frame.state().get('selection').first();
                        if (!selected) return;
                        var photo = selected.toJSON();
                        imageInput.value = String(photo.id);
                        imagePreview.src = photo.sizes && photo.sizes.thumbnail ? photo.sizes.thumbnail.url : photo.url;
                        imagePreview.hidden = false;
                    });
                }
                frame.open();
            });
            if (removeImage) {
                removeImage.addEventListener('click', function () {
                    imageInput.value = '0';
                    imagePreview.removeAttribute('src');
                    imagePreview.hidden = true;
                });
            }
        }
    });
}());
