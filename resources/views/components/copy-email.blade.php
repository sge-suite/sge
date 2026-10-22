@props(['email'])

<span
    x-data="{
        async copy() {
            try {
                await navigator.clipboard.writeText(this.$refs.email.textContent.trim());
                this.$flux.toast('E-mail copiado!', { variant: 'success' });
            } catch {
                this.$flux.toast('Não foi possível copiar. Selecione e copie o endereço.', { variant: 'danger' });
            }
        },
    }"
    {{ $attributes }}
>
    <flux:link as="button" class="cursor-copy text-left wrap-anywhere" x-on:click="copy()" title="Clique para copiar o e-mail" aria-label="Copiar e-mail: {{ $email }}">
        <span x-ref="email">{{ $email }}</span>
    </flux:link>
</span>
