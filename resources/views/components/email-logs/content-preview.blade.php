@props(['message'])

@if ($message?->content_html || $message?->content_text)
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-white">
        <div class="scroll-fade-y max-h-[70vh] overflow-y-auto overscroll-contain" tabindex="0" role="region" aria-label="Conteúdo do e-mail registrado">
            @if ($message?->content_html)
                @php
                    $preview = '<meta http-equiv="Content-Security-Policy" content="default-src &apos;none&apos;; style-src &apos;unsafe-inline&apos;; img-src data:; base-uri &apos;none&apos;; form-action &apos;none&apos;">'.$message->content_html;
                @endphp
                <iframe
                    title="Prévia do e-mail registrado"
                    srcdoc="{{ $preview }}"
                    sandbox="allow-same-origin"
                    referrerpolicy="no-referrer"
                    class="block w-full bg-white"
                    style="height: 1px"
                    x-data="{
                        observer: null,
                        resizePreview() {
                            const document = this.$el.contentDocument;
                            if (! document?.body) return;
                            const body = document.body;
                            const styles = getComputedStyle(body);
                            const margins = parseFloat(styles.marginTop) + parseFloat(styles.marginBottom);
                            this.$el.style.height = Math.ceil(Math.max(body.scrollHeight, body.getBoundingClientRect().height) + margins) + 'px';
                        },
                        observePreview() {
                            this.observer?.disconnect();
                            const document = this.$el.contentDocument;
                            if (! document?.body) return;
                            document.documentElement.style.overflow = 'hidden';
                            this.observer = new ResizeObserver(() => this.resizePreview());
                            this.observer.observe(document.body);
                            this.resizePreview();
                        },
                        destroy() { this.observer?.disconnect(); }
                    }"
                    x-init="$nextTick(() => observePreview())"
                    x-on:load="observePreview()"
                ></iframe>
            @else
                <pre class="whitespace-pre-wrap break-words p-6 font-sans">{{ $message->content_text }}</pre>
            @endif
        </div>
    </div>
@else
    <flux:text>O conteúdo deste envio não foi armazenado.</flux:text>
@endif
