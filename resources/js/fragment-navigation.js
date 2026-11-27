let restoringHistory = false;

function scrollToFragment() {
    const hash = window.location.hash;
    if (!hash || hash === '#') return false;

    let id;
    try {
        id = decodeURIComponent(hash.slice(1));
    } catch {
        return false;
    }

    const target = document.getElementById(id);
    if (!target) return false;

    requestAnimationFrame(() => {
        if (window.location.hash !== hash) return;
        const behavior = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth';
        target.scrollIntoView({ block: 'start', behavior });
        if (!target.hasAttribute('tabindex')) {
            target.setAttribute('tabindex', '-1');
        }
        target.focus({ preventScroll: true });
    });

    return true;
}

function focusMainContentAfterNavigation() {
    requestAnimationFrame(() => {
        const active = document.activeElement;
        const insideChrome = active instanceof HTMLElement
            && (active.closest('[data-flux-sidebar]') !== null || active.closest('header') !== null);

        if (active === null || active === document.body || insideChrome) {
            document.getElementById('main-content')?.focus({ preventScroll: true });
        }
    });
}

document.addEventListener('livewire:navigating', (event) => {
    restoringHistory = event.detail?.history === true;
});

document.addEventListener('livewire:navigated', () => {
    if (!restoringHistory) {
        const focusedFragment = scrollToFragment();
        if (!focusedFragment) {
            focusMainContentAfterNavigation();
        }
    }
    restoringHistory = false;
});
