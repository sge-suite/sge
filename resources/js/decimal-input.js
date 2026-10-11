export function normalizeDecimalInput(event) {
    const input = event.target;

    if (!input?.matches?.('input[data-decimal-input]') || !input.value.includes(',')) {
        return;
    }

    const start = input.selectionStart;
    const end = input.selectionEnd;
    input.value = input.value.replaceAll(',', '.');
    input.setSelectionRange(start, end);
}
