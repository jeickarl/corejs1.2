document.addEventListener('DOMContentLoaded', function() {
    console.log('Settings Tabs Script Loaded');

    // Helper to force visibility
    function forceShow(pane) {
        if (!pane) return;
        pane.classList.add('active', 'show');
        pane.style.display = 'block';
        pane.style.opacity = '1';
        
        // Resize iframes if any
        const iframes = pane.querySelectorAll('iframe');
        iframes.forEach(iframe => {
            if (iframe.contentWindow && iframe.contentWindow.document) {
                try {
                    const height = iframe.contentWindow.document.documentElement.scrollHeight;
                    if (height > 100) iframe.style.height = height + 'px';
                } catch(e) {}
            }
            if (iframe.offsetHeight < 600) iframe.style.height = '700px';
        });
    }

    function forceHide(pane) {
        if (!pane) return;
        pane.classList.remove('active', 'show');
        pane.style.display = 'none';
        pane.style.opacity = '0';
    }

    // Main Tab Switching Logic
    const triggerTabList = document.querySelectorAll('[data-bs-toggle="tab"], [data-bs-toggle="pill"]');
    triggerTabList.forEach(triggerEl => {
        triggerEl.addEventListener('click', event => {
            event.preventDefault();
            
            const targetSelector = triggerEl.getAttribute('data-bs-target');
            if (!targetSelector) return;
            const targetPane = document.querySelector(targetSelector);
            if (!targetPane) return;

            // 1. Update Buttons State
            const navList = triggerEl.closest('.nav');
            if (navList) {
                navList.querySelectorAll('.nav-link').forEach(btn => {
                    btn.classList.remove('active');
                    btn.setAttribute('aria-selected', 'false');
                });
            }
            triggerEl.classList.add('active');
            triggerEl.setAttribute('aria-selected', 'true');

            // 2. Update Panes State
            // Only hide siblings in the same tab-content container
            const tabContent = targetPane.closest('.tab-content');
            if (tabContent) {
                Array.from(tabContent.children).forEach(child => {
                    if (child.classList.contains('tab-pane') && child !== targetPane) {
                        forceHide(child);
                    }
                });
            }
            
            // 3. Show Target
            forceShow(targetPane);

            // 4. Update URL Hash (only for top-level tabs)
            if (navList && navList.id === 'configTabs') {
                const tabId = triggerEl.getAttribute('id');
                if (tabId) {
                    history.replaceState(null, null, 'settings.php#' + tabId.replace('-tab', ''));
                }
            }
        });

        // Listen for Bootstrap native events as backup
        triggerEl.addEventListener('shown.bs.tab', function (event) {
            const targetSelector = event.target.getAttribute('data-bs-target');
            const targetPane = document.querySelector(targetSelector);
            forceShow(targetPane);
        });
    });

    // Handle Hash on Load
    const hash = window.location.hash.replace('#', '');
    if (hash) {
        const targetBtn = document.getElementById(hash + '-tab');
        if (targetBtn) {
            targetBtn.click();
        } else {
            // Default to company if hash invalid
            const defaultBtn = document.getElementById('company-tab');
            if (defaultBtn) defaultBtn.click();
        }
    } else {
         // Default to company
         const defaultBtn = document.getElementById('company-tab');
         if (defaultBtn) defaultBtn.click();
    }
});

// Global Resize Function for Iframes
window.resizeIframe = function(obj) {
    try {
        // Initial resize
        if (obj.contentWindow.document.documentElement.scrollHeight > 100) {
            obj.style.height = obj.contentWindow.document.documentElement.scrollHeight + 'px';
        } else {
             obj.style.height = '800px';
        }

        // Add Observer if supported
        if (window.ResizeObserver && obj.contentWindow.document.body) {
            const resizeObserver = new ResizeObserver(() => {
                if (obj.contentWindow.document.documentElement.scrollHeight > 100) {
                    obj.style.height = obj.contentWindow.document.documentElement.scrollHeight + 'px';
                }
            });
            resizeObserver.observe(obj.contentWindow.document.body);
        }
    } catch (e) {
        console.log('Error resizing iframe (likely cross-origin):', e);
        obj.style.height = '800px';
    }
};
