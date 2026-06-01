console.log("Core cargado correctamente ✅");

(function () {
    try {
        if (window.__coreFetchAuthRedirectPatched) return;
        window.__coreFetchAuthRedirectPatched = true;
        if (typeof window.fetch !== 'function') return;

        const getBasePath = function () {
            try {
                const p = String(window.location && window.location.pathname ? window.location.pathname : '');
                if (p === '/core' || p.indexOf('/core/') === 0) return '/core';
            } catch (e) {
            }
            return '';
        };

        const getLoginUrl = function () {
            const base = getBasePath();
            const msg = encodeURIComponent('Sesión inválida. Inicia sesión nuevamente.');
            return base + '/login/index.php?error=' + msg;
        };

        const mergeHeaders = function (headers) {
            try {
                const h = new Headers(headers || {});
                if (!h.has('X-Requested-With')) h.set('X-Requested-With', 'XMLHttpRequest');
                if (!h.has('Accept')) h.set('Accept', 'application/json, text/plain, */*');
                return h;
            } catch (e) {
                return headers;
            }
        };

        const originalFetch = window.fetch.bind(window);
        window.fetch = function (input, init) {
            try {
                const nextInit = init ? Object.assign({}, init) : {};
                nextInit.headers = mergeHeaders(nextInit.headers);
                if (input instanceof Request) {
                    input = new Request(input, nextInit);
                    return originalFetch(input).then(handleResponse);
                }
                return originalFetch(input, nextInit).then(handleResponse);
            } catch (e) {
                return originalFetch(input, init).then(handleResponse);
            }
        };

        function handleResponse(resp) {
                try {
                    if (resp && resp.status === 401) {
                        window.location.href = getLoginUrl();
                    }
                    if (resp && resp.redirected && resp.url && /\/login\/index\.php/i.test(String(resp.url))) {
                        window.location.href = String(resp.url);
                    }
                } catch (e) {
                }
                return resp;
        }
    } catch (e) {
    }
})();

if (!window.__corePrint || typeof window.__corePrint.printUrl !== 'function') {
    window.__corePrint = {
        iframe: null,
        timer: null,
        lastUrl: '',
        lastAt: 0,
        printUrl: function (url) {
            try {
                const now = Date.now();
                if (this.lastUrl === url && (now - this.lastAt) < 2500) return;
                this.lastUrl = url;
                this.lastAt = now;

                const run = () => {
                    try {
                        if (this.iframe && this.iframe.parentNode) {
                            try { this.iframe.remove(); } catch (e) { this.iframe.parentNode.removeChild(this.iframe); }
                        }
                        if (this.timer) { try { clearTimeout(this.timer); } catch (e) {} this.timer = null; }

                        const iframe = document.createElement('iframe');
                        iframe.style.position = 'fixed';
                        iframe.style.right = '0';
                        iframe.style.bottom = '0';
                        iframe.style.width = '0';
                        iframe.style.height = '0';
                        iframe.style.border = '0';
                        iframe.style.opacity = '0';
                        iframe.style.pointerEvents = 'none';
                        iframe.setAttribute('aria-hidden', 'true');
                        iframe.onload = () => {
                            try {
                                const w = iframe.contentWindow;
                                if (!w) return;
                                try { w.focus(); } catch (e) {}
                            } finally {
                                this.timer = setTimeout(() => {
                                    try { iframe.remove(); } catch (e) { try { iframe.parentNode.removeChild(iframe); } catch (e2) {} }
                                }, 1500);
                            }
                        };
                        iframe.src = url;
                        document.body.appendChild(iframe);
                        this.iframe = iframe;
                    } catch (e) {
                        try { window.open(url, 'PrintWindow', 'width=1000,height=800,scrollbars=yes,resizable=yes'); } catch (e2) {}
                    }
                };

                if (document.body) run();
                else document.addEventListener('DOMContentLoaded', run, { once: true });
            } catch (e) {
                try { window.open(url, 'PrintWindow', 'width=1000,height=800,scrollbars=yes,resizable=yes'); } catch (e2) {}
            }
        }
    };
}

