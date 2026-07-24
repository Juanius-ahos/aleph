/**
 * Aleph ERP v6 — Shared JavaScript
 * Form handling, line items, confirmations, utilities
 */

// =====================================================
// Form Submit Protection
// =====================================================

document.addEventListener('DOMContentLoaded', function() {
    // Prevent double submission
    document.querySelectorAll('form').forEach(function(form) {
        form.addEventListener('submit', function() {
            const btn = form.querySelector('[type="submit"]');
            if (btn && !btn.dataset.noDisable) {
                btn.disabled = true;
                btn.dataset.originalText = btn.innerHTML;
                btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation:spin 1s linear infinite"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg> Processing...';
            }
        });
    });

    // Confirm delete actions
    document.querySelectorAll('[data-confirm]').forEach(function(el) {
        el.addEventListener('click', function(e) {
            if (!confirm(el.dataset.confirm || 'Are you sure?')) {
                e.preventDefault();
            }
        });
    });
});

// =====================================================
// Line Item Management (Quotes, Invoices, POs)
// =====================================================

let itemCounter = 0;

function addItem(containerId, prefix) {
    prefix = prefix || 'items';
    itemCounter++;
    const container = document.getElementById(containerId);
    const html = `
        <div class="item-row" id="${prefix}_row_${itemCounter}">
            <input type="text" name="${prefix}[${itemCounter}][description]" placeholder="Description" required>
            <input type="number" name="${prefix}[${itemCounter}][quantity]" value="1" min="0.01" step="0.01" onchange="calcRow('${prefix}_row_${itemCounter}','${prefix}')" onkeyup="calcRow('${prefix}_row_${itemCounter}','${prefix}')">
            <input type="text" name="${prefix}[${itemCounter}][unit]" value="piece" placeholder="Unit">
            <input type="number" name="${prefix}[${itemCounter}][unit_price]" value="0.00" min="0" step="0.01" onchange="calcRow('${prefix}_row_${itemCounter}','${prefix}')" onkeyup="calcRow('${prefix}_row_${itemCounter}','${prefix}')">
            <div style="display:flex;align-items:center;gap:8px">
                <span class="row-total" id="${prefix}_total_${itemCounter}">$0.00</span>
                <button type="button" class="item-remove" onclick="removeItem('${prefix}_row_${itemCounter}','${prefix}')">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', html);
}

function removeItem(rowId, prefix) {
    const row = document.getElementById(rowId);
    if (row) {
        row.remove();
        calcTotal(prefix);
    }
}

function calcRow(rowId, prefix) {
    const row = document.getElementById(rowId);
    if (!row) return;
    const inputs = row.querySelectorAll('input');
    const qty = parseFloat(inputs[1].value) || 0;
    const price = parseFloat(inputs[3].value) || 0;
    const total = qty * price;
    const idx = rowId.split('_').pop();
    document.getElementById(prefix + '_total_' + idx).textContent = '$' + total.toFixed(2);
    calcTotal(prefix);
}

function calcTotal(prefix) {
    let subtotal = 0;
    document.querySelectorAll('[id^="' + prefix + '_total_"]').forEach(function(el) {
        subtotal += parseFloat(el.textContent.replace('$', '')) || 0;
    });

    const taxRate = parseFloat(document.getElementById('tax_rate')?.value || 11);
    const discount = parseFloat(document.getElementById('discount_amount')?.value || 0);
    const tax = (subtotal - discount) * (taxRate / 100);
    const total = subtotal - discount + tax;

    const subtotalEl = document.getElementById('subtotal');
    const taxEl = document.getElementById('tax_amount');
    const discountEl = document.getElementById('discount_display');
    const totalEl = document.getElementById('grand_total');

    if (subtotalEl) subtotalEl.textContent = '$' + subtotal.toFixed(2);
    if (taxEl) taxEl.textContent = '$' + tax.toFixed(2);
    if (discountEl) discountEl.textContent = '-$' + discount.toFixed(2);
    if (totalEl) totalEl.textContent = '$' + total.toFixed(2);
}

// =====================================================
// Search Debounce
// =====================================================

function debounce(func, wait) {
    let timeout;
    return function executedFunction() {
        const context = this;
        const args = arguments;
        clearTimeout(timeout);
        timeout = setTimeout(function() { func.apply(context, args); }, wait);
    };
}

// =====================================================
// Tab Navigation
// =====================================================

function switchTab(tabGroup, tabId) {
    document.querySelectorAll('[data-tab-group="' + tabGroup + '"]').forEach(function(el) {
        el.classList.remove('active');
    });
    document.querySelectorAll('[data-tab-content="' + tabGroup + '"]').forEach(function(el) {
        el.style.display = 'none';
    });
    document.querySelector('[data-tab="' + tabId + '"]').classList.add('active');
    document.getElementById(tabId).style.display = 'block';
}

// =====================================================
// Confirm Actions
// =====================================================

function confirmAction(message, callback) {
    if (confirm(message)) {
        callback();
    }
}

// =====================================================
// Print
// =====================================================

function printPage() {
    window.print();
}

// =====================================================
// Keyboard Shortcuts
// =====================================================

document.addEventListener('keydown', function(e) {
    // Ctrl+K for search focus
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        const searchInput = document.querySelector('.search-bar input, .search-input-group input');
        if (searchInput) searchInput.focus();
    }
    // Escape to close mobile menu
    if (e.key === 'Escape') {
        closeMobileMenu();
    }
});
