import { test } from 'node:test';
import assert from 'node:assert/strict';
import { normalizeDecimalInput } from '../../resources/js/decimal-input.js';

test('typing or pasting commas displays points and preserves the caret before Livewire reads the value', () => {
    for (const value of ['1,5', '0,5', '3,', '1,25', '1,,5']) {
        let selection;
        const input = {
            value,
            selectionStart: 2,
            selectionEnd: 2,
            matches: selector => selector === 'input[data-decimal-input]',
            setSelectionRange: (start, end) => { selection = [start, end]; },
        };
        normalizeDecimalInput({ target: input });
        assert.equal(input.value, value.replaceAll(',', '.'));
        assert.deepEqual(selection, [2, 2]);
    }
});

test('dot decimals and other form inputs keep their value and selection', () => {
    for (const [value, matches] of [['1.5', true], ['Nome, sobrenome', false]]) {
        const input = {
            value,
            matches: () => matches,
            setSelectionRange: () => { throw new Error('Selection must remain untouched'); },
        };
        normalizeDecimalInput({ target: input });
        assert.equal(input.value, value);
    }
    normalizeDecimalInput({ target: {} });
});
