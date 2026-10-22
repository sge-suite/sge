@props(['label', 'options' => [], 'value' => '', 'name' => '', 'placeholder' => 'Escolha ou crie uma opção', 'error' => '', 'required' => false, 'maxLength' => 5000])

<x-select :label="$label" :options="$options" :value="$value" :name="$name" :placeholder="$placeholder"
    :error="$error" :required="$required" :max-length="$maxLength" :invalid="$error !== ''" searchable creatable {{ $attributes }} />
