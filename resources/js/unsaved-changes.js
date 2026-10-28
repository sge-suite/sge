const roots = new Map();
let refreshQueued = false;
let allowUnload = false;
let livewireRegistered = false;
let unloadGuardAttached = false;

function normalize(value) {
    if (value === null || value === undefined) return '';
    if (typeof value === 'boolean') return value ? '1' : '0';
    if (Array.isArray(value)) return value.map(normalize);
    if (typeof value === 'object') {
        return Object.fromEntries(Object.keys(value).sort().map(key => [key, normalize(value[key])]));
    }
    return String(value);
}

function extractSnapshot(value) {
    if (Array.isArray(value) && value.length === 2 && value[1] && typeof value[1] === 'object' && 's' in value[1]) return extractSnapshot(value[0]);
    if (Array.isArray(value)) return value.map(extractSnapshot);
    if (value && typeof value === 'object') return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, extractSnapshot(item)]));
    return value;
}

function readPath(data, path) {
    return path.split('.').reduce((value, key) => value?.[key], data);
}

function writePath(data, path, value) {
    const keys = path.split('.');
    let target = data;
    keys.slice(0, -1).forEach((key, index) => {
        let next = target[key] ?? {};
        if (Array.isArray(next) && !/^\d+$/.test(keys[index + 1])) next = { ...next };
        target = target[key] = next;
    });
    target[keys.at(-1)] = value;
}

function owner(element) {
    return element.closest('[wire\\:id]')?.getAttribute('wire:id');
}

function wireFor(componentId) {
    try { return window.Livewire?.find(componentId); }
    catch { return null; }
}

function model(element) {
    for (let node = element; node; node = node.parentElement) {
        const attribute = node.getAttributeNames().find(name => name === 'wire:model' || name.startsWith('wire:model.'));
        if (attribute) return { path: node.getAttribute(attribute), componentId: owner(node) };
        if (node.hasAttribute('wire:id')) break;
    }
    return null;
}

function accepts(state, binding) {
    return binding && binding.componentId === state.componentId
        && state.models.some(path => binding.path === path || binding.path.startsWith(path + '.'));
}

function fields(root) {
    return [...root.querySelectorAll('input, select, textarea')].filter(field =>
        !['submit', 'button', 'reset'].includes(field.type) && !['_token', '_method'].includes(field.name)
        && field.getAttribute('role') !== 'combobox' && !field.closest('[data-unsaved-ignore]'));
}

function controlValue(field) {
    if (field.type === 'file') return [...field.files].map(file => [file.name, file.size, file.lastModified]);
    if (field.type === 'checkbox' || field.type === 'radio') return field.checked ? field.value : null;
    if (field.multiple) return [...field.selectedOptions].map(option => option.value);
    return field.value;
}

function fingerprint(state, snapshot = null) {
    if (!state.models.length) return JSON.stringify(fields(state.root).filter(field => field.name && !field.disabled).map(field => [field.name, controlValue(field)]));
    const data = {};
    const wire = wireFor(state.componentId);
    state.models.forEach(path => {
        const value = snapshot === null ? wire?.$get(path) : readPath(snapshot, path);
        writePath(data, path, value === undefined ? null : JSON.parse(JSON.stringify(value)));
    });
    if (snapshot === null && !state.skipDomReads) {
        const controls = fields(state.root);
        controls.forEach(field => {
            const binding = model(field);
            if (!accepts(state, binding) || field.type === 'file' || field.disabled) return;
            if (field.type === 'radio' && !field.checked) return;
            let value = controlValue(field);
            if (field.type === 'checkbox') {
                value = Array.isArray(readPath(data, binding.path))
                    ? controls.filter(other => other.type === 'checkbox' && other.checked && model(other)?.path === binding.path).map(other => other.value)
                    : field.checked;
            }
            writePath(data, binding.path, value);
        });
    }
    return JSON.stringify(normalize(data));
}

function isActive(state) {
    if (state.root.dataset.unsavedEnabled === 'false') return false;
    const dialog = state.root.closest('dialog');
    return !dialog || dialog.open;
}

