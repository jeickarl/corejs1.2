/**
 * Logic for Invoice Form (New/Edit)
 * Expects global variables:
 * - window.servicesData
 * - window.productsData
 * - window.currencyConfig
 * - window.__clients
 * - window.SYSTEM_CONFIG
 */

// Estado de validación global
let validationState = {
    client: false,
    items: false,
    formValid: false
};

// Función para formatear campos de precio en tiempo real
function formatPriceInput(input) {
    // Usamos formatCurrencyInput global si existe, o implementamos fallback
    if (typeof formatCurrencyInput === 'function') {
        formatCurrencyInput(input);
    }
}

// Función para inicializar el formateo en campos de precio existentes
function initializePriceFormatting() {
    document.querySelectorAll('.unit-price').forEach(function (input) {
        if (input.value && input.value !== '0') {
            formatPriceInput(input);
        }
    });

    // Agregar formateo al campo de monto de pago
    const paymentAmountField = document.getElementById('payment_amount');
    if (paymentAmountField) {
        paymentAmountField.addEventListener('input', function () {
            formatPriceInput(this);
        });
    }
}

// Función para validar selección de cliente
function validateClientSelection() {
    const selectedClientId = document.getElementById('client_id');

    const isValid = selectedClientId && selectedClientId.value;
    validationState.client = isValid;

    updateFormValidation();
    return isValid;
}

// Funcionalidad de búsqueda de clientes
const clientSearchInput = document.getElementById('client_search');
const clientIdInput = document.getElementById('client_id');
const clientDropdown = document.getElementById('client_dropdown');
let searchTimeout;

// Función para buscar clientes
function searchClients(searchTerm) {
    if (searchTerm.length < 2) {
        if (clientDropdown) clientDropdown.style.display = 'none';
        return;
    }

    const formData = new FormData();
    formData.append('search', searchTerm);

    fetch('/core/clients/search_ajax.php', {
        method: 'POST',
        body: formData
    })
        .then(response => {
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            return response.json();
        })
        .then(data => {
            if (data.clients && data.clients.length > 0) {
                displaySearchResults(data.clients);
            } else {
                if (clientDropdown) {
                    clientDropdown.innerHTML = '<div class="dropdown-item-text text-muted text-center" style="padding: 20px; font-style: italic;"><i class="fas fa-search me-2"></i>No se encontraron clientes que coincidan con la búsqueda</div>';
                    clientDropdown.style.display = 'block';
                }
            }
        })
        .catch(error => {
            console.error('Error en búsqueda:', error);
            if (clientDropdown) {
                let msg = 'Error de conexión';
                if (error.name === 'SyntaxError') msg = 'Error en la respuesta del servidor';
                clientDropdown.innerHTML = `<div class="dropdown-item-text text-danger text-center" style="padding: 20px;"><i class="fas fa-exclamation-triangle me-2"></i>${msg}</div>`;
                clientDropdown.style.display = 'block';
            }
        });
}

// Función para mostrar resultados de búsqueda
function displaySearchResults(clients) {
    if (!clientDropdown) return;
    clientDropdown.innerHTML = '';

    if (clients.length === 0) {
        const noResults = document.createElement('div');
        noResults.className = 'dropdown-item-text text-muted text-center';
        noResults.style.cssText = 'padding: 20px; font-style: italic;';
        noResults.innerHTML = '<i class="fas fa-search me-2"></i>No se encontraron clientes que coincidan con la búsqueda';
        clientDropdown.appendChild(noResults);
    } else {
        clients.forEach(client => {
            const item = document.createElement('a');
            item.className = 'dropdown-item';
            item.href = '#';
            item.style.cssText = 'padding: 12px 16px; border-bottom: 1px solid #f8f9fa; cursor: pointer; transition: background-color 0.15s ease-in-out;';
            item.innerHTML = `
                <div>
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <strong class="text-dark">${client.id_number || 'Sin identificación'}</strong>
                            <br>
                            <small class="text-muted">
                                <i class="fas fa-user me-1"></i>${client.name} &bull; <i class="fas fa-phone me-1"></i>
                                <br>
                                ${client.phone || ''}
                            </small>
                        </div>
                        <small class="badge bg-secondary text-uppercase">${client.client_type === 'company' ? 'Empresa' : 'Persona Natural'}</small>
                    </div>
                </div>
            `;

            item.addEventListener('mouseenter', function () { this.style.backgroundColor = '#f8f9fa'; });
            item.addEventListener('mouseleave', function () { this.style.backgroundColor = 'white'; });
            item.addEventListener('click', function (e) {
                e.preventDefault();
                selectClient(client);
            });

            clientDropdown.appendChild(item);
        });
    }

    clientDropdown.style.display = 'block';
}

