(function () {
    'use strict';

    const config = window.AltFixesAdmin || {};
    const root = document.getElementById('alt-fixes-results');
    const scanButton = document.getElementById('alt-fixes-scan');
    if (!root || !scanButton) return;

    let state = { page: 1, pages: 1, status: 'missing' };

    const esc = (value) => String(value ?? '').replace(/[&<>\"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '\"': '&quot;' }[char]));
    const selectedIds = () => [...root.querySelectorAll('.item-select:checked')].map((el) => Number(el.closest('.alt-fixes-card').dataset.id));

    const request = async (path, options = {}) => {
        const response = await fetch((config.root || '/wp-json/alt-fixes/v1/') + path.replace(/^\//, ''), {
            credentials: 'same-origin', ...options,
            headers: { 'X-WP-Nonce': config.nonce || '', 'Content-Type': 'application/json', ...(options.headers || {}) }
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Request failed.');
        return data;
    };

    const render = (data) => {
        const items = data.items || [];
        state.page = Number(data.page || 1); state.pages = Number(data.pages || 1);
        if (!items.length) { root.innerHTML = '<div class="alt-fixes-empty">No images match this filter.</div>'; return; }
        root.innerHTML = `
            <div class="alt-fixes-toolbar">
                <div><strong>${Number(data.total || items.length)} image${Number(data.total || items.length) === 1 ? '' : 's'}</strong></div>
                <div class="alt-fixes-toolbar-actions">
                    <select class="alt-fixes-filter" id="alt-fixes-status-filter">
                        ${['missing','suggested','approved','skipped','all'].map(s => `<option value="${s}" ${state.status === s ? 'selected' : ''}>${s[0].toUpperCase()+s.slice(1)}</option>`).join('')}
                    </select>
                    <button class="button" id="alt-fixes-select-all">Select all</button>
                    <button class="button button-primary" id="alt-fixes-bulk-generate">Generate selected</button>
                    <button class="button" id="alt-fixes-bulk-approve">Approve selected</button>
                </div>
            </div>
            <div class="alt-fixes-grid">${items.map(item => `
                <article class="alt-fixes-card ${item.status === 'approved' ? 'is-approved' : ''}" data-id="${Number(item.id)}">
                    <label class="alt-fixes-select"><input type="checkbox" class="item-select"> Select</label>
                    <img src="${esc(item.url || '')}" alt="" loading="lazy">
                    <div class="alt-fixes-card-body">
                        <h3>${esc(item.title || '(untitled)')}</h3>
                        <p class="alt-fixes-meta">Current alt: ${esc(item.alt || 'None')}</p>
                        ${item.purpose ? `<span class="alt-fixes-badge">${esc(item.purpose)}</span>` : ''}
                        ${item.confidence !== null && item.confidence !== undefined ? `<span class="alt-fixes-badge">Confidence: ${Math.round(Number(item.confidence)*100)}%</span>` : ''}
                        <textarea class="alt-fixes-input" placeholder="Generate a suggestion…">${esc(item.suggestion || '')}</textarea>
                        <div class="alt-fixes-actions">
                            <button class="button suggest">Generate</button><button class="button button-primary approve">Approve</button><button class="button skip">Skip</button>
                        </div>
                        <div class="alt-fixes-status" aria-live="polite">${esc(item.review_reason || '')}</div>
                    </div>
                </article>`).join('')}</div>
            <div class="alt-fixes-toolbar">
                <button class="button prev" ${state.page <= 1 ? 'disabled' : ''}>Previous</button>
                <span>Page ${state.page} of ${state.pages}</span>
                <button class="button next" ${state.page >= state.pages ? 'disabled' : ''}>Next</button>
            </div>`;

        document.getElementById('alt-fixes-select-all').onclick = () => root.querySelectorAll('.item-select').forEach(c => { c.checked = true; });
        document.getElementById('alt-fixes-status-filter').onchange = (e) => { state.status = e.target.value; state.page = 1; load(); };
        document.getElementById('alt-fixes-bulk-generate').onclick = bulkGenerate;
        document.getElementById('alt-fixes-bulk-approve').onclick = bulkApprove;
        root.querySelector('.prev').onclick = () => { state.page--; load(); };
        root.querySelector('.next').onclick = () => { state.page++; load(); };
        root.querySelectorAll('.alt-fixes-card').forEach(bindCard);
    };

    const bindCard = (card) => {
        const id = card.dataset.id, input = card.querySelector('.alt-fixes-input'), status = card.querySelector('.alt-fixes-status');
        const buttons = card.querySelectorAll('button');
        card.querySelector('.suggest').onclick = async () => {
            buttons.forEach(b => b.disabled = true); status.textContent = 'Analyzing image and page context…';
            try { const data = await request(`suggest/${id}`, {method:'POST'}); input.value = data.suggestion || data.alt || ''; status.textContent = data.review_reason ? `Review: ${data.review_reason}` : 'Suggestion ready for review.'; }
            catch (e) { status.textContent = e.message; } finally { buttons.forEach(b => b.disabled = false); }
        };
        card.querySelector('.approve').onclick = async () => {
            const alt = input.value.trim(); if (!alt) { status.textContent = 'Add or generate alt text before approving.'; return; }
            buttons.forEach(b => b.disabled = true); status.textContent = 'Saving…';
            try { await request(`approve/${id}`, {method:'POST', body:JSON.stringify({alt})}); card.classList.add('is-approved'); status.textContent = 'Approved and saved to the Media Library.'; }
            catch (e) { status.textContent = e.message; buttons.forEach(b => b.disabled = false); }
        };
        card.querySelector('.skip').onclick = async () => {
            buttons.forEach(b => b.disabled = true); status.textContent = 'Skipping…';
            try { await request(`skip/${id}`, {method:'POST'}); card.remove(); }
            catch (e) { status.textContent = e.message; buttons.forEach(b => b.disabled = false); }
        };
    };

    async function bulkGenerate() {
        const ids = selectedIds(); if (!ids.length) return alert('Select at least one image.');
        setToolbarBusy(true); root.querySelector('.alt-fixes-status')?.remove();
        try { await request('bulk-suggest', {method:'POST', body:JSON.stringify({ids})}); await load(); }
        catch (e) { alert(e.message); } finally { setToolbarBusy(false); }
    }

    async function bulkApprove() {
        const cards = [...root.querySelectorAll('.alt-fixes-card')].filter(c => c.querySelector('.item-select')?.checked);
        const items = cards.map(c => ({id:Number(c.dataset.id), alt:c.querySelector('.alt-fixes-input').value.trim()})).filter(x => x.alt);
        if (!items.length) return alert('Select images with alt text ready for approval.');
        setToolbarBusy(true);
        try { await request('bulk-approve', {method:'POST', body:JSON.stringify({items})}); await load(); }
        catch (e) { alert(e.message); } finally { setToolbarBusy(false); }
    }

    function setToolbarBusy(busy) { root.querySelectorAll('.alt-fixes-toolbar button,.alt-fixes-toolbar select').forEach(el => el.disabled = busy); }

    async function load() {
        root.innerHTML = '<div class="alt-fixes-loading">Loading image library…</div>';
        try { render(await request(`scan?per_page=24&page=${state.page}&status=${encodeURIComponent(state.status)}`)); }
        catch (e) { root.innerHTML = `<div class="notice notice-error"><p>${esc(e.message)}</p></div>`; }
    }

    scanButton.onclick = () => { state.page = 1; state.status = 'missing'; load(); };
})();
