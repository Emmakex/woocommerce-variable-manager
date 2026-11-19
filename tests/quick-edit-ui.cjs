/* No browser or npm packages required; simulation of quick-edit DOM behavior. */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const events = {};
const documentEvents = {};
const windowEvents = {};
const listeners = {};
const changes = new Set();

function field(original, current) {
    const attrs = {'data-original': original};
    return {
        value: current,
        classes: new Set(),
        invalid: '',
        aria: '',
        getAttribute: name => attrs[name],
        classList: {
            toggle(name, enabled) {
                if (enabled) changes.add(name);
                else changes.delete(name);
            }
        },
        setAttribute(name, value) {
            if (name === 'aria-invalid') this.aria = value;
        },
        setCustomValidity(message) { this.invalid = message; }
    };
}

const fields = [field('299.90', '299.90'), field('135.00', '135.00')];
const counter = {textContent: ''};
const save = {disabled: false};
const undo = {disabled: false, addEventListener(name, handler) { listeners['undo-' + name] = handler; }};
const form = {
    getAttribute: name => name === 'data-wvm-decimals' ? '2' : '',
    querySelectorAll: selector => selector === '.wvm-quick-price' ? fields : [],
    querySelector: selector => selector === '[data-wvm-changed-count]' ? counter : null,
    addEventListener(name, handler) { events[name] = handler; }
};
const document = {
    addEventListener: (event, callback) => { documentEvents[event] = callback; },
    getElementById: id => ({
        'wvm-quick-form': form,
        'wvm-quick-save': save,
        'wvm-quick-undo': undo
    })[id] || null
};
const window = {
    addEventListener: (event, handler) => { windowEvents[event] = handler; }
};

const script = fs.readFileSync(path.join(__dirname, '../assets/quick-edit.js'), 'utf8');
vm.runInNewContext(script, {document, window, Array, Number, String, Math, RegExp, Set});
assert.equal(typeof documentEvents.DOMContentLoaded, 'function');
documentEvents.DOMContentLoaded();

assert.equal(counter.textContent, '0', 'initial count');
assert.equal(save.disabled, true, 'do not save unchanged values');
assert.equal(undo.disabled, true, 'do not reset unchanged values');

fields[0].value = '320,90';
events.input();
assert.equal(counter.textContent, '1', 'Spanish comma counts as a single change');
assert.equal(save.disabled, false, 'valid changes may be saved');
assert.equal(undo.disabled, false, 'undo enabled when dirty');

let prevented = false;
const unload = {preventDefault() {prevented = true; }, returnValue: undefined};
windowEvents.beforeunload(unload);
assert.equal(prevented, true, 'warn when leaving dirty form');

fields[1].value = '-1';
events.input();
assert.equal(save.disabled, true, 'invalid amount disables save');
assert.equal(fields[1].aria, 'true', 'invalid price marked for assistive technology');
assert.ok(fields[1].invalid, 'field has native validation message');

listeners['undo-click']();
assert.equal(fields[0].value, '299.90', 'undo restores original price');
assert.equal(fields[1].value, '135.00', 'undo restores all prices');
assert.equal(counter.textContent, '0', 'undo clears changed count');
assert.equal(save.disabled, true, 'undo prevents redundant save');

fields[0].value = '315.50';
events.input();
let rejected = false;
events.submit({preventDefault() { rejected = true; }});
assert.equal(rejected, false, 'valid change allows form submit');
let warnedAfterSubmit = false;
windowEvents.beforeunload({preventDefault() {warnedAfterSubmit = true; }, returnValue: undefined});
assert.equal(warnedAfterSubmit, false, 'submitted form does not show unsaved warning');

console.log('Quick edit UX smoke PASS: changed count, comma decimals, invalid input, undo and unsaved-change guard.');
