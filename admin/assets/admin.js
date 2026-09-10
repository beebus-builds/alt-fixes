(function () {
    'use strict';

    const config = window.AltFixesAdmin || {};
    const root = document.getElementById('alt-fixes-results');
    const scanButton = document.getElementById('alt-fixes-scan');
    if (!root || !scanButton) return;

    const esc = (value) => String(value ?? '').replace(/[&<>\"]/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '\"': '&quot;'
    }[char]));

    const request = async (path, options = {}) => {
        const response = await fetch((config.root || '/wp-json/alt-fixes/v1/') + path.replace(/^\//, ''), {
            credentials: 'same-origin',
            ...options,
            headers: {
                'X-WP-Nonce': config.nonce || '',
                'Content-Type': 'application/json',
                ...(options.headers || {})
            }
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Request failed.');
        return data;
    };

    const render = (items) => {
        if (!items.length) {
            root.innerHTML = '<div class="alt-fixes-empty">No images need review.</div>';
            return;
        }

        root.innerHTML = `
            <div class="alt-fixes-toolbar">
                <strong>${items.length} image${items.length === 1 ? '' : 's'} need review</strong>
                <button class="button" id="alt-fixes-select-all">Select all</button>
            </div>
            <div class="alt-fixes-grid">
                ${items.map((item) => `
                    <article class="alt-fixes-card" data-id="${Number(item.id)}">
                        <label class="alt-fixes-select"><input type="checkbox" class="item-select"> Select</label>
                        <img src="${esc(item.url || '')}" alt="" loading="lazy">
                        <div class="alt-fixes-card-body">
                            <h3>${esc(item.title || '(untitled)')}</h3>
                            <p class="alt-fixes-meta">Current alt: ${esc(item.alt || 'None')}</p>
                            <textarea class="alt-fixes-input" placeholder="Generate a suggestion…">${esc(item.suggestion || '')}</textarea>
                            <div class="alt-fixes-actions">
                                <button class="button suggest">Generate</button>
                                <button class="button button-primary approve">Approve</button>
                                <button class="button skip">Skip</button>
                            </div>
                            <div class="alt-fixes-status" aria-live="polite"></div>
                        </div>
                    </article>
                `).join('')}
            </div>
        `;

        document.getElementById('alt-fixes-select-all').onclick = () => {
            root.querySelectorAll('.item-select').forEach((checkbox) => { checkbox.checked = true; });
        };

        root.querySelectorAll('.alt-fixes-card').forEach(bindCard);
    };

    const bindCard = (card) => {
        const id = card.dataset.id;
        const input = card.querySelector('.alt-fixes-input');
        const status = card.querySelector('.alt-fixes-status');
        const buttons = card.querySelectorAll('button');

        card.querySelector('.suggest').onclick = async () => {
            buttons.forEach((button) => { button.disabled = true; });
            status.textContent = 'Analyzing image and page context…';
            try {
                const data = await request(`suggest/${id}`, { method: 'POST' });
                input.value = data.suggestion || data.alt || '';
                status.textContent = data.review_reason ? `Review: ${data.review_reason}` : 'Suggestion ready for review.';
            } catch (error) {
                status.textContent = error.message;
            } finally {
                buttons.forEach((button) => { button.disabled = false; });
            }
        };

        card.querySelector('.approve').onclick = async () => {
            const alt = input.value.trim();
            if (!alt) {
                status.textContent = 'Add or generate alt text before approving.';
                return;
            }
            buttons.forEach((button) => { button.disabled = true; });
            status.textContent = 'Saving…';
            try {
                await request(`approve/${id}`, {
                    method: 'POST',
                    body: JSON.stringify({ alt })
                });
                card.classList.add('is-approved');
                status.textContent = 'Approved and saved to the Media Library.';
            } catch (error) {
                status.textContent = error.message;
                buttons.forEach((button) => { button.disabled = false; });
            }
        };

        card.querySelector('.skip').onclick = async () => {
            buttons.forEach((button) => { button.disabled = true; });
            status.textContent = 'Skipping…';
            try {
                await request(`skip/${id}`, { method: 'POST' });
                card.remove();
                if (!root.querySelector('.alt-fixes-card')) {
                    root.innerHTML = '<div class="alt-fixes-empty">No images need review.</div>';
                }
            } catch (error) {
                status.textContent = error.message;
                buttons.forEach((button) => { button.disabled = false; });
            }
        };
    };

    scanButton.onclick = async () => {
        scanButton.disabled = true;
        root.innerHTML = '<div class="alt-fixes-loading">Scanning image library…</div>';
        try {
            const data = await request('scan?per_page=100&status=missing');
            render(data.items || []);
        } catch (error) {
            root.innerHTML = `<div class="notice notice-error"><p>${esc(error.message)}</p></div>`;
        } finally {
            scanButton.disabled = false;
        }
    };
})();