function statusFor(root) {
    return [...root.querySelectorAll('[data-form-save-status]')]
        .find(status => status.closest('[data-unsaved-changes]') === root);
}

function update(state) {
    const active = isActive(state);
    if (active && !state.active) {
        state.baseline = fingerprint(state);
        state.forceDirty = false;
    }
    state.active = active;
    state.dirty = active && (state.forceDirty || state.uploading > 0 || fingerprint(state) !== state.baseline);
    syncUnloadGuard();
    const status = statusFor(state.root);
    if (!status) return;
    const message = state.saving > 0 ? 'Salvando…' : state.dirty ? 'Alterações não salvas' : state.savedAt ? 'Gravação confirmada' : 'Nenhuma alteração pendente nesta tela';
    const text = status.querySelector('[data-form-save-message]');
    const timestamp = status.querySelector('[data-form-save-time]');
    if (text.textContent !== message) text.textContent = message;
    const time = state.savedAt ? 'Última gravação: ' + state.savedAt : '';
    if (timestamp.textContent !== time) timestamp.textContent = time;
    status.dataset.state = state.saving > 0 ? 'saving' : state.dirty ? 'dirty' : 'clean';
    status.hidden = !active;
    state.root.setAttribute('aria-busy', state.saving > 0 ? 'true' : 'false');
}

function watchModels(state) {
    if (!window.Livewire) return;
    const bindings = state.models.length ? state.models.map(path => ({ path, componentId: state.componentId }))
        : [...state.root.querySelectorAll('*')].flatMap(element => {
            const attribute = element.getAttributeNames().find(name => name === 'wire:model' || name.startsWith('wire:model.'));
            return attribute ? [{ path: element.getAttribute(attribute), componentId: owner(element) }] : [];
        });
    bindings.forEach(({ path, componentId }) => {
        const key = componentId + ':' + path;
        if (!componentId || state.watchers.has(key)) return;
        const wire = wireFor(componentId);
        if (wire) state.watchers.set(key, wire.$watch(path, scheduleRefresh));
    });
}

function refresh() {
    refreshQueued = false;
    for (const [root, state] of roots) {
        if (!root.isConnected) {
            state.watchers.forEach(dispose => dispose());
            roots.delete(root);
        }
    }
    document.querySelectorAll('[data-unsaved-changes]').forEach(root => {
        if (!roots.has(root)) {
            const models = JSON.parse(root.dataset.unsavedModels || '[]');
            if (models.length && !wireFor(owner(root))) return;
            const status = statusFor(root);
            const state = {
                root, componentId: owner(root), models, actions: JSON.parse(root.dataset.unsavedActions || '[]'), watchers: new Map(),
                forceDirty: status?.dataset.initialDirty === 'true', savedAt: status?.dataset.savedAt || '',
                saving: 0, uploading: 0, revision: 0, skipDomReads: false, active: true,
            };
            state.active = isActive(state);
            state.baseline = fingerprint(state);
            roots.set(root, state);
        }
        const state = roots.get(root);
        watchModels(state);
        update(state);
    });
    syncUnloadGuard();
}

function scheduleRefresh() {
    if (refreshQueued) return;
    refreshQueued = true;
    requestAnimationFrame(refresh);
}

function changed(event) {
    for (const state of roots.values()) {
        if (!state.root.contains(event.target)) continue;
        if (state.models.length && !accepts(state, model(event.target))) continue;
        if (!state.models.length && !event.target.name) continue;
        state.revision++;
        state.skipDomReads = false;
        allowUnload = false;
        update(state);
    }
    scheduleRefresh();
}

function pending(except = null) {
    refresh();
    return [...roots.values()].some(state => state.root !== except && state.active && (state.dirty || state.saving > 0));
}

function confirmLeaving(except = null) {
    return !pending(except) || window.confirm('Há alterações que ainda não foram salvas. Sair agora pode perder esse preenchimento. Deseja sair mesmo assim?');
}

