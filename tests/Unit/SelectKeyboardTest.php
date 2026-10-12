<?php

use Symfony\Component\Process\Process;

test('select keyboard navigates results selects with enter and leaves at tab boundaries', function () {
    $script = <<<'JS'
const fs = require('node:fs');
const assert = require('node:assert/strict');
const source = fs.readFileSync(process.argv[1], 'utf8');
const expression = source.split('x-data="')[1].split('" x-modelable')[0]
    .replace(/value: String\(@js\(.*\)\),/, "value: '',")
    .replace(/openOnMount: @js\(.*\),/, 'openOnMount: false,');
const select = new Function('return (' + expression + ')')();
let focused = null;
const input = { focus() { focused = this; } };
const trigger = { focus() { focused = this; } };
const options = ['42', '43'].map(value => ({
    dataset: { value }, focus() { focused = this; }, scrollIntoView() {}
}));
select.$refs = { trigger, search: { querySelector() { return input; } } };
select.options = () => options;
select.open = true;
select.move(1);
assert.equal(select.activeValue, '42');
assert.equal(focused, options[0]);
select.move(1);
assert.equal(select.activeValue, '43');
assert.equal(focused, options[1]);
select.chooseActive();
assert.equal(select.value, '43');
assert.equal(select.open, false);
assert.equal(focused, trigger);

const tab = (target, shiftKey = false) => {
    let prevented = false;
    select.tab({ target, shiftKey, preventDefault() { prevented = true; } });
    return prevented;
};
select.open = true;
assert.equal(tab(input), true);
assert.equal(focused, options[0]);
assert.equal(tab(options[0]), true);
assert.equal(focused, options[1]);
assert.equal(tab(options[1], true), true);
assert.equal(focused, options[0]);
assert.equal(tab(options[0], true), true);
assert.equal(focused, input);
assert.equal(tab(input, true), false);
assert.equal(select.open, false);
select.open = true;
assert.equal(tab(options[1]), false);
assert.equal(select.open, false);

select.open = true;
select.activeValue = '';
select.options = () => [];
select.move(1);
assert.equal(select.activeValue, '');
assert.equal(tab(input), false);
assert.equal(select.open, false);
JS;
    $process = new Process(['node', '-e', $script, dirname(__DIR__, 2).'/resources/views/components/select.blade.php']);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});
