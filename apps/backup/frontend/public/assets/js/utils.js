/**
 * Funciones utilitarias comunes para todo el sistema
 */

// Configuración global de SweetAlert2
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Swal !== 'undefined') {
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', Swal.stopTimer)
                toast.addEventListener('mouseleave', Swal.resumeTimer)
            }
        });
        window.Toast = Toast;

        // Sobrescribir alert() nativo
        window.originalAlert = window.alert;
        window.alert = function(message) {
            Swal.fire({
                title: 'Atención',
                text: message,
                icon: 'info',
                confirmButtonText: 'Entendido',
                customClass: {
                    popup: 'rounded-4 shadow-lg border-0',
                    confirmButton: 'btn btn-primary rounded-pill px-4'
                },
                buttonsStyling: false
            });
        };
        window.originalConfirm = window.confirm;
        window.__confirmBypass = false;
        window.confirm = function(message) {
            if (window.__confirmBypass) {
                window.__confirmBypass = false;
                return true;
            }
            Swal.fire({
                title: '¿Estás seguro?',
                text: message,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Sí, continuar',
                cancelButtonText: 'Cancelar',
                customClass: {
                    popup: 'rounded-4 shadow-lg border-0',
                    confirmButton: 'btn btn-primary rounded-pill px-4 me-2',
                    cancelButton: 'btn btn-outline-secondary rounded-pill px-4'
                },
                buttonsStyling: false
            }).then(function(result){
                if (result.isConfirmed) {
                    window.__confirmBypass = true;
                    var el = document.activeElement;
                    if (!el) return;
                    var form = el.closest && el.closest('form');
                    if (form) {
                        if (typeof form.requestSubmit === 'function') {
                            form.requestSubmit(el);
                        } else {
                            form.submit();
                        }
                    } else {
                        el.click();
                    }
                }
            });
            return false;
        };

        // Función global para confirmaciones bonitas
        // NOTA: window.confirm es sincrono y bloqueante, no se puede sobrescribir fácilmente con SweetAlert (que es asíncrono).
        // Por eso creamos una función alternativa global.
        window.confirmAction = function(message, callback) {
            Swal.fire({
                title: '¿Estás seguro?',
                text: message,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Sí, continuar',
                cancelButtonText: 'Cancelar',
                customClass: {
                    popup: 'rounded-4 shadow-lg border-0',
                    confirmButton: 'btn btn-primary rounded-pill px-4 me-2',
                    cancelButton: 'btn btn-outline-secondary rounded-pill px-4'
                },
                buttonsStyling: false
            }).then((result) => {
                if (result.isConfirmed && callback) callback();
            });
        };
    }
});

/**
 * Formatea un número de teléfono removiendo caracteres no válidos
 * @param {string} phone - El número de teléfono a formatear
 * @returns {string} - El número de teléfono formateado
 */
function formatPhone(phone) {
    // Remover caracteres no válidos, solo permitir números, +, -, espacios y paréntesis
    return phone.replace(/[^0-9+\-\s()]/g, '');
}

/**
 * Formatea un número de teléfono en un input
 * @param {HTMLInputElement} input - El elemento input a formatear
 */
function formatPhoneInput(input) {
    input.value = formatPhone(input.value);
}

/**
 * Valida si un email tiene formato válido
 * @param {string} email - El email a validar
 * @returns {boolean} - True si es válido, false si no
 */
function validateEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
}

/**
 * Valida si un campo requerido tiene valor
 * @param {HTMLInputElement} field - El campo a validar
 * @returns {boolean} - True si es válido, false si no
 */
function validateRequired(field) {
    return field.value.trim() !== '';
}

/**
 * Sanitiza una cadena de texto removiendo caracteres peligrosos
 * @param {string} str - La cadena a sanitizar
 * @returns {string} - La cadena sanitizada
 */
function sanitizeString(str) {
    return str.replace(/[<>"'&]/g, function(match) {
        const map = {
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#x27;',
            '&': '&amp;'
        };
        return map[match];
    });
}

/**
 * Formatea un valor numérico como moneda usando la configuración global
 * @param {number|string} amount - El valor a formatear
 * @returns {string} - El valor formateado con símbolo y separadores
 */
function formatCurrency(amount) {
    // Obtener configuración global o usar defaults
    const config = window.SYSTEM_CONFIG?.currency || { symbol: '$', code: 'USD' };
    const symbol = config.symbol || '$';
    
    // Convertir a número
    const num = parseFloat(amount || 0);
    
    // Formatear:
    // - Sin decimales (user request: "NO QUIERO QUE SE MUESTRE LOS NUMEROS DESPUES DEL.")
    // - Coma como separador de miles (user request: "QUE SE COLOQUE LA COMA DESPUES DE LOS MIL")
    // Usamos 'en-US' para forzar la coma en miles, pero quitamos decimales.
    return symbol + ' ' + num.toLocaleString('en-US', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    });
}