// Funciones para el dashboard
document.addEventListener('DOMContentLoaded', function () {
    // Código JavaScript para la aplicación
    console.log('Core Dashboard cargado correctamente');

    // Sidebar Toggle Functionality
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.querySelector('.main-content');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const overlay = document.querySelector('.sidebar-overlay');
    const logoToggleBtn = document.getElementById('logoToggleBtn');

    function updateHamburgerIcon() {
        const icon = sidebarToggle ? sidebarToggle.querySelector('i') : null;
        if (!icon) return;
        if (window.innerWidth >= 992) {
            icon.classList.remove('fa-times');
            icon.classList.add('fa-bars');
            return;
        }
        if (document.body.classList.contains('sidebar-mobile-open')) {
            icon.classList.remove('fa-bars');
            icon.classList.add('fa-times');
        } else {
            icon.classList.remove('fa-times');
            icon.classList.add('fa-bars');
        }
    }

    function handleSidebarToggle(e) {
        if (e) e.preventDefault();
        if (window.innerWidth >= 992) {
            document.body.classList.toggle('sidebar-collapsed');
            localStorage.setItem('sidebarCollapsed', document.body.classList.contains('sidebar-collapsed'));
        } else {
            // En móvil, la clase sidebar-mobile-open regula la apertura y los márgenes del contenido
            document.body.classList.toggle('sidebar-mobile-open');
        }
        updateHamburgerIcon();
    }

    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', handleSidebarToggle);
    }

    if (logoToggleBtn) {
        logoToggleBtn.addEventListener('click', function (e) {
            if (window.innerWidth >= 992) {
                e.preventDefault();
                handleSidebarToggle();
            }
        });
    }

    if (overlay && sidebar) {
        overlay.addEventListener('click', function () {
            if (window.innerWidth < 992) {
                sidebar.classList.add('mobile-hidden');
                overlay.classList.remove('active');
                document.body.classList.remove('sidebar-mobile-open');
                updateHamburgerIcon();
            }
        });
    }

    // Estado inicial
    if (window.innerWidth < 992) {
        // En móvil, inicia cerrado por defecto (no tiene la clase sidebar-mobile-open)
        document.body.classList.remove('sidebar-mobile-open');
        document.body.classList.remove('sidebar-collapsed'); // No aplica acá
    } else if (localStorage.getItem('sidebarCollapsed') === 'true') {
        // PC recuerda configuración
        document.body.classList.add('sidebar-collapsed');
    } else {
        document.body.classList.remove('sidebar-collapsed');
    }
    updateHamburgerIcon();
    window.addEventListener('resize', updateHamburgerIcon);

    // Active menu item highlighting
    function setActiveMenuItem() {
        const currentPath = window.location.pathname;
        const navLinks = document.querySelectorAll('.nav-link');

        // Remove active class from all items first
        document.querySelectorAll('.nav-item').forEach(item => {
            item.classList.remove('active');
        });

        navLinks.forEach(link => {
            const href = link.getAttribute('href');
            if (!href || href === '#') return;

            // Remove relative path components (../ or ./)
            const cleanHref = href.replace(/^(\.\.\/|\.\/)+/, '');

            // Check exact match
            if (currentPath.endsWith(cleanHref)) {
                link.closest('.nav-item').classList.add('active');
                return;
            }

            // Check directory match (for module navigation)
            // Example: currentPath = /core/orders/view.php
            // cleanHref = orders/index.php
            // We check if currentPath contains 'orders/' and cleanHref is 'orders/index.php'
            if (cleanHref.endsWith('index.php')) {
                const moduleDir = cleanHref.replace('index.php', ''); // e.g., 'orders/'
                // Only match if moduleDir is not empty (to avoid matching root index.php everywhere if that was the case)
                // and if the current path actually includes this directory
                if (moduleDir && currentPath.includes(moduleDir)) {
                    link.closest('.nav-item').classList.add('active');
                }
            }
        });
    }

    setActiveMenuItem();

    // Fallback: delegación por si el botón se renderiza tarde
    document.addEventListener('click', function(ev){
        const btn = ev.target.closest && ev.target.closest('#sidebarToggle');
        if (!btn) return;
        ev.preventDefault();
        if (window.innerWidth >= 992) {
            document.body.classList.toggle('sidebar-collapsed');
            try { localStorage.setItem('sidebarCollapsed', document.body.classList.contains('sidebar-collapsed')); } catch (_) {}
        } else {
            document.body.classList.toggle('sidebar-mobile-open');
        }
        updateHamburgerIcon();
    }, { passive: false });

    function initTooltips() { return; }

    // Animación de las tarjetas al cargar
    const cards = document.querySelectorAll('.card');
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';

        setTimeout(() => {
            card.style.transition = 'all 0.5s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100);
    });

    // Efecto hover en las tarjetas
    cards.forEach(card => {
        card.addEventListener('mouseenter', function () {
            this.style.transform = 'translateY(-5px)';
            this.style.transition = 'transform 0.3s ease';
        });

        card.addEventListener('mouseleave', function () {
            this.style.transform = 'translateY(0)';
        });
    });

    // Actualizar hora en tiempo real
    updateTime();
    setInterval(updateTime, 1000);

    // Mostrar mensaje de bienvenida
    showWelcomeMessage();
});

