<?php

use Illuminate\Support\Facades\Blade;

test('accordion renders caller supplied title and content without password-specific copy', function () {
    $html = Blade::render('<x-accordion title="Detalhes" :open="true"><p>Conteúdo livre.</p></x-accordion>');

    expect($html)->toContain('Detalhes', 'Conteúdo livre.', 'data-open="true"', 'aria-expanded="true"')
        ->not->toContain('senha');
});