// Función para seleccionar un cliente
function selectClient(client) {
    if (clientSearchInput) clientSearchInput.value = client.name;
    if (clientIdInput) clientIdInput.value = client.id;
    if (clientDropdown) clientDropdown.style.display = 'none';

    if (clientSearchInput) clientSearchInput.classList.remove('is-invalid');
    const errorDiv = document.getElementById('client-error');
    if (errorDiv) errorDiv.remove();

    showClientInfo(client);
    validateClientSelection();

    // Mantener buscador visible (comentado para evitar ocultamiento)
    // const wrapper = document.getElementById('client-search-wrapper');
    // if (wrapper) wrapper.style.display = 'none';
}

// Función para mostrar información del cliente
function showClientInfo(client) {
    const clientInfoSection = document.getElementById('client-info-section');
    const selectedClientName = document.getElementById('selected-client-name');
    const selectedClientPhone = document.getElementById('selected-client-phone');
    const selectedClientIdNumber = document.getElementById('selected-client-id-number');
    const selectedClientType = document.getElementById('selected-client-type');

    if (clientInfoSection && selectedClientName && selectedClientPhone && selectedClientIdNumber && selectedClientType) {
        selectedClientName.textContent = client.name || 'No especificado';
        selectedClientPhone.textContent = client.phone || 'No especificado';
        selectedClientIdNumber.textContent = client.id_number || 'No especificado';
        selectedClientType.textContent = client.client_type === 'company' ? 'Empresa' : 'Persona Natural';

        clientInfoSection.classList.remove('d-none');
        clientInfoSection.style.display = 'block';
    }
}

// Función para ocultar información del cliente
function hideClientInfo() {
    const clientInfoSection = document.getElementById('client-info-section');
    if (clientInfoSection) {
        clientInfoSection.classList.add('d-none');
        clientInfoSection.style.display = 'none';
    }
    const wrapper = document.getElementById('client-search-wrapper');
    if (wrapper) wrapper.style.display = 'block';
}

// Función para validar items
function validateItems() {
    const itemRows = document.querySelectorAll('.item-row');
    let validItems = 0;

    itemRows.forEach(row => {
        const description = row.querySelector('.item-description');
        const quantity = row.querySelector('.quantity');
        const unitPrice = row.querySelector('.unit-price');

        if (description && description.value.trim() && quantity && quantity.value && unitPrice && unitPrice.value) {
            validItems++;
        }
    });

    validationState.items = validItems >= 1;
    updateFormValidation();
    return validationState.items;
}

// Función para actualizar estado general del formulario
function updateFormValidation() {
    validationState.formValid = validationState.client && validationState.items;
    updateStatusCounters();
    return validationState.formValid;
}

// Función para actualizar contadores de estado
function updateStatusCounters() {
    const itemsCount = document.getElementById('itemsCount');
    const clientStatus = document.getElementById('clientStatus');
    const totalStatus = document.getElementById('totalStatus');
    const totalStatusTotal = document.getElementById('totalStatusTotal');

    if (itemsCount) {
        const validItems = document.querySelectorAll('.item-row').length;
        itemsCount.textContent = validItems;
    }

    if (clientStatus) {
        const selectedClientName = document.getElementById('selected-client-name');
        clientStatus.textContent = selectedClientName && selectedClientName.textContent ?
            selectedClientName.textContent : 'Sin cliente';
    }

    if (totalStatus || totalStatusTotal) {
        let invoiceTotal = 0;
        document.querySelectorAll('tr[data-index] .item-total').forEach(inp => {
            invoiceTotal += parseFloat(inp.value) || 0;
        });
        let paidTotal = 0;
        if (typeof getPaidTotalFromDOM === 'function') {
            paidTotal = getPaidTotalFromDOM();
        } else {
            const paymentAmountField = document.getElementById('payment_amount');
            if (paymentAmountField) {
                paidTotal = parseCurrency(paymentAmountField.value);
            }
        }
        const pending = Math.max(0, invoiceTotal - paidTotal);
        if (totalStatus) {
            totalStatus.textContent = formatCurrency(pending);
            totalStatus.classList.remove('text-danger', 'text-success', 'text-warning', 'text-dark', 'text-muted');
            totalStatus.classList.add(pending > 0 ? 'text-danger' : 'text-success');
        }
        if (totalStatusTotal) totalStatusTotal.textContent = formatCurrency(invoiceTotal);
    }
}

// Helpers de configuración de impuestos
function getTaxConfig() {
    const cfg = (window.SYSTEM_CONFIG && window.SYSTEM_CONFIG.tax) ? window.SYSTEM_CONFIG.tax : null;
    return {
        enabled: cfg ? !!cfg.enabled : true,
        rate: cfg ? parseFloat(cfg.rate) : 19,
        name: cfg ? (cfg.name || 'IVA') : 'IVA'
    };
}

