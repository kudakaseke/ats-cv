export function isPageNavigation(event, link, currentUrl) {
    if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey
        || link.hasAttribute('download') || (link.target && link.target !== '_self')) return false;
    const href = link.getAttribute('href');
    if (!href || href.startsWith('#')) return false;
    try {
        const destination = new URL(href, currentUrl);
        const current = new URL(currentUrl);
        return destination.origin === current.origin && ['http:', 'https:'].includes(destination.protocol)
            && !(destination.hash && destination.pathname === current.pathname && destination.search === current.search);
    } catch { return false; }
}

if (typeof document !== 'undefined') {
    const labels = new Map();
    function clearPending() {
        delete document.documentElement.dataset.navigationPending;
        document.documentElement.removeAttribute('aria-busy');
        for (const [node, value] of labels) { node.textContent = value; node.removeAttribute('aria-disabled'); }
        labels.clear();
        document.getElementById('site-navigation-status')?.remove();
    }
    function pending(message) {
        let progress = document.getElementById('site-navigation-progress');
        if (!progress) {
            progress = document.createElement('div');
            progress.id = 'site-navigation-progress';
            progress.className = 'site-navigation-progress';
            progress.setAttribute('aria-hidden', 'true');
            document.body.append(progress);
        }
        let status = document.getElementById('site-navigation-status');
        if (!status) {
            status = document.createElement('span');
            status.id = 'site-navigation-status'; status.className = 'sr-only';
            status.setAttribute('role', 'status'); document.body.append(status);
        }
        status.textContent = message;
        document.documentElement.dataset.navigationPending = 'true';
        document.documentElement.setAttribute('aria-busy', 'true');
    }
    document.addEventListener('click', event => {
        const link = event.target.closest?.('a[href]');
        if (!isPageNavigation(event, link, location.href)) return;
        const label = link.querySelector('[data-navigation-label]');
        if (link.hasAttribute('data-google-auth') && label) {
            if (!labels.has(label)) labels.set(label, label.textContent);
            label.textContent = 'Opening Google…';
        }
        pending(link.hasAttribute('data-google-auth') ? 'Opening Google sign-in.' : 'Loading page.');
    });
    document.addEventListener('submit', event => {
        const form = event.target;
        if (event.defaultPrevented || !form.closest('body.auth-body')) return;
        const button = event.submitter;
        if (button) {
            labels.set(button, button.textContent);
            button.textContent = new URL(form.action).pathname === '/register' ? 'Creating account…' : 'Please wait…';
            button.setAttribute('aria-disabled', 'true');
        }
        pending('Submitting your details.');
    });
    window.addEventListener('pageshow', clearPending);
    document.addEventListener('livewire:navigated', clearPending);
}
