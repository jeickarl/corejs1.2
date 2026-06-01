// Autocompletado para campos de dispositivos
document.addEventListener('DOMContentLoaded', function() {
    // Configuración general
    const DEBOUNCE_DELAY = 300;
    const MAX_RESULTS = 10;
    
    // Función para crear dropdown de resultados
    function createDropdown(input) {
        const dropdown = document.createElement('div');
        dropdown.className = 'autocomplete-dropdown';
        dropdown.style.cssText = `
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-top: none;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        `;
        
        input.parentNode.style.position = 'relative';
        input.parentNode.appendChild(dropdown);
        return dropdown;
    }
    
    // Función para crear item de resultado
    function createResultItem(result, input, dropdown) {
        const item = document.createElement('div');
        item.className = 'autocomplete-item';
        item.style.cssText = `
            padding: 8px 12px;
            cursor: pointer;
            border-bottom: 1px solid #eee;
        `;
        item.textContent = result.label;
        
        item.addEventListener('mouseenter', function() {
            this.style.backgroundColor = '#f8f9fa';
        });
        
        item.addEventListener('mouseleave', function() {
            this.style.backgroundColor = 'white';
        });
        
        item.addEventListener('click', function() {
            input.value = result.value;
            dropdown.style.display = 'none';
            input.focus();
        });
        
        return item;
    }
    
    // Función para realizar búsqueda AJAX
    function performSearch(query, type, callback) {
        if (query.length < 2) {
            callback([]);
            return;
        }
        
        const formData = new FormData();
        formData.append('action', type);
        formData.append('search', query);
        
        fetch('../devices/search_ajax.php', {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body: formData
        })
        .then(function(response){ return (typeof window.parseJsonResponse === 'function') ? window.parseJsonResponse(response) : response.json(); })
        .then(data => {
            callback(data.results || []);
        })
        .catch(error => {
            console.error('Error en búsqueda:', error);
            callback([]);
        });
    }
    
    // Función para configurar autocompletado
    function setupAutocomplete(input, searchType) {
        const dropdown = createDropdown(input);
        let debounceTimer;
        
        input.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            const query = this.value.trim();
            
            if (query.length < 2) {
                dropdown.style.display = 'none';
                return;
            }
            
            debounceTimer = setTimeout(() => {
                performSearch(query, searchType, function(results) {
                    dropdown.innerHTML = '';
                    
                    if (results.length === 0) {
                        dropdown.style.display = 'none';
                        return;
                    }
                    
                    results.slice(0, MAX_RESULTS).forEach(result => {
                        const item = createResultItem(result, input, dropdown);
                        dropdown.appendChild(item);
                    });
                    
                    dropdown.style.display = 'block';
                });
            }, DEBOUNCE_DELAY);
        });
        
        // Cerrar dropdown al hacer clic fuera
        document.addEventListener('click', function(e) {
            if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        });
        
        // Navegación con teclado
        input.addEventListener('keydown', function(e) {
            const items = dropdown.querySelectorAll('.autocomplete-item');
            let selectedIndex = -1;
            
            // Encontrar item seleccionado actual
            items.forEach((item, index) => {
                if (item.style.backgroundColor === 'rgb(248, 249, 250)') {
                    selectedIndex = index;
                }
            });
            
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                selectedIndex = Math.max(selectedIndex - 1, -1);
            } else if (e.key === 'Enter' && selectedIndex >= 0) {
                e.preventDefault();
                items[selectedIndex].click();
                return;
            } else if (e.key === 'Escape') {
                dropdown.style.display = 'none';
                return;
            }
            
            // Actualizar selección visual
            items.forEach((item, index) => {
                item.style.backgroundColor = index === selectedIndex ? '#f8f9fa' : 'white';
            });
        });
    }
    
    // Configurar autocompletado para marcas
    const brandInput = document.getElementById('brand');
    if (brandInput && brandInput.tagName === 'INPUT') {
        setupAutocomplete(brandInput, 'search_brands');
    }
    
    // Configurar autocompletado para tipos de dispositivo
    const deviceTypeInput = document.getElementById('device_type');
    if (deviceTypeInput && deviceTypeInput.tagName === 'INPUT') {
        setupAutocomplete(deviceTypeInput, 'search_device_types');
    }
    
    // Funcionalidad especial para modelos (filtrado por marca)
    const modelInput = document.getElementById('model');
    if (modelInput) {
        const modelDropdown = createDropdown(modelInput);
        let modelDebounceTimer;
        
        modelInput.addEventListener('input', function() {
            clearTimeout(modelDebounceTimer);
            const query = this.value.trim();
            const selectedBrand = document.getElementById('brand')?.value;
            
            if (query.length < 2) {
                modelDropdown.style.display = 'none';
                return;
            }
            
            modelDebounceTimer = setTimeout(() => {
                const formData = new FormData();
                formData.append('action', 'search_models');
                formData.append('search', query);
                if (selectedBrand) {
                    formData.append('brand', selectedBrand);
                }
                
                fetch('../devices/search_ajax.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    modelDropdown.innerHTML = '';
                    
                    if (!data.results || data.results.length === 0) {
                        modelDropdown.style.display = 'none';
                        return;
                    }
                    
                    data.results.slice(0, MAX_RESULTS).forEach(result => {
                        const item = createResultItem(result, modelInput, modelDropdown);
                        modelDropdown.appendChild(item);
                    });
                    
                    modelDropdown.style.display = 'block';
                })
                .catch(error => {
                    console.error('Error en búsqueda de modelos:', error);
                    modelDropdown.style.display = 'none';
                });
            }, DEBOUNCE_DELAY);
        });
        
        // Cerrar dropdown al hacer clic fuera
        document.addEventListener('click', function(e) {
            if (!modelInput.contains(e.target) && !modelDropdown.contains(e.target)) {
                modelDropdown.style.display = 'none';
            }
        });
    }
    
    // Limpiar modelos cuando cambie la marca
    if (brandInput) {
        brandInput.addEventListener('change', function() {
            if (modelInput) {
                modelInput.value = '';
            }
        });
    }
});