// Función para calcular total de un item
function calculateItemTotal(row) {
    // calculateTotals ya se encarga de calcular cada fila y los totales generales
    calculateTotals();
}

// Función para agregar nuevo item
function addNewItem() {
    const tbody = document.getElementById('itemsTableBody');
    if (!tbody) return;

    const itemIndex = document.querySelectorAll('.item-row').length;
    const newItemRow = document.createElement('tr');
    newItemRow.className = 'item-row';
    newItemRow.setAttribute('data-index', itemIndex);

    const symbol = window.SYSTEM_CONFIG?.currency?.symbol || '$';

    newItemRow.innerHTML = `
        <td class="text-center text-muted small pt-2">${itemIndex + 1}</td>
        <td>
            <div class="search-input-container">
                <input type="text" class="form-control item-code" 
                       name="items[${itemIndex}][code]" placeholder="Buscar..." autocomplete="off">
                <i class="fas fa-search"></i>
                <div class="dropdown-menu code-search-dropdown shadow-lg border-0 rounded-4" style="display: none; width: 250px;"></div>
            </div>
            <input type="hidden" class="selected-item-id" name="items[${itemIndex}][item_id]" value="">
            <input type="hidden" class="selected-item-type" name="items[${itemIndex}][selected_type]" value="manual">
        </td>
        <td>
            <div class="position-relative">
                <input type="text" class="form-control item-description" 
                       name="items[${itemIndex}][description]" placeholder="Descripción del producto..." required autocomplete="off">
                <div class="dropdown-menu item-search-dropdown shadow-lg border-0 rounded-4" style="display: none; width: 100%;"></div>
            </div>
        </td>
        <td>
            <input type="number" class="form-control text-center quantity" 
                   name="items[${itemIndex}][quantity]" min="1" value="1" step="1" required>
        </td>
        <td>
            <div class="input-group">
                <span class="input-group-text border-0 bg-transparent text-muted small pe-1">${symbol}</span>
                <input type="text" class="form-control border-start-0 unit-price money-input" 
                       name="items[${itemIndex}][unit_price]" value="0" required oninput="formatCurrencyInput(this)">
            </div>
        </td>
        <td>
            <select class="form-select item-tax" name="items[${itemIndex}][tax]">
                <option value="0">0%</option>
                <option value="19" selected>IVA 19%</option>
                <option value="5">IVA 5%</option>
                <option value="8">IVA 8%</option>
            </select>
        </td>
        <td class="text-end">
            <span class="item-total-display">${symbol}0.00</span>
            <input type="hidden" class="item-total" value="0">
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-link text-danger p-0 delete-item-btn" onclick="removeItem(this)">
                <i class="fas fa-trash-alt"></i>
            </button>
        </td>
    `;

    tbody.appendChild(newItemRow);
    
    const priceInput = newItemRow.querySelector('.unit-price');
    if (priceInput) formatPriceInput(priceInput);

    const taxCfg = getTaxConfig();
    const taxSelect = newItemRow.querySelector('.item-tax');
    if (taxSelect) {
        if (!taxCfg.enabled) {
            taxSelect.value = '0';
            taxSelect.disabled = true;
        } else {
            const desired = String(Math.round(taxCfg.rate));
            const opt = Array.from(taxSelect.options).find(o => o.value === desired);
            taxSelect.value = opt ? desired : taxSelect.value;
            taxSelect.disabled = false;
        }
    }

    setTimeout(() => {
        const desc = newItemRow.querySelector('.item-description');
        if (desc) desc.focus();
    }, 100);

    calculateTotals();
}

// Función para eliminar item
function removeItem(button) {
    const itemRow = button.closest('.item-row');
    if (itemRow) {
        itemRow.remove();
        reindexItems();
        calculateTotals();
        validateItems();
    }
}

// Función para asegurar que siempre haya filas vacías disponibles
function ensureEmptyRows() {
    // En el nuevo diseño no necesitamos filas vacías visuales decorativas
    // pero mantenemos la función para compatibilidad si se llama
    const tbody = document.getElementById('itemsTableBody');
    if (!tbody) return;
    if (tbody.querySelectorAll('.item-row').length === 0) {
        addNewItem();
    }
}

// Función para reindexar items después de eliminar
function reindexItems() {
    const itemRows = document.querySelectorAll('.item-row');
    itemRows.forEach((row, index) => {
        row.setAttribute('data-index', index);
        row.cells[0].textContent = index + 1; // Actualizar número de fila
        const inputs = row.querySelectorAll('input, select');
        inputs.forEach(input => {
            if (input.name) {
                input.name = input.name.replace(/\[\d+\]/, `[${index}]`);
            }
        });
    });
}

