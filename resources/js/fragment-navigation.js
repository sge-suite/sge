let restoringHistory = false;

function scrollToFragment() {
    const hash = window.location.hash;
    if (!hash || hash === '#') return;

    let id;
    try {
        id = decodeURIComponent(hash.slice(1));
    } catch {
        return;
    }

    requestAnimationFrame(() => {
        if (window.location.hash !== hash) return;
        const behavior = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth';
        document.getElementById(id)?.scrollIntoView({ block: 'start', behavior });
    });
}

document.addEventListener('livewire:navigating', (event) => {
    restoringHistory = event.detail?.history === true;
});

document.addEventListener('livewire:navigated', () => {
    if (!restoringHistory) scrollToFragment();
    restoringHistory = false;
});