/**
 * Formatea un input de moneda en tiempo real (agrega comas)
 * @param {HTMLInputElement} input - El input a formatear
 */
function formatCurrencyInput(input) {
    // Guardar la posición del cursor
    const start = input.selectionStart;
    const end = input.selectionEnd;
    const initialLength = input.value.length;

    // Obtener valor actual eliminando todo excepto números
    let value = input.value.replace(/\D/g, '');
    
    // Si está vacío, dejarlo vacío
    if (value === '') {
        input.value = '';
        return;
    }

    // Convertir a número y formatear con comas
    // Usamos toLocaleString para formatear con comas
    const formatted = parseInt(value, 10).toLocaleString('en-US');
    
    input.value = formatted;

    // Restaurar posición del cursor (ajustada por los cambios de longitud)
    // Esto ayuda a que el cursor no salte al final si se edita en el medio
    const newLength = input.value.length;
    const diff = newLength - initialLength;
    
    // Si se agregó una coma, ajustar cursor
    if (diff > 0) {
        input.setSelectionRange(start + diff, end + diff);
    } else {
        // Si no cambió longitud o disminuyó, mantener o ajustar
        input.setSelectionRange(start, end);
    }
}

/**
 * Parsea un valor de moneda a número
 * @param {string|number} value - El valor a parsear
 * @returns {number} - El número
 */
function parseCurrency(value) {
    if (!value) return 0;
    if (typeof value === 'number') return value;
    // Eliminar todo excepto números
    // (Si soportáramos decimales, tendríamos que manejar el punto o la coma decimal según locale)
    // Para este caso (enteros con coma de miles):
    return parseInt(value.replace(/\D/g, ''), 10) || 0;
}

/**
 * Inicializa inputs de moneda automáticamente
 */
function initializeCurrencyInputs() {
    // Selectores para encontrar inputs de precio/costo
    const selectors = [
        '.money-input', 
        '.currency-input', 
        'input[name*="price"]', 
        'input[name*="amount"]', 
        'input[name*="cost"]', 
        'input[name*="total"]',
        'input[id*="price"]', 
        'input[id*="amount"]', 
        'input[id*="cost"]', 
        'input[id*="total"]'
    ];
    
    const inputs = document.querySelectorAll(selectors.join(', '));
    
    inputs.forEach(input => {
        // Ignorar si ya está inicializado o es hidden/checkbox/radio
        if (input.dataset.currencyInitialized || input.type === 'hidden' || input.type === 'checkbox' || input.type === 'radio') return;
        
        input.dataset.currencyInitialized = 'true';
        input.setAttribute('autocomplete', 'off'); // Evitar autocompletado del navegador que puede interferir
        
        // Formatear valor inicial
        if (input.value) {
            formatCurrencyInput(input);
        }
        
        // Listener para input
        input.addEventListener('input', function(e) {
            formatCurrencyInput(this);
        });
    });
}

// Inicializar al cargar y exponer globalmente
document.addEventListener('DOMContentLoaded', initializeCurrencyInputs);
// También exponer para llamar manualmente si se agregan inputs dinámicamente
window.initializeCurrencyInputs = initializeCurrencyInputs;


/**
 * Muestra un mensaje de éxito
 * @param {string} message - El mensaje a mostrar
 */
function showSuccess(message) {
    if (typeof Swal !== 'undefined') {
        if (window.Toast) {
            window.Toast.fire({
                icon: 'success',
                title: message
            });
        } else {
            // Fallback si Toast no está definido por alguna razón
            Swal.fire({
                icon: 'success',
                title: '¡Éxito!',
                text: message,
                timer: 2000,
                showConfirmButton: false,
                position: 'top-end',
                toast: true
            });
        }
    } else {
        alert(message);
    }
}

/**
 * Muestra un mensaje de error
 * @param {string} message - El mensaje a mostrar
 */
function showError(message) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: message,
            customClass: {
                popup: 'rounded-4 shadow-lg border-0',
                confirmButton: 'btn btn-danger rounded-pill px-4'
            },
            buttonsStyling: false
        });
    } else {
        alert('Error: ' + message);
    }
}

/**
 * Muestra un mensaje de confirmación
 * @param {string} message - El mensaje a mostrar
 * @param {function} callback - Función a ejecutar si se confirma
 */
