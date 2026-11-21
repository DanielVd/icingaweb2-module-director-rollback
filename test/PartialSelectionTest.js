const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const Icinga = { availableModules: {} };
vm.runInNewContext(fs.readFileSync(require.resolve('../public/js/module.js'), 'utf8'), { Icinga });
let change;
new Icinga.availableModules.director_rollback({
    on(event, selector, listener) {
        assert.equal(event, 'change');
        assert.equal(selector, 'input[data-rollback-choice]');
        change = listener;
    }
});

const links = {
    1: [2, 3], // Pending host and its two services.
    2: [1],
    3: [1],
    4: [1], // Template removal requires the pending host.
    5: [], // Service with a deployed, unchanged host.
};
const choices = Object.keys(links).map(value => ({
    value, checked: false, name: 'objects[]',
    removeAttribute(attribute) { delete this[attribute]; }
}));
const form = { dataset: { rollbackLinks: JSON.stringify(links) }, querySelectorAll: () => choices };
for (const choice of choices) {
    choice.form = form;
}

function toggle(id, checked) {
    const choice = choices.find(item => item.value === id);
    choice.checked = checked;
    change({ currentTarget: choice });
}

function expect(selected, submitted) {
    assert.deepEqual(choices.filter(choice => choice.checked).map(choice => choice.value), selected);
    assert.deepEqual(choices.filter(choice => choice.checked && choice.name === 'objects[]').map(choice => choice.value), submitted);
}

toggle('2', true);
expect(['1', '2', '3'], ['2']);
toggle('5', true);
expect(['1', '2', '3', '5'], ['2', '5']);
toggle('1', false);
expect(['5'], ['5']);
toggle('4', true);
expect(['1', '2', '3', '4', '5'], ['4', '5']);
toggle('3', false);
expect(['5'], ['5']);
toggle('1', true);
expect(['1', '2', '3', '5'], ['1', '5']);
console.log('Partial selection tests passed');
