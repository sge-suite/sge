window.__sgeIsMac = /(Mac|iPhone|iPad|iPod)/i.test(
    navigator.userAgentData?.platform || navigator.platform || navigator.userAgent,
);

function isEditableTarget(el) {
    return el instanceof HTMLElement
        && (el.matches('input, textarea, select')
            || el.isContentEditable
            || el.closest('[contenteditable="true"]') !== null);
}

function hasOpenDialog() {
    return document.querySelector('dialog[open]') !== null;
}

function isVisible(el) {
    return el instanceof HTMLElement
        && (el.offsetParent !== null || el.getClientRects().length > 0);
}

function findPageSearchInput() {
    const scope = document.getElementById('main-content') ?? document;
    const candidates = scope.querySelectorAll(
        'input[type="search"], input[wire\\:model*="search"], input[placeholder*="Buscar"], input[placeholder*="Pesquisar"]',
    );

    for (const input of candidates) {
        if (input instanceof HTMLInputElement && !input.disabled && !input.readOnly && isVisible(input) && !input.closest('dialog')) {
            return input;
        }
    }

    return null;
}

function findSafeModalButton(dialog) {
    const closeSlot = dialog.querySelector('[data-flux-modal-close] button:not([disabled])');
    if (closeSlot instanceof HTMLButtonElement && isVisible(closeSlot) && closeSlot.getAttribute('aria-label') !== 'Close modal') {
        return closeSlot;
    }

    const buttons = Array.from(dialog.querySelectorAll('button:not([disabled])')).filter(isVisible);
    const safeByText = buttons.find((button) => /^(cancelar|fechar)$/i.test(button.textContent?.trim() ?? ''));
    if (safeByText) {
        return safeByText;
    }

    return buttons.find((button) => button.getAttribute('aria-label') !== 'Close modal') ?? buttons[0] ?? null;
}

function focusDialogOnOpen(dialog) {
    requestAnimationFrame(() => {
        if (!dialog.hasAttribute('open')) {
            return;
        }

        const auto = dialog.querySelector('[autofocus]');
        if (auto instanceof HTMLElement && isVisible(auto)) {
            auto.focus({ preventScroll: true });
            return;
        }

        const controls = dialog.querySelectorAll(
            'input:not([type="hidden"]):not([disabled]), textarea:not([disabled]), select:not([disabled])',
        );
        for (const control of controls) {
            if (isVisible(control)) {
                control.focus({ preventScroll: true });
                return;
            }
        }

        const safeButton = findSafeModalButton(dialog);
        safeButton?.focus({ preventScroll: true });
    });
}

let lastFocusBeforeDialog = null;

document.addEventListener('focusin', (event) => {
    if (event.target instanceof HTMLElement && !event.target.closest('dialog')) {
        lastFocusBeforeDialog = event.target;
    }
});

const dialogObserver = new MutationObserver((mutations) => {
    for (const mutation of mutations) {
        if (!(mutation.target instanceof HTMLDialogElement)) {
            continue;
        }

        const dialog = mutation.target;
        if (dialog.hasAttribute('open')) {
            focusDialogOnOpen(dialog);
        } else {
            requestAnimationFrame(() => {
                const active = document.activeElement;
                if ((!active || active === document.body)
                    && lastFocusBeforeDialog?.isConnected
                    && isVisible(lastFocusBeforeDialog)) {
                    lastFocusBeforeDialog.focus({ preventScroll: true });
                }
            });
        }
    }
});

if (document.body) {
    dialogObserver.observe(document.body, {
        subtree: true,
        attributes: true,
        attributeFilter: ['open'],
    });
}

document.addEventListener('keydown', (event) => {
    if (event.defaultPrevented) {
        return;
    }

    if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
        if (!(event.target instanceof HTMLElement)) {
            return;
        }

        const form = event.target.closest('form');
        if (!form || form.dataset.noCtrlEnter !== undefined) {
            return;
        }

        const submitButton = form.querySelector(
            'button[type="submit"]:not([disabled]), button:not([type]):not([disabled]), input[type="submit"]:not([disabled])',
        );
        if (!submitButton) {
            return;
        }

        event.preventDefault();
        form.requestSubmit(submitButton);
        return;
    }

    if (event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey) {
        if (isEditableTarget(event.target) || hasOpenDialog()) {
            return;
        }

        const searchInput = findPageSearchInput();
        if (searchInput) {
            event.preventDefault();
            searchInput.focus();
            searchInput.select();
        }
        return;
    }

    if (event.key === 'Escape' && event.target instanceof HTMLInputElement) {
        if (!event.target.closest('dialog') && !event.target.closest('.select-search, [data-select-list]')) {
            if (event.target.matches('input[type="search"], input[wire\\:model*="search"], input[placeholder*="Buscar"], input[placeholder*="Pesquisar"]')) {
                event.target.blur();
            }
        }
        return;
    }

    if (event.key === '?' && !event.ctrlKey && !event.metaKey && !event.altKey) {
        if (isEditableTarget(event.target) || hasOpenDialog()) {
            return;
        }

        event.preventDefault();
        window.dispatchEvent(new CustomEvent('sge:open-shortcuts'));
        return;
    }

    if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && !event.ctrlKey && !event.metaKey && !event.altKey) {
        if (hasOpenDialog() || !(event.target instanceof HTMLAnchorElement)) {
            return;
        }

        const currentRow = event.target.closest('tbody tr');
        const tbody = currentRow?.parentElement;
        if (!currentRow || !tbody) {
            return;
        }

        const rowTargets = Array.from(tbody.querySelectorAll('tr'))
            .map((row) => Array.from(row.querySelectorAll('a[href]')).find(isVisible) ?? null)
            .filter((link) => link !== null);

        const currentIndex = rowTargets.indexOf(event.target);
        if (currentIndex === -1) {
            return;
        }

        const delta = event.key === 'ArrowDown' ? 1 : -1;
        const nextIndex = Math.max(0, Math.min(currentIndex + delta, rowTargets.length - 1));
        if (nextIndex !== currentIndex) {
            event.preventDefault();
            const nextLink = rowTargets[nextIndex];
            nextLink.focus();
            nextLink.scrollIntoView({ block: 'nearest' });
        }
    }
});
