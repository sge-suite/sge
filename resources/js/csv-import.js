function registerImportComponent() {
    window.Alpine.data('csvImport', () => ({
        processing: false,
        stopped: false,
        disposed: false,
        message: '',

        async start() {
            if (this.processing || this.disposed) return;

            this.processing = true;
            this.stopped = false;
            this.message = '';

            try {
                while (!this.stopped && !this.disposed) {
                    const more = await this.$wire.processNextBatch();
                    if (!more) break;
                }
            } catch {
                this.message = 'O processamento foi pausado. Confira os cadastros antes de continuar. Se sair desta tela, reenvie o arquivo e revise a nova prévia.';
            } finally {
                this.processing = false;
            }
        },

        pause() {
            this.stopped = true;
        },

        destroy() {
            this.stopped = true;
            this.disposed = true;
        },
    }));
}

if (window.Alpine) {
    registerImportComponent();
} else {
    document.addEventListener('alpine:init', registerImportComponent, { once: true });
}
