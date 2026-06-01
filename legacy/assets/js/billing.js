// Placeholder del módulo de facturación
// Evita 404 y permite futuras funciones específicas de facturación
(function() {
    // Usamos formatCurrency global definida en utils.js

    function showNotification(message, type) {
        const div = document.createElement('div');
        div.className = 'notification ' + (type || 'info');
        div.textContent = message;
        document.body.appendChild(div);
        setTimeout(() => { div.classList.add('show'); }, 10);
        setTimeout(() => { div.classList.remove('show'); div.remove(); }, 3500);
    }

    function recalcTotals() {
        const rows = document.querySelectorAll('#itemsTableBody .item-row');
        let subtotal = 0, taxTotal = 0;
        rows.forEach(row => {
            const qty = parseFloat(row.querySelector('.quantity')?.value || 0);
            const unit = parseCurrency(row.querySelector('.unit-price')?.value || 0);
            const tax = parseFloat(row.querySelector('.item-tax')?.value || 0);
            const lineSubtotal = qty * unit;
            const lineTax = lineSubtotal * (tax / 100);
            subtotal += lineSubtotal;
            taxTotal += lineTax;
            const totalInput = row.querySelector('.item-total');
            if (totalInput) totalInput.value = formatCurrency(lineSubtotal + lineTax);
        });
        const total = subtotal + taxTotal;
        const subtotalDisplay = document.getElementById('subtotalDisplay');
        const taxDisplay = document.getElementById('taxDisplay');
        const totalDisplay = document.getElementById('totalDisplay');
        const itemsCount = document.getElementById('itemsCount');
        const totalStatus = document.getElementById('totalStatus');
        if (subtotalDisplay) subtotalDisplay.textContent = formatCurrency(subtotal);
        if (taxDisplay) taxDisplay.textContent = formatCurrency(taxTotal);
        if (totalDisplay) totalDisplay.textContent = formatCurrency(total);
        if (itemsCount) itemsCount.textContent = rows.length;
        if (totalStatus) totalStatus.textContent = formatCurrency(total);
    }

    // Expose functions globally
    window.calculateTotals = recalcTotals;
    window.addItemRow = addItemRow;

    function clearItems() {
        const body = document.getElementById('itemsTableBody');
        if (!body) return;
        body.querySelectorAll('.item-row').forEach(r => r.remove());
    }

    function addItemRow(index, item) {
        const body = document.getElementById('itemsTableBody');
        if (!body) return;
        const tr = document.createElement('tr');
        tr.className = 'item-row';
        tr.setAttribute('data-index', String(index));
        tr.innerHTML = `
            <td>
                <div class="position-relative">
                    <input type="text" class="form-control form-control-sm item-code" name="items[${index}][code]" placeholder="Buscar por código..." autocomplete="off">
                    <div class="dropdown-menu code-search-dropdown" style="display: none; width: 100%; max-height: 200px; overflow-y: auto;"></div>
                </div>
                <input type="hidden" class="selected-item-id" name="items[${index}][item_id]" value="">
                <input type="hidden" class="selected-item-type" name="items[${index}][selected_type]" value="${item.type || 'manual'}">
            </td>
            <td>
                <div class="position-relative">
                    <input type="text" class="form-control form-control-sm item-description" name="items[${index}][description]" placeholder="Buscar producto/servicio o escribir descripción..." required autocomplete="off" value="${item.description || ''}">
                    <div class="dropdown-menu item-search-dropdown" style="display: none; width: 100%; max-height: 200px; overflow-y: auto;"></div>
                </div>
                <input type="hidden" class="selected-item-id" name="items[${index}][item_id]" value="">
                <input type="hidden" class="selected-item-type" name="items[${index}][selected_type]" value="${item.type || 'manual'}">
            </td>
            <td>
                <input type="number" class="form-control form-control-sm quantity" name="items[${index}][quantity]" step="1" min="1" value="${item.quantity || 1}" placeholder="1" required>
            </td>
            <td>
                <div class="input-group input-group-sm">
                    <span class="input-group-text currency-symbol">${(window.SYSTEM_CONFIG?.currency?.symbol || '$')}</span>
                    <input type="text" class="form-control unit-price money-input" name="items[${index}][unit_price]" value="${parseFloat(item.unit_price || 0).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0})}" placeholder="0" required oninput="formatCurrencyInput(this)" autocomplete="off">
                </div>
            </td>
            <td>
                <div class="input-group input-group-sm">
                    <input type="number" class="form-control item-tax" name="items[${index}][tax]" step="1" min="0" max="100" value="${item.tax ?? 19}" placeholder="19">
                    <span class="input-group-text">%</span>
                </div>
            </td>
            <td>
                <input type="text" class="form-control form-control-sm item-total bg-light text-center fw-bold" readonly value="0" style="color: #28a745;">
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-outline-danger btn-sm delete-item-btn" onclick="(function(btn){ const row=btn.closest('tr'); if(row) row.remove(); (window.calculateTotals ? window.calculateTotals() : recalcTotals()); })(this)" title="Eliminar item">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        `;
        body.insertBefore(tr, body.querySelector('.empty-row'));
    }

    function populateClientFromList(clientId) {
        const list = window.__clients || [];
        const match = list.find(c => String(c.id) === String(clientId));
        const clientSearch = document.getElementById('client_search');
        const clientIdInput = document.getElementById('client_id');
        const infoSection = document.getElementById('client-info-section');
        const nameSpan = document.getElementById('selected-client-name');
        const phoneSpan = document.getElementById('selected-client-phone');
        const idSpan = document.getElementById('selected-client-id-number');
        const typeSpan = document.getElementById('selected-client-type');
        if (clientIdInput) clientIdInput.value = clientId || '';
        if (match) {
            if (clientSearch) clientSearch.value = match.name;
            if (nameSpan) nameSpan.textContent = match.name || '';
            if (phoneSpan) phoneSpan.textContent = match.phone || '';
            if (idSpan) idSpan.textContent = match.document_number || match.id_number || '';
            if (typeSpan) typeSpan.textContent = match.client_type || '';
            if (infoSection) infoSection.classList.remove('d-none');
            const clientStatus = document.getElementById('clientStatus');
            if (clientStatus) clientStatus.textContent = 'Cliente seleccionado';
        } else {
            if (clientSearch) clientSearch.value = '';
        }
    }

    window.setDueDate = function() {
        const invoiceDateInput = document.getElementById('invoice_date');
        const dueDateInput = document.getElementById('due_date');
        if (invoiceDateInput && dueDateInput) {
            const invoiceDateVal = invoiceDateInput.value;
            if (!invoiceDateVal) return;
            
            // Crear fecha evitando problemas de zona horaria (usando UTC o strings directos)
            // Una forma segura es parsear el string YYYY-MM-DD
            const parts = invoiceDateVal.split('-');
            if (parts.length !== 3) return;
            
            const date = new Date(parts[0], parts[1] - 1, parts[2]);
            date.setDate(date.getDate() + 30);
            
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            
            dueDateInput.value = `${year}-${month}-${day}`;
        }
    };

    document.addEventListener('DOMContentLoaded', function() {
        const isBillingNew = !!document.getElementById('invoiceForm');
        if (isBillingNew) {
            /* 
            // Se comenta este listener porque new.php ya tiene su propio listener para addItemBtn
            // y esto estaba causando que se agregaran dos filas al hacer clic.
            document.getElementById('addItemBtn')?.addEventListener('click', function() {
                const rows = document.querySelectorAll('#itemsTableBody .item-row');
                let maxIndex = -1;
                rows.forEach(r => {
                    const idx = parseInt(r.getAttribute('data-index') || '-1');
                    if (idx > maxIndex) maxIndex = idx;
                });
                const idx = maxIndex + 1;
                addItemRow(idx, { type: 'manual', description: '', quantity: 1, unit_price: 0, tax: 19 });
                (window.calculateTotals ? window.calculateTotals() : recalcTotals());
            });
            */
            /*
            document.getElementById('itemsTableBody')?.addEventListener('input', function(e){
                if (e.target && (e.target.classList.contains('quantity') || e.target.classList.contains('unit-price') || e.target.classList.contains('item-tax'))) {
                    (window.calculateTotals ? window.calculateTotals() : recalcTotals());
                }
            });
            */
        }
    });
})();