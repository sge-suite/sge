function registerFileUpload() {
    window.Alpine.data('fileUpload', () => ({
        dragging: false,
        dragDepth: 0,
        uploading: false,
        progress: 0,
        pendingFiles: [],
        uploadError: '',

        drag(event, entering = false) {
            if (!Array.from(event.dataTransfer?.types ?? []).includes('Files')) return;

            event.preventDefault();
            if (this.$refs.input.disabled) return;

            if (entering) this.dragDepth++;
            this.dragging = true;
        },

        leave() {
            this.dragDepth = Math.max(0, this.dragDepth - 1);
            if (this.dragDepth === 0) this.dragging = false;
        },

        drop(event) {
            event.preventDefault();
            this.dragDepth = 0;
            this.dragging = false;
            if (this.$refs.input.disabled) return;

            const files = Array.from(event.dataTransfer?.files ?? []);
            if (!files.length) return;
            if (!this.$refs.input.multiple && files.length > 1) {
                this.uploadError = 'Envie apenas um arquivo por vez.';
                return;
            }

            const transfer = new DataTransfer();
            files.forEach(file => transfer.items.add(file));
            this.$refs.input.files = transfer.files;
            this.$refs.input.dispatchEvent(new Event('change', { bubbles: true }));
        },

        start() {
            this.uploadError = '';
            this.uploading = true;
            this.progress = 0;
            this.pendingFiles = Array.from(this.$refs.input.files).map(file => ({ name: file.name, size: file.size }));
        },

        finish() {
            this.uploading = false;
            this.pendingFiles = [];
            this.progress = 0;
        },

        fail() {
            this.finish();
            this.uploadError = 'Não foi possível enviar o arquivo. Selecione-o novamente e tente outra vez.';
        },

        formatSize(bytes) {
            if (bytes < 1000) return `${bytes} B`;
            const unit = bytes < 1000000 ? 'KB' : 'MB';
            const size = bytes / (unit === 'KB' ? 1000 : 1000000);
            return `${new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 1 }).format(size)} ${unit}`;
        },
    }));
}

if (window.Alpine) {
    registerFileUpload();
} else {
    document.addEventListener('alpine:init', registerFileUpload, { once: true });
}