function guardUnload(event) {
    if (allowUnload || !pending()) return;
    event.preventDefault();
    event.returnValue = '';
}

function syncUnloadGuard() {
    const needed = !allowUnload && [...roots.values()].some(state => state.active && (state.dirty || state.saving > 0));
    if (needed === unloadGuardAttached) return;
    window[needed ? 'addEventListener' : 'removeEventListener']('beforeunload', guardUnload);
    unloadGuardAttached = needed;
}

function registerLivewire() {
    if (livewireRegistered || !window.Livewire) return;
    livewireRegistered = true;
    window.Livewire.interceptMessage(({ message, onSend, onSuccess, onFinish }) => {
        const component = message.component;
        const revisions = new Map();
        const saving = new Set();
        onSend(() => {
            refresh();
            for (const state of roots.values()) {
                if (state.componentId !== component.id || !state.active) continue;
                revisions.set(state, state.revision);
                if ([...message.actions].some(action => state.actions.includes(action.name))) {
                    saving.add(state);
                    state.saving++;
                    update(state);
                }
            }
        });
        onSuccess(({ payload, onSync, onRender }) => {
            const acknowledgements = (payload.effects.dispatches || []).filter(event => event.name === 'form-saved');
            onSync(() => {
                const snapshot = extractSnapshot(payload.snapshot.data);
                for (const state of roots.values()) {
                    const acknowledgement = acknowledgements.find(event => event.params.scope === state.root.dataset.unsavedChanges);
                    if (state.componentId !== component.id || !acknowledgement) continue;
                    state.baseline = fingerprint(state, snapshot);
                    state.forceDirty = false;
                    state.savedAt = acknowledgement.params.savedAt;
                    if (saving.delete(state)) state.saving = Math.max(0, state.saving - 1);
                    state.skipDomReads = revisions.get(state) === state.revision;
                    update(state);
                }
            });
            onRender(() => {
                for (const state of revisions.keys()) state.skipDomReads = false;
                scheduleRefresh();
            });
        });
        onFinish(() => {
            saving.forEach(state => { state.saving = Math.max(0, state.saving - 1); });
            scheduleRefresh();
        });
    });
    scheduleRefresh();
}

document.addEventListener('input', changed, true);
document.addEventListener('change', changed, true);
document.addEventListener('livewire:navigate', event => { if (!confirmLeaving()) event.preventDefault(); });
document.addEventListener('livewire:navigated', () => { allowUnload = false; scheduleRefresh(); });
document.addEventListener('submit', event => {
    if (event.defaultPrevented) return;
    const form = event.target;
    if (form.target && form.target !== '_self') return;
    if (form.getAttributeNames().some(name => name.startsWith('wire:submit'))) return;
    const ownRoot = form.hasAttribute('data-unsaved-changes') ? form : null;
    if (!confirmLeaving(ownRoot)) { event.preventDefault(); event.stopImmediatePropagation(); return; }
    allowUnload = true;
    syncUnloadGuard();
    const state = roots.get(ownRoot);
    if (state) { state.saving++; update(state); }
}, true);
window.addEventListener('pageshow', () => {
    allowUnload = false;
    roots.forEach(state => { state.saving = 0; });
    scheduleRefresh();
});
for (const [event, delta] of [['livewire-upload-start', 1], ['livewire-upload-finish', -1], ['livewire-upload-error', -1], ['livewire-upload-cancel', -1]]) {
    document.addEventListener(event, detail => {
        roots.forEach(state => {
            if (state.root.contains(detail.target) && (!state.models.length || accepts(state, model(detail.target)))) {
                state.uploading = Math.max(0, state.uploading + delta);
                update(state);
            }
        });
        scheduleRefresh();
    });
}
document.addEventListener('livewire:init', registerLivewire, { once: true });
document.addEventListener('livewire:initialized', scheduleRefresh, { once: true });
function initialize() {
    registerLivewire();
    refresh();
    new MutationObserver(scheduleRefresh).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['open', 'data-unsaved-enabled'] });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
else initialize();