// Función para actualizar visibilidad de botones de eliminar
function updateDeleteButtonsVisibility() {
    // Siempre visibles en este diseño
}

// Función para calcular totales generales
function calculateTotals() {
    let subtotal = 0;
    let totalTax = 0;

    const isTaxEnabled = getTaxConfig().enabled;

    document.querySelectorAll('tr[data-index]').forEach(function (row) {
        const quantityInput = row.querySelector('.quantity');
        const unitPriceInput = row.querySelector('.unit-price');
        const taxInput = row.querySelector('.item-tax');

        const quantity = parseFloat(quantityInput ? quantityInput.value : 0) || 0;
        const unitPrice = parseCurrency(unitPriceInput ? unitPriceInput.value : 0);
        const tax = parseFloat(taxInput ? taxInput.value : 0) || 0;

        const lineSubtotal = quantity * unitPrice;
        const lineTax = isTaxEnabled ? (lineSubtotal * tax) / 100 : 0;
        const lineTotal = lineSubtotal + lineTax;

        subtotal += lineSubtotal;
        totalTax += lineTax;

        const itemTotalInput = row.querySelector('.item-total');
        const itemTotalDisplay = row.querySelector('.item-total-display');

        if (itemTotalInput) itemTotalInput.value = lineTotal;
        if (itemTotalDisplay) itemTotalDisplay.textContent = formatCurrency(lineTotal);
    });

    const total = subtotal + totalTax;

    const subDisplay = document.getElementById('subtotalDisplay');
    const taxDisplay = document.getElementById('taxDisplay');
    const totalDisplay = document.getElementById('totalDisplay');

    if (subDisplay) subDisplay.textContent = formatCurrency(subtotal);
    if (taxDisplay) taxDisplay.textContent = formatCurrency(totalTax);
    if (totalDisplay) totalDisplay.textContent = formatCurrency(total);

    updateStatusCounters();

    // Disparar evento personalizado para que otros scripts (como pagos) se enteren
    document.dispatchEvent(new CustomEvent('invoiceTotalUpdated', {
        detail: {
            total: total,
            subtotal: subtotal,
            tax: totalTax
        }
    }));
}

// Variables globales para búsqueda de items
let itemSearchTimeouts = new Map();

// Función para buscar items (productos/servicios)
function searchItems(input, searchTerm) {
    const row = input.closest('tr[data-index]');
    const typeSelect = row.querySelector('.item-type'); // Note: Template doesn't have explicit item-type select visible always, need to check structure
    // In template, we don't have explicit type select in the row, we have hidden input. 
    // We search all by default or infer type. The original code assumed a select.
    // Let's assume 'all' type for now as the template doesn't show a type selector per row visibly in the screenshot/code.

    const dropdown = row.querySelector('.item-search-dropdown');

    if (searchTerm.length < 2) {
        if (dropdown) dropdown.style.display = 'none';
        return;
    }

    const formData = new FormData();
    formData.append('search', searchTerm);
    formData.append('type', 'all');

    fetch('search_items_ajax.php', {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.items && data.items.length > 0) {
                displayItemSearchResults(dropdown, data.items, input);
            } else {
                dropdown.innerHTML = '<div class="dropdown-item-text text-muted text-center p-3"><i class="fas fa-search me-2"></i>No se encontraron resultados</div>';
                dropdown.style.display = 'block';
            }
        })
        .catch(error => {
            console.error('Error en búsqueda de items:', error);
            dropdown.innerHTML = '<div class="dropdown-item-text text-danger text-center p-3"><i class="fas fa-exclamation-triangle me-2"></i>Error de conexión</div>';
            dropdown.style.display = 'block';
        });
}

// Función para buscar items por código
function searchItemsByCode(input, searchTerm) {
    const row = input.closest('tr[data-index]');
    const dropdown = row.querySelector('.code-search-dropdown');

    if (searchTerm.length < 2) {
        if (dropdown) dropdown.style.display = 'none';
        return;
    }

    const formData = new FormData();
    formData.append('search', searchTerm);
    formData.append('type', 'all');
    formData.append('search_by', 'code');

    fetch('search_items_ajax.php', {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.items && data.items.length > 0) {
                displayCodeSearchResults(dropdown, data.items, input);
            } else {
                dropdown.innerHTML = '<div class="dropdown-item-text text-muted text-center p-3"><i class="fas fa-search me-2"></i>No se encontraron productos/servicios con ese código</div>';
                dropdown.style.display = 'block';
            }
        })
        .catch(error => {
            console.error('Error en búsqueda por código:', error);
            dropdown.innerHTML = '<div class="dropdown-item-text text-danger text-center p-3"><i class="fas fa-exclamation-triangle me-2"></i>Error de conexión</div>';
            dropdown.style.display = 'block';
        });
}

