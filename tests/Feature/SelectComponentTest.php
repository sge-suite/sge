<?php

test('custom select exposes its named form field', function () {
    $this->blade('<x-select label="Cidade" name="address[city_id]" x-model="city" value="42" :options="[42 => \'Santa Rosa\', 43 => \'Alegrete\']" searchable required />')
        ->assertSee('name="address[city_id]"', false);
});
