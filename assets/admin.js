/* Variable Manager: single page product and tariff editor, framework-free. */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var select = document.getElementById('wvm_template_id');
        var prices = document.getElementById('wvm-price-rows');
        var extraRows = document.getElementById('wvm-surcharge-rows');
        var rulesRows = document.getElementById('wvm-exclusion-rows');
        var addRule = document.getElementById('wvm-add-exclusion');
        var ready = document.getElementById('wvm_rules_ready');
        if (!select || !prices || !extraRows || !rulesRows || !addRule ||
            !ready || typeof wvmAdminData === 'undefined') return;

        var originalId = String(select.value);
        var previousId = originalId;
        var cache = {};
        var labels = wvmAdminData.labels || {};
        var originalPrices = wvmAdminData.savedPrices || {};
        var originalExtras = wvmAdminData.savedSurcharges || {};
        var originalRules = wvmAdminData.savedExclusions || [];

        function optionKeys(template) {
            return ['size', 'color', 'thickness', 'opening'].filter(function (key) {
                return Array.isArray(template[key]) && template[key].length;
            });
        }

        function templateById(id) {
            return wvmAdminData.templates[id] || {};
        }

        function element(tag, settings, value) {
            var el = document.createElement(tag);
            Object.keys(settings || {}).forEach(function (key) {
                if (key === 'className') el.className = settings[key];
                else el.setAttribute(key, settings[key]);
            });
            if (value !== undefined) el.textContent = String(value);
            return el;
        }

        function hidden(name, value) {
            return element('input', {type: 'hidden', name: name, value: value});
        }

        function valuesInRow(row) {
            var value = {};
            row.querySelectorAll('input,select').forEach(function (field) {
                if (field.name) value[field.name] = field.value;
            });
            return value;
        }

        function snapshot() {
            var state = { prices: {}, extras: {}, rules: [] };
            prices.querySelectorAll('tr').forEach(function (row) {
                var s = row.querySelector('[name="wvm_matrix_sizes[]"]');
                var p = row.querySelector('[name="wvm_matrix_prices[]"]');
                if (s && p) state.prices[s.value] = p.value;
            });
            extraRows.querySelectorAll('tr').forEach(function (row) {
                var a = row.querySelector('[name="wvm_extra_attr[]"]');
                var v = row.querySelector('[name="wvm_extra_value[]"]');
                var p = row.querySelector('[name="wvm_extra_amount[]"]');
                if (a && v && p) {
                    if (!state.extras[a.value]) state.extras[a.value] = {};
                    state.extras[a.value][v.value] = p.value;
                }
            });
            rulesRows.querySelectorAll('.wvm-rule-row').forEach(function (row) {
                var fields = valuesInRow(row);
                state.rules.push({
                    a_attr: fields['wvm_rule_a_attr[]'], a_value: fields['wvm_rule_a_value[]'],
                    b_attr: fields['wvm_rule_b_attr[]'], b_value: fields['wvm_rule_b_value[]']
                });
            });
            return state;
        }

        function source(id) {
            if (cache[id]) return cache[id];
            return id === originalId
                ? {prices: originalPrices, extras: originalExtras, rules: originalRules}
                : {prices: {}, extras: {}, rules: []};
        }

        function selectedValue(obj, attr, value) {
            return obj && obj[attr] && Object.prototype.hasOwnProperty.call(obj[attr], value)
                ? String(obj[attr][value]) : '0';
        }

        function renderPriceRows(template, state) {
            prices.replaceChildren();
            var sizes = template.size || [];
            if (!sizes.length) {
                var empty = element('tr');
                var cell = element('td', {colspan: '2'}, 'Selecciona una plantilla con medidas.');
                empty.appendChild(cell);
                prices.appendChild(empty);
            }
            sizes.forEach(function (size) {
                var tr = element('tr');
                tr.appendChild(element('td', {}, size));
                var td = element('td');
                td.appendChild(hidden('wvm_matrix_sizes[]', size));
                var input = element('input', {
                    type: 'text', name: 'wvm_matrix_prices[]',
                    inputmode: 'decimal', autocomplete: 'off',
                    placeholder: '0,00', required: 'required',
                    'aria-label': 'Precio de ' + size
                });
                input.value = Object.prototype.hasOwnProperty.call(state.prices, size)
                    ? String(state.prices[size]) : '';
                td.appendChild(input);
                tr.appendChild(td);
                prices.appendChild(tr);
            });
        }

        function renderExtras(template, state) {
            extraRows.replaceChildren();
            var count = 0;
            ['color', 'thickness', 'opening'].forEach(function (attr) {
                (template[attr] || []).forEach(function (value) {
                    count++;
                    var tr = element('tr');
                    tr.appendChild(element('td', {}, (labels[attr] || attr) + ': ' + value));
                    var td = element('td');
                    td.appendChild(hidden('wvm_extra_attr[]', attr));
                    td.appendChild(hidden('wvm_extra_value[]', value));
                    var input = element('input', {
                        type: 'text', name: 'wvm_extra_amount[]',
                        inputmode: 'decimal', autocomplete: 'off',
                        required: 'required', 'aria-label': 'Suplemento para ' + value
                    });
                    input.value = selectedValue(state.extras, attr, value);
                    td.appendChild(input);
                    tr.appendChild(td);
                    extraRows.appendChild(tr);
                });
            });
            if (!count) {
                var tr = element('tr');
                tr.appendChild(element('td', {colspan: '2'}, 'Esta plantilla no tiene opciones con suplementos.'));
                extraRows.appendChild(tr);
            }
        }

        function makeSelector(name, choices, labelText, value) {
            var el = element('select', {name: name, 'aria-label': labelText, required: 'required'});
            choices.forEach(function (item) {
                var option = element('option', {value: item.value}, item.label);
                el.appendChild(option);
            });
            if (value && choices.some(function (item) { return item.value === value; })) {
                el.value = value;
            }
            return el;
        }

        function ruleEditor(template, saved) {
            var keys = optionKeys(template);
            if (keys.length < 2) return null;
            saved = saved || {};
            var row = element('div', {className: 'wvm-rule-row'});
            var attributeChoices = keys.map(function (key) {
                return {value: key, label: labels[key] || key};
            });

            var aAttr = makeSelector('wvm_rule_a_attr[]', attributeChoices, 'Primer atributo', saved.a_attr || keys[0]);
            var bAttr = makeSelector('wvm_rule_b_attr[]', attributeChoices, 'Segundo atributo', saved.b_attr || keys[1]);
            var aValue = makeSelector('wvm_rule_a_value[]', [], 'Primer valor');
            var bValue = makeSelector('wvm_rule_b_value[]', [], 'Segundo valor');

            function updateValues(attrSelect, valueSelect, preferred) {
                var choices = (template[attrSelect.value] || []).map(function (value) {
                    return {value: value, label: value};
                });
                valueSelect.replaceChildren();
                choices.forEach(function (choice) {
                    valueSelect.appendChild(element('option', {value: choice.value}, choice.label));
                });
                if (preferred && choices.some(function (choice) { return choice.value === preferred; })) {
                    valueSelect.value = preferred;
                }
            }
            function keepDifferent(changed) {
                if (aAttr.value === bAttr.value) {
                    var other = keys.find(function (k) { return k !== changed.value; });
                    if (other) (changed === aAttr ? bAttr : aAttr).value = other;
                }
                updateValues(aAttr, aValue);
                updateValues(bAttr, bValue);
            }
            aAttr.addEventListener('change', function () { keepDifferent(aAttr); });
            bAttr.addEventListener('change', function () { keepDifferent(bAttr); });
            updateValues(aAttr, aValue, saved.a_value);
            updateValues(bAttr, bValue, saved.b_value);
            row.appendChild(aAttr);
            row.appendChild(aValue);
            row.appendChild(element('span', {}, 'no disponible con'));
            row.appendChild(bAttr);
            row.appendChild(bValue);
            var remove = element('button', {type: 'button', className: 'button-link-delete'}, 'Quitar');
            remove.addEventListener('click', function () { row.remove(); });
            row.appendChild(remove);
            return row;
        }

        function renderRules(template, state) {
            rulesRows.replaceChildren();
            var keys = optionKeys(template);
            addRule.disabled = keys.length < 2;
            (state.rules || []).forEach(function (rule) {
                var row = ruleEditor(template, rule);
                if (row) rulesRows.appendChild(row);
            });
        }

        function render() {
            var template = templateById(String(select.value));
            var state = source(String(select.value));
            renderPriceRows(template, state);
            renderExtras(template, state);
            renderRules(template, state);
        }

        select.addEventListener('change', function () {
            if (previousId) cache[previousId] = snapshot();
            previousId = String(select.value);
            render();
        });
        addRule.addEventListener('click', function () {
            var template = templateById(String(select.value));
            var row = ruleEditor(template);
            if (row && rulesRows.children.length < 60) rulesRows.appendChild(row);
        });
        render();
        ready.value = '1';

        // Paste measure;price from Excel or CSV — only known measures are modified.
        var bulkButton = document.getElementById('wvm_apply_bulk');
        var bulkInput = document.getElementById('wvm_bulk_prices');
        var bulkStatus = document.getElementById('wvm_bulk_status');
        if (bulkButton && bulkInput && bulkStatus) {
            bulkButton.addEventListener('click', function () {
                var rows = {};
                prices.querySelectorAll('tr').forEach(function (tr) {
                    var size = tr.querySelector('[name="wvm_matrix_sizes[]"]');
                    var price = tr.querySelector('[name="wvm_matrix_prices[]"]');
                    if (size && price) rows[size.value] = price;
                });
                var done = 0;
                var skipped = 0;
                bulkInput.value.split(/\r?\n/).forEach(function (line) {
                    if (!line.trim()) return;
                    var pos = line.indexOf(';');
                    if (pos === -1) { skipped++; return; }
                    var size = line.slice(0, pos).trim();
                    var amount = line.slice(pos + 1).trim();
                    if (!Object.prototype.hasOwnProperty.call(rows, size) ||
                        !/^\d{1,9}([.,]\d{1,4})?$/.test(amount) ||
                        Number(amount.replace(',', '.')) <= 0) { skipped++; return; }
                    rows[size].value = amount;
                    done++;
                });
                bulkStatus.textContent = done + ' precios aplicados' + (skipped ? ', ' + skipped + ' filas ignoradas.' : '.');
            });
        }

        // Native WordPress image picker.
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
                        button: {text: 'Usar imagen'},
                        library: {type: 'image'}, multiple: false
                    });
                    frame.on('select', function () {
                        var chosen = frame.state().get('selection').first();
                        if (!chosen) return;
                        var photo = chosen.toJSON();
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