// Función para mostrar resultados de búsqueda por código
function displayCodeSearchResults(dropdown, items, input) {
    dropdown.innerHTML = '';

    items.forEach(item => {
        const itemElement = document.createElement('a');
        itemElement.className = 'dropdown-item';
        itemElement.href = '#';
        itemElement.style.cssText = 'padding: 8px 12px; border-bottom: 1px solid #f8f9fa; cursor: pointer;';

        let stockInfo = '';
        if (item.type === 'product' && item.stock !== null) {
            const stockClass = item.stock > 10 ? 'text-success' : item.stock > 0 ? 'text-warning' : 'text-danger';
            stockInfo = `<small class="${stockClass}">Stock: ${item.stock}</small>`;
        }

        itemElement.innerHTML = `
            <div>
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <strong class="text-primary fw-bold"><i class="fas fa-barcode me-1"></i>${item.code || 'Sin código'}</strong>
                        <br>
                        <span class="text-dark">${item.display_name}</span>
                        <br>
                        <small class="text-muted">
                            ${formatCurrency(item.price)}
                            ${stockInfo ? ' • ' + stockInfo : ''}
                        </small>
                    </div>
                    <small class="badge ${item.type === 'product' ? 'bg-primary' : 'bg-success'}">
                        ${item.type === 'product' ? 'Producto' : 'Servicio'}
                    </small>
                </div>
            </div>
        `;

        itemElement.addEventListener('mouseenter', function () { this.style.backgroundColor = '#f8f9fa'; });
        itemElement.addEventListener('mouseleave', function () { this.style.backgroundColor = 'transparent'; });
        itemElement.addEventListener('click', function (e) {
            e.preventDefault();
            selectItemFromCode(item, input);
        });

        dropdown.appendChild(itemElement);
    });

    dropdown.style.display = 'block';
}

// Función para seleccionar item desde búsqueda por código
function selectItemFromCode(item, input) {
    const row = input.closest('tr[data-index]');
    const dropdown = row.querySelector('.code-search-dropdown');
    const itemIdInput = row.querySelector('.selected-item-id');
    const itemTypeInput = row.querySelector('.selected-item-type');
    const unitPriceInput = row.querySelector('.unit-price');
    const descriptionInput = row.querySelector('.item-description');

    input.value = item.code || item.sku || '';
    if (itemIdInput) itemIdInput.value = item.id;
    if (itemTypeInput) itemTypeInput.value = item.type;
    if (unitPriceInput) unitPriceInput.value = item.price;
    if (descriptionInput) descriptionInput.value = item.display_name;

    if (dropdown) dropdown.style.display = 'none';

    formatPriceInput(unitPriceInput);
    calculateItemTotal(row);
    calculateTotals();
    validateItems();
}

// Función para mostrar resultados de búsqueda de items
function displayItemSearchResults(dropdown, items, input) {
    dropdown.innerHTML = '';

    items.forEach(item => {
        const itemElement = document.createElement('a');
        itemElement.className = 'dropdown-item';
        itemElement.href = '#';
        itemElement.style.cssText = 'padding: 8px 12px; border-bottom: 1px solid #f8f9fa; cursor: pointer;';

        let stockInfo = '';
        if (item.type === 'product' && item.stock !== null) {
            const stockClass = item.stock > 10 ? 'text-success' : item.stock > 0 ? 'text-warning' : 'text-danger';
            stockInfo = `<small class="${stockClass}">Stock: ${item.stock}</small>`;
        }

        itemElement.innerHTML = `
            <div>
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <strong class="text-dark">${item.display_name}</strong>
                        ${item.code ? `<br><small class="text-primary fw-bold"><i class="fas fa-barcode me-1"></i>Código: ${item.code}</small>` : ''}
                        <br>
                        <small class="text-muted">
                            <i class="fas fa-dollar-sign me-1"></i>${parseFloat(item.price).toLocaleString('es-CO', { minimumFractionDigits: 2 })}
                            ${stockInfo ? ' • ' + stockInfo : ''}
                        </small>
                    </div>
                    <small class="badge ${item.type === 'product' ? 'bg-primary' : 'bg-success'}">
                        ${item.type === 'product' ? 'Producto' : 'Servicio'}
                    </small>
                </div>
            </div>
        `;

        itemElement.addEventListener('mouseenter', function () { this.style.backgroundColor = '#f8f9fa'; });
        itemElement.addEventListener('mouseleave', function () { this.style.backgroundColor = 'white'; });
        itemElement.addEventListener('click', function (e) {
            e.preventDefault();
            selectItem(item, input);
        });

        dropdown.appendChild(itemElement);
    });

    dropdown.style.display = 'block';
}

