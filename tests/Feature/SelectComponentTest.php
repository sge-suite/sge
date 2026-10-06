<?php

use Illuminate\Support\Facades\Blade;

test('custom select keeps named values required validation accessible options and flux styling', function () {
    $this->blade('<x-select label="Cidade" name="address[city_id]" x-model="city" value="42" :options="[42 => \'Santa Rosa\', 43 => \'Alegrete\']" searchable required />')
        ->assertSee('name="address[city_id]"', false)
        ->assertSee('value="42" selected', false)
        ->assertSee('role="listbox"', false)->assertSee('role="option"', false)
        ->assertSee('aria-haspopup="listbox"', false)->assertSee('aria-selected="true"', false)
        ->assertSee('select-trigger flex h-10 w-full min-w-0', false)
        ->assertSee('select-label-viewport block min-w-0 flex-1 overflow-hidden', false)
        ->assertSee('x-bind:aria-label="label()"', false)
        ->assertSee('select-label-marquee', false)
        ->assertSee('select-label-fade', false)
        ->assertSee("x-bind:style=\"'--select-label-distance: ' + labelScrollDistance\"", false)
        ->assertSee('whitespace-normal break-words', false)
        ->assertSee('x-model="city"', false)
        ->assertSee('Buscar cidade')->assertSee('Santa Rosa')->assertSee('required', false)
        ->assertSee('dark:bg-white/10', false);

    $this->blade('<x-select label="Situação" :options="[1 => \'Ativo\']" :option-icons="[1 => \'check-circle\']" icon="building-office-2" />')
        ->assertSee('data-flux-icon', false)->assertSee('Ativo')->assertDontSee('Buscar opção')->assertDontSee('@js(', false);
});

test('custom select escapes option content and displays server validation errors', function () {
    $html = Blade::render('<x-select label="Cidade" name="city" :options="$options" invalid error="Selecione uma cidade válida." />', [
        'options' => [1 => '<script>alert(1)</script>'],
    ]);

    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;', 'aria-invalid="true"', 'Selecione uma cidade válida.')
        ->not->toContain('<script>alert(1)</script>');
});