// Smooth scrolling for anchor links
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        const href = this.getAttribute('href');
        // Validar que el href no esté vacío y sea un selector válido
        if (href && href.length > 1) {
            e.preventDefault();
            const target = document.querySelector(href);
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        }
    });
});

// Función para actualizar la hora
function updateTime() {
    const timeElements = document.querySelectorAll('.current-time');
    const now = new Date();
    const timeString = now.toLocaleString('es-ES', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });

    timeElements.forEach(element => {
        element.textContent = timeString;
    });
}

// Función para mostrar mensaje de bienvenida
function showWelcomeMessage() {
    const hour = new Date().getHours();
    let greeting;

    if (hour < 12) {
        greeting = '🌅 Buenos días';
    } else if (hour < 18) {
        greeting = '☀️ Buenas tardes';
    } else {
        greeting = '🌙 Buenas noches';
    }

    // Buscar elementos de saludo y actualizarlos
    const greetingElements = document.querySelectorAll('.greeting-text');
    greetingElements.forEach(element => {
        element.textContent = greeting;
    });
}

// Función para confirmar logout
function confirmLogout() {
    if (typeof showConfirm === 'function') {
        showConfirm('¿Estás seguro de que deseas cerrar sesión?', () => {
            window.location.href = '../login/logout.php';
        });
    } else {
        if (confirm('¿Estás seguro de que deseas cerrar sesión?')) {
            window.location.href = '../login/logout.php';
        }
    }
}

// Función para mostrar notificaciones
function showNotification(message, type = 'info') {
    var icon = 'info';
    if (type === 'success') icon = 'success';
    else if (type === 'error' || type === 'danger') icon = 'error';
    else if (type === 'warning') icon = 'warning';
    var timerMs = type === 'success' ? 800 : (type === 'warning' ? 2000 : (type === 'error' || type === 'danger') ? 6000 : 1500);
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            icon: icon,
            title: type === 'success' ? 'Éxito' : (type === 'error' || type === 'danger') ? 'Error' : (type === 'warning') ? 'Advertencia' : 'Información',
            text: message,
            timer: timerMs,
            showConfirmButton: false,
            timerProgressBar: true
        });
    }
}

// Función para simular carga de datos
function simulateDataLoad() {
    showNotification('Cargando datos del sistema...', 'info');

    setTimeout(() => {
        showNotification('Datos cargados correctamente ✅', 'success');
    }, 2000);
}