// Función para seleccionar un item
function selectItem(item, input) {
    const row = input.closest('tr[data-index]');
    const dropdown = row.querySelector('.item-search-dropdown');
    const itemIdInput = row.querySelector('.selected-item-id');
    const itemTypeInput = row.querySelector('.selected-item-type');
    const unitPriceInput = row.querySelector('.unit-price');
    const codeInput = row.querySelector('.item-code');

    input.value = item.display_name;
    if (itemIdInput) itemIdInput.value = item.id;
    if (itemTypeInput) itemTypeInput.value = item.type;
    if (unitPriceInput) unitPriceInput.value = item.price;
    if (codeInput) codeInput.value = item.code || item.sku || '';

    if (dropdown) dropdown.style.display = 'none';

    formatPriceInput(unitPriceInput);
    calculateItemTotal(row);
    calculateTotals();
    validateItems();
}

// Función para validar formulario antes del envío
function validateFormBeforeSubmit(event) {
    const submitter = event.submitter || document.activeElement;
    let action = submitter && typeof submitter.value === 'string' ? submitter.value : '';
    if (!action || action.trim() === '') action = (window.__invoiceSubmitAction || '').trim();
    if (!action || action.trim() === '') action = 'save_pending';

    const getDefaultDueDays = () => {
        const el = document.getElementById('invoice_due_days_default');
        const n = el ? parseInt(el.value || '7', 10) : 7;
        return Number.isFinite(n) && n >= 0 ? n : 7;
    };

    const setDueDateByDays = (days) => {
        const invoiceDateInput = document.getElementById('invoice_date');
        const dueDateInput = document.getElementById('due_date');
        if (!invoiceDateInput || !dueDateInput) return;
        if (dueDateInput.value && String(dueDateInput.value).trim() !== '') return;
        const invoiceDate = new Date(invoiceDateInput.value);
        if (isNaN(invoiceDate.getTime())) return;
        const dueDate = new Date(invoiceDate);
        dueDate.setDate(dueDate.getDate() + days);
        const year = dueDate.getFullYear();
        const month = String(dueDate.getMonth() + 1).padStart(2, '0');
        const day = String(dueDate.getDate()).padStart(2, '0');
        dueDateInput.value = `${year}-${month}-${day}`;
    };

    const clearPaymentsForPending = () => {
        const container = document.getElementById('payments-container');
        if (!container) return;
        container.querySelectorAll('select[required]').forEach(s => s.required = false);
        container.querySelectorAll('select').forEach(s => { s.value = ''; });
        container.querySelectorAll('input[name*="[amount]"]').forEach(i => { i.value = ''; i.removeAttribute('data-autofilled'); });
        container.querySelectorAll('.payment-row').forEach(r => r.remove());
    };

    const ensurePaymentRowExists = () => {
        const container = document.getElementById('payments-container');
        if (!container) return;
        if (container.querySelectorAll('.payment-row').length > 0) return;
        if (typeof addPaymentRow === 'function') {
            addPaymentRow();
        }
    };

    const setDefaultMethodEfectivo = () => {
        const container = document.getElementById('payments-container');
        if (!container) return;
        const row = container.querySelector('.payment-row');
        if (!row) return;
        const methodSelect = row.querySelector('select[name*="[method]"]');
        if (!methodSelect || (methodSelect.value || '').trim() !== '') return;
        const hasEfectivo = Array.from(methodSelect.options || []).some(o => (o.value || '').toLowerCase() === 'efectivo');
        if (hasEfectivo) methodSelect.value = 'Efectivo';
    };

    const getInvoiceTotal = () => {
        if (typeof getInvoiceTotalFromDOM === 'function') {
            return Number(getInvoiceTotalFromDOM()) || 0;
        }
        const el = document.getElementById('totalDisplay');
        if (!el) return 0;
        if (typeof parseCurrency === 'function') {
            return parseCurrency(el.textContent || '0');
        }
        return parseFloat(String(el.textContent || '0').replace(/[^\d.-]/g, '')) || 0;
    };

    const getExistingPaid = () => {
        const el = document.getElementById('existing-paid-total');
        return el ? (parseFloat(el.value) || 0) : 0;
    };

    const getNewPaymentsTotal = () => {
        const container = document.getElementById('payments-container');
        if (!container) return 0;
        let total = 0;
        container.querySelectorAll('input[name*="[amount]"]').forEach(i => {
            if (typeof parseCurrency === 'function') total += parseCurrency(i.value || '0');
            else total += parseFloat(String(i.value || '0').replace(/[^\d.-]/g, '')) || 0;
        });
        return total;
    };

    if (action === 'save_pending') {
        setDueDateByDays(getDefaultDueDays());
        clearPaymentsForPending();
    } else if (action === 'save') {
        ensurePaymentRowExists();
        setDefaultMethodEfectivo();
        const invoiceTotal = getInvoiceTotal();
        const existingPaid = getExistingPaid();
        const newPaid = getNewPaymentsTotal();
        const remaining = Math.max(0, invoiceTotal - existingPaid - newPaid);

        if (invoiceTotal > 0 && newPaid <= 0) {
            const container = document.getElementById('payments-container');
            const firstRow = container ? container.querySelector('.payment-row') : null;
            const methodSelect = firstRow ? firstRow.querySelector('select[name*="[method]"]') : null;
            const amountInput = firstRow ? firstRow.querySelector('input[name*="[amount]"]') : null;

            if (amountInput && remaining > 0) {
                amountInput.value = remaining;
                amountInput.dataset.autofilled = '1';
                if (typeof formatCurrencyInput === 'function') formatCurrencyInput(amountInput);
            }

            if (methodSelect) {
                if ((methodSelect.value || '').trim() === '') {
                    const hasEfectivo = Array.from(methodSelect.options || []).some(o => (o.value || '').toLowerCase() === 'efectivo');
                    if (hasEfectivo) methodSelect.value = 'Efectivo';
                }
                methodSelect.focus();
                methodSelect.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
    }

    if (!updateFormValidation()) {
        event.preventDefault();

        const loadingStatus = document.getElementById('loadingStatus');
        const loadingText = document.getElementById('loadingText');

        if (loadingStatus && loadingText) {
            loadingText.textContent = 'Validando formulario...';
            loadingStatus.style.display = 'block';
            setTimeout(() => { loadingStatus.style.display = 'none'; }, 2000);
        }

        const firstInvalidField = document.querySelector('.is-invalid');
        if (firstInvalidField) {
            firstInvalidField.focus();
            firstInvalidField.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        return false;
    }

    if (action === 'save') {
        const invoiceTotal = getInvoiceTotal();
        const newPaid = getNewPaymentsTotal();
        if (invoiceTotal > 0 && newPaid <= 0) {
            event.preventDefault();
            const container = document.getElementById('payments-container');
            const firstRow = container ? container.querySelector('.payment-row') : null;
            const amountInput = firstRow ? firstRow.querySelector('input[name*="[amount]"]') : null;
            if (amountInput) {
                amountInput.classList.add('is-invalid');
                amountInput.focus();
                amountInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            const loadingStatus = document.getElementById('loadingStatus');
            const loadingText = document.getElementById('loadingText');
            if (loadingStatus && loadingText) {
                loadingText.textContent = 'Ingrese un pago o use Guardar pendiente';
                loadingStatus.style.display = 'block';
                setTimeout(() => { loadingStatus.style.display = 'none'; }, 2500);
            }
            return false;
        }
    }

    const loadingStatus = document.getElementById('loadingStatus');
    const loadingText = document.getElementById('loadingText');

    if (loadingStatus && loadingText) {
        loadingText.textContent = (action === 'save_pending') ? 'Guardando pendiente...' : 'Guardando factura...';
        loadingStatus.style.display = 'block';
    }

    return true;
}

// Función para establecer fecha de vencimiento usando el plazo configurado
function setDueDate() {
    const invoiceDateInput = document.getElementById('invoice_date');
    const dueDateInput = document.getElementById('due_date');

    if (invoiceDateInput && dueDateInput) {
        const invoiceDate = new Date(invoiceDateInput.value);
        if (!isNaN(invoiceDate.getTime())) {
            const dueDate = new Date(invoiceDate);
            const el = document.getElementById('invoice_due_days_default');
            const days = el ? parseInt(el.value || '7', 10) : 7;
            dueDate.setDate(dueDate.getDate() + (Number.isFinite(days) ? days : 7));

            // Formatear a YYYY-MM-DD
            const year = dueDate.getFullYear();
            const month = String(dueDate.getMonth() + 1).padStart(2, '0');
            const day = String(dueDate.getDate()).padStart(2, '0');

            dueDateInput.value = `${year}-${month}-${day}`;
        }
    }
}

// Event listeners
document.addEventListener('DOMContentLoaded', function () {
    console.log('✅ Sistema de facturación unificado cargado');
    window.__invoiceSubmitAction = '';

    const taxCfg = getTaxConfig();
    document.querySelectorAll('.item-tax').forEach(sel => {
        if (!taxCfg.enabled) {
            sel.value = '0';
            sel.disabled = true;
        } else {
            sel.disabled = false;
        }
    });

    // Event delegation para inputs
    document.addEventListener('input', function (e) {
        if (e.target.classList.contains('unit-price')) {
            formatCurrencyInput(e.target);
        }

        if (e.target.classList.contains('quantity') ||
            e.target.classList.contains('unit-price') ||
            e.target.classList.contains('item-tax')) {

            const row = e.target.closest('tr[data-index]');
            if (row) {
                calculateItemTotal(row);
                calculateTotals();
                validateItems();
            }
        }

        if (e.target.classList.contains('item-description')) {
            validateItems();
        }

        // Búsqueda de items
        if (e.target.classList.contains('item-description')) {
            const row = e.target.closest('tr[data-index]');
            const searchTerm = e.target.value.trim();
            const rowIndex = row.dataset.index;

            if (itemSearchTimeouts.has(rowIndex)) clearTimeout(itemSearchTimeouts.get(rowIndex));

            if (searchTerm.length >= 2) {
                const timeoutId = setTimeout(() => {
                    searchItems(e.target, searchTerm);
                }, 300);
                itemSearchTimeouts.set(rowIndex, timeoutId);
            } else {
                const dropdown = row.querySelector('.item-search-dropdown');
                if (dropdown) dropdown.style.display = 'none';
            }
        }

        // Búsqueda de cliente
        if (e.target.id === 'client_search') {
            const searchTerm = e.target.value.trim();
            if (searchTerm === '') {
                if (clientIdInput) clientIdInput.value = '';
                if (clientDropdown) clientDropdown.style.display = 'none';
                hideClientInfo();
                validateClientSelection();
                return;
            }

            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                searchClients(searchTerm);
            }, 300);
        }

        // Búsqueda por código
        if (e.target.classList.contains('item-code')) {
            const row = e.target.closest('tr[data-index]');
            const searchTerm = e.target.value.trim();
            const rowIndex = row.dataset.index + '_code';

            if (itemSearchTimeouts.has(rowIndex)) clearTimeout(itemSearchTimeouts.get(rowIndex));

            if (searchTerm.length >= 2) {
                const timeoutId = setTimeout(() => {
                    searchItemsByCode(e.target, searchTerm);
                }, 300);
                itemSearchTimeouts.set(rowIndex, timeoutId);
            } else {
                const dropdown = row.querySelector('.code-search-dropdown');
                if (dropdown) dropdown.style.display = 'none';
            }
        }
    });

    // Ocultar dropdowns al hacer clic fuera
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.position-relative')) {
            document.querySelectorAll('.item-search-dropdown, .code-search-dropdown, #client_dropdown').forEach(dropdown => {
                dropdown.style.display = 'none';
            });
        }
    });

    // Botón agregar item
    const addItemBtn = document.getElementById('addItemBtn');
    if (addItemBtn) {
        addItemBtn.addEventListener('click', function () {
            addNewItem();
            validateItems();
        });
    }

    // Shortcuts
    document.addEventListener('keydown', function (e) {
        if (e.ctrlKey && e.key === 'Enter') {
            e.preventDefault();
            addNewItem();
            validateItems();
        }
        if (e.key === 'Escape') {
            const activeElement = document.activeElement;
            if (activeElement && activeElement.classList.contains('form-control')) {
                activeElement.blur();
            }
        }
    });

    // Validación de formulario
    const form = document.getElementById('invoiceForm');
    if (form) {
        form.querySelectorAll('button[type="submit"][name="action"]').forEach(btn => {
            btn.addEventListener('click', function () {
                window.__invoiceSubmitAction = this.value || '';
            });
        });
        form.addEventListener('submit', validateFormBeforeSubmit);
    }

    // Inicialización
    initializePriceFormatting();
    ensureEmptyRows();

    // Toggle transferencia
    const pm = document.getElementById('payment_method');
    const td = document.getElementById('payment-details-container'); // In template it's called payment-details-container, check if there is a specific transfer details div?
    // Original code checked for 'transferDetails' ID which might be inside payment-details-container.
    // In the template, we just show payment-details-container if method is selected.
    // Let's keep the logic simple: if method selected, show amount/ref.

    if (pm && td) {
        pm.addEventListener('change', function () {
            if (this.value && this.value !== '') {
                td.style.display = 'block';
                td.classList.add('fade-in');
            } else {
                td.style.display = 'none';
            }
        });
    }

    // Validación inicial
    setTimeout(() => {
        validateClientSelection();
        validateItems();
        calculateTotals();
        updateDeleteButtonsVisibility();
    }, 100);
});
