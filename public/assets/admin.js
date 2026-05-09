// Live search — progressive enhancement over the <form method="get">.
// If JS is disabled, the form still submits normally and the page reloads.
// With JS, typing debounces into a fetch('/admin.php?q=X&partial=1') that
// swaps the results region in place, preserving input focus.
//
// Design notes:
//   - Debounce is short (180ms) because FTS5 over a small table is fast
//     and staff expect results to keep up with typing.
//   - AbortController cancels in-flight requests on new keystrokes so
//     late responses never overwrite fresher ones.
//   - History is updated with replaceState so Back doesn't fill up with
//     a history entry per keystroke. The initial page load still wins.
//   - aria-live="polite" on #search-status lets screen readers hear the
//     result count without being interrupted mid-key.

(function () {
    const form = document.getElementById('search-form');
    if (!form) return;

    const input = form.querySelector('input[name="q"]');
    const clear = form.querySelector('.search-clear');
    const results = document.getElementById('search-results');
    const status = document.getElementById('search-status');
    if (!input || !results) return;

    let controller = null;
    let debounceTimer = null;

    const DEBOUNCE_MS = 180;

    function updateClear() {
        if (!clear) return;
        if (input.value === '') {
            clear.setAttribute('hidden', '');
        } else {
            clear.removeAttribute('hidden');
        }
    }

    function announceResultCount() {
        if (!status) return;
        const countEl = results.querySelector('.result-count');
        const empty = results.querySelector('.empty');
        if (countEl) {
            status.textContent = countEl.textContent;
        } else if (empty) {
            status.textContent = empty.textContent;
        } else {
            status.textContent = '';
        }
    }

    async function runSearch() {
        const q = input.value;
        const url = '/admin.php?partial=1&q=' + encodeURIComponent(q);

        if (controller) controller.abort();
        controller = new AbortController();

        try {
            const res = await fetch(url, {
                signal: controller.signal,
                headers: { 'Accept': 'text/html' },
            });
            if (!res.ok) return;
            const html = await res.text();
            results.innerHTML = html;
            announceResultCount();

            // Keep the URL in sync so a refresh / share-link preserves state.
            const params = new URLSearchParams(window.location.search);
            if (q === '') {
                params.delete('q');
            } else {
                params.set('q', q);
            }
            params.delete('partial');
            const newQs = params.toString();
            const newUrl = window.location.pathname + (newQs ? '?' + newQs : '');
            window.history.replaceState(null, '', newUrl);
        } catch (e) {
            if (e.name !== 'AbortError') {
                // Fall back silently; next keystroke retries.
                console.error('search failed', e);
            }
        }
    }

    input.addEventListener('input', () => {
        updateClear();
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(runSearch, DEBOUNCE_MS);
    });

    form.addEventListener('submit', (e) => {
        // With JS enabled, handle in-page instead of reloading.
        e.preventDefault();
        clearTimeout(debounceTimer);
        runSearch();
    });

    if (clear) {
        clear.addEventListener('click', (e) => {
            e.preventDefault();
            input.value = '';
            updateClear();
            runSearch();
            input.focus();
        });
    }

    // "/" keyboard shortcut to focus search (common pattern, e.g. GitHub).
    // Skip when the user is already typing into a field.
    document.addEventListener('keydown', (e) => {
        if (e.key !== '/') return;
        const target = e.target;
        if (target instanceof HTMLElement) {
            const tag = target.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || target.isContentEditable) return;
        }
        e.preventDefault();
        input.focus();
        input.select();
    });

    updateClear();
})();