function showConfirm(message, callback) {
    if (typeof window.confirmAction === 'function') {
        window.confirmAction(message, callback);
    } else if (typeof Swal !== 'undefined') {
         Swal.fire({
            title: '¿Estás seguro?',
            text: message,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Sí, continuar',
            cancelButtonText: 'Cancelar',
            customClass: {
                popup: 'rounded-4 shadow-lg border-0',
                confirmButton: 'btn btn-primary rounded-pill px-4 me-2',
                cancelButton: 'btn btn-outline-secondary rounded-pill px-4'
            },
            buttonsStyling: false
        }).then((result) => {
            if (result.isConfirmed && callback) {
                callback();
            }
        });
    } else {
        if (confirm(message) && callback) {
            callback();
        }
    }
}

/**
 * Calcula el total de una tabla de items
 * @param {string} tableSelector - Selector de la tabla
 * @param {string} quantitySelector - Selector del campo cantidad
 * @param {string} priceSelector - Selector del campo precio
 * @returns {number} - El subtotal calculado
 */
function calculateTableSubtotal(tableSelector, quantitySelector, priceSelector) {
    let subtotal = 0;
    
    document.querySelectorAll(tableSelector + ' tr').forEach(row => {
        const quantity = parseFloat(row.querySelector(quantitySelector)?.value || 0);
        const price = parseFloat(row.querySelector(priceSelector)?.value || 0);
        subtotal += quantity * price;
    });
    
    return subtotal;
}

function normalizeEmoji(text) {
    return String(text || '').replace(/\uFFFD/g, '');
}
window.normalizeEmoji = normalizeEmoji;

/**
 * Decodifica entidades HTML (p. ej. &#128993;) a caracteres reales.
 * Útil para emojis guardados como entidades en la BD.
 */
function decodeHtmlEntities(text) {
    try {
        const div = document.createElement('div');
        div.innerHTML = String(text || '');
        return div.textContent || '';
    } catch (e) {
        return String(text || '');
    }
}
window.decodeHtmlEntities = decodeHtmlEntities;

function ensureEmojiPresentation(text) {
    const t = String(text || '');
    const needsVS16 = /[\u2600-\u26FF\u2700-\u27BF]/;
    if (needsVS16.test(t) && !/\uFE0F/.test(t)) {
        return t + '\uFE0F';
    }
    return t;
}
window.ensureEmojiPresentation = ensureEmojiPresentation;

if (typeof window.resizeIframe !== 'function') {
    window.resizeIframe = function(obj) {
        try {
            var h = obj.contentWindow && obj.contentWindow.document && obj.contentWindow.document.documentElement ? obj.contentWindow.document.documentElement.scrollHeight : 0;
            obj.style.height = (h && h > 100 ? h : 800) + 'px';
        } catch (e) {
            obj.style.height = '800px';
        }
    };
}

/**
 * Calcula totales con impuestos, descuentos y envío
 * @param {number} subtotal - El subtotal base
 * @param {number} taxPercentage - Porcentaje de impuesto
 * @param {number} discountAmount - Monto de descuento
 * @param {number} shippingCost - Costo de envío
 * @returns {object} - Objeto con todos los totales calculados
 */
function calculateTotalsWithTax(subtotal, taxPercentage = 0, discountAmount = 0, shippingCost = 0) {
    const taxAmount = subtotal * (taxPercentage / 100);
    const total = subtotal + taxAmount + shippingCost - discountAmount;
    
    return {
        subtotal: subtotal,
        taxAmount: taxAmount,
        discountAmount: discountAmount,
        shippingCost: shippingCost,
        total: total
    };
}

function upgradeBootstrapAlerts() {
    if (typeof Swal === 'undefined') return;
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(a){
        if (a.dataset && a.dataset.swalUpgraded === 'true') return;
        var cs = window.getComputedStyle(a);
        var visible = !(cs.display === 'none' || cs.visibility === 'hidden' || a.classList.contains('d-none') || a.classList.contains('visually-hidden')) && (a.offsetWidth > 0 || a.offsetHeight > 0);
        var shouldConvert = a.classList.contains('show') || a.dataset.autoToast === 'true';
        if (!visible || !shouldConvert) return;
        var type = 'info';
        if (a.classList.contains('alert-success')) type = 'success';
        else if (a.classList.contains('alert-danger')) type = 'error';
        else if (a.classList.contains('alert-warning')) type = 'warning';
        else if (a.classList.contains('alert-primary')) type = 'info';
        else if (a.classList.contains('alert-info')) type = 'info';
        var text = String(a.textContent || '').replace(/\s+/g,' ').trim();
        if (window.Toast) {
            window.Toast.fire({ icon: type, title: text });
        } else {
            Swal.fire({
                icon: type,
                title: type === 'error' ? 'Error' : (type === 'success' ? 'Éxito' : 'Información'),
                text: text,
                toast: true,
                position: 'top-end',
                timer: 3000,
                showConfirmButton: false
            });
        }
        a.dataset.swalUpgraded = 'true';
        a.classList.add('d-none');
    });
}
window.upgradeBootstrapAlerts = upgradeBootstrapAlerts;
document.addEventListener('DOMContentLoaded', function(){ 
    try { upgradeBootstrapAlerts(); } catch (e) {}
});

/**
 * Actualiza los elementos de display de totales
 * @param {object} totals - Objeto con los totales calculados
 * @param {object} selectors - Objeto con los selectores de los elementos a actualizar
 */
function updateTotalDisplays(totals, selectors) {
    if (selectors.subtotal) {
        const subtotalElement = document.querySelector(selectors.subtotal);
        if (subtotalElement) {
            subtotalElement.textContent = formatCurrency(totals.subtotal);
        }
    }
    
    if (selectors.tax) {
        const taxElement = document.querySelector(selectors.tax);
        if (taxElement) {
            taxElement.textContent = formatCurrency(totals.taxAmount);
        }
    }
    
    if (selectors.total) {
        const totalElement = document.querySelector(selectors.total);
        if (totalElement) {
            totalElement.textContent = formatCurrency(totals.total);
        }
    }
}

if (typeof window.getCsrfToken !== 'function') {
    window.getCsrfToken = function() {
        try {
            const meta = document.querySelector('meta[name="csrf-token"]');
            if (meta && meta.content) return meta.content;
        } catch (e) {}
        try {
            const input = document.querySelector('input[name="csrf_token"]');
            if (input && input.value) return input.value;
        } catch (e) {}
        return '';
    };
}

if (typeof window.parseJsonResponse !== 'function') {
    window.parseJsonResponse = function(response) {
        return response.text().then(function(text){
            const ct = response.headers && response.headers.get ? (response.headers.get('content-type') || '') : '';
            if (ct.indexOf('application/json') === -1) {
                return { success: false, message: 'Respuesta no JSON', status: response.status, raw: text };
            }
            let data;
            try { data = JSON.parse(text || '{}'); }
            catch (e) { return { success: false, message: 'JSON inválido', status: response.status, raw: text }; }
            if (response.ok) return data;
            if (data && typeof data === 'object') {
                if (typeof data.success === 'undefined') data.success = false;
                if (typeof data.status === 'undefined') data.status = response.status;
                return data;
            }
            return { success: false, message: 'HTTP ' + response.status, status: response.status, raw: text };
        });
    };
}

if (typeof window.fetchJson !== 'function') {
    window.fetchJson = function(url, options) {
        const opts = options || {};
        const method = String(opts.method || 'POST').toUpperCase();
        const headers = Object.assign({}, opts.headers || {});
        if (!headers.Accept) headers.Accept = 'application/json';
        let body = opts.body;
        const csrf = (opts.csrf === false) ? '' : window.getCsrfToken();
        if (csrf) {
            if (typeof FormData !== 'undefined' && body instanceof FormData) {
                try { if (!body.has('csrf_token')) body.append('csrf_token', csrf); } catch (e) {}
            } else if (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams) {
                try { if (!body.get('csrf_token')) body.append('csrf_token', csrf); } catch (e) {}
            } else if (body && typeof body === 'object' && !(body instanceof Blob) && !(body instanceof ArrayBuffer) && !(typeof ReadableStream !== 'undefined' && body instanceof ReadableStream) && typeof body !== 'string') {
                if (!Object.prototype.hasOwnProperty.call(body, 'csrf_token')) body.csrf_token = csrf;
            }
        }
        if (body && typeof body === 'object' && !(body instanceof FormData) && !(body instanceof URLSearchParams) && !(body instanceof Blob) && !(body instanceof ArrayBuffer) && !(typeof ReadableStream !== 'undefined' && body instanceof ReadableStream) && typeof body !== 'string') {
            body = JSON.stringify(body);
            if (!headers['Content-Type']) headers['Content-Type'] = 'application/json';
        }
        return fetch(url, {
            method: method,
            headers: headers,
            body: (method === 'GET' || method === 'HEAD') ? undefined : body,
            credentials: opts.credentials || 'same-origin'
        }).then(function(r){ return window.parseJsonResponse(r); });
    };
}

if (typeof window.postJson !== 'function') {
    window.postJson = function(a, b, c) {
        if (typeof a === 'string') {
            return window.fetchJson(a, Object.assign({ method: 'POST', body: b }, c || {}));
        }
        return window.fetchJson('config_operations.php', Object.assign({ method: 'POST', body: a }, b || {}));
    };
}
