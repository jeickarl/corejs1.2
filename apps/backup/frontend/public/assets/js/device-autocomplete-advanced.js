// Funcionalidad avanzada de autocompletado para dispositivos
class DeviceAutocomplete {
    constructor() {
        console.log('Constructor DeviceAutocomplete llamado');
        this.searchTimeouts = {};
        this.currentBrand = '';
        this.currentDeviceType = '';
        this.init();
    }

    parseJson(response) {
        return (typeof window.parseJsonResponse === 'function') ? window.parseJsonResponse(response) : response.json();
    }

    init() {
        console.log('Inicializando DeviceAutocomplete...');
        this.initBrandAutocomplete();
        this.initModelAutocomplete();
        this.initDeviceTypeAutocomplete(); // Agregar esta línea
        this.setupFormValidation();
        console.log('DeviceAutocomplete inicializado completamente');
    }

    // Autocompletado para marcas
    initBrandAutocomplete() {
        const searchInput = document.getElementById('device_brand_search');
        const hiddenInput = document.getElementById('device_brand');
        const dropdown = document.getElementById('brand_dropdown');

        if (!searchInput || !hiddenInput || !dropdown) return;

        // Establecer valor inicial si existe
        if (hiddenInput.value) {
            searchInput.value = hiddenInput.value;
            this.currentBrand = hiddenInput.value;
        }

        searchInput.addEventListener('input', (e) => {
            const searchTerm = e.target.value.trim();
            
            if (searchTerm === '') {
                hiddenInput.value = '';
                dropdown.style.display = 'none';
                this.currentBrand = '';
                this.clearModelField();
                return;
            }

            // Debounce
            clearTimeout(this.searchTimeouts.brand);
            this.searchTimeouts.brand = setTimeout(() => {
                this.searchBrands(searchTerm);
            }, 300);
        });

        // Ocultar dropdown al hacer clic fuera
        document.addEventListener('click', (e) => {
            if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        });
    }

    searchBrands(searchTerm) {
        const formData = new FormData();
        formData.append('action', 'search_brands');
        formData.append('search', searchTerm);

        fetch('../devices/device_autocomplete_ajax.php', {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body: formData,
            credentials: 'same-origin'
        })
        .then((response) => this.parseJson(response))
        .then(data => {
            if (data.results) {
                this.displayBrandResults(data.results);
            }
        })
        })
        })
        })
        .catch(error => {
            console.error('Error en búsqueda de marcas:', error);
        });
    }

    displayBrandResults(results) {
        const dropdown = document.getElementById('brand_dropdown');
        dropdown.innerHTML = '';

        if (results.length === 0) {
            const noResults = document.createElement('div');
            noResults.className = 'dropdown-item-text text-muted text-center';
            noResults.style.padding = '12px';
            noResults.innerHTML = '<i class="fas fa-search me-2"></i>No se encontraron marcas';
            dropdown.appendChild(noResults);
        } else {
            results.forEach(result => {
                const item = document.createElement('a');
                item.className = 'dropdown-item';
                item.href = '#';
                item.style.cssText = 'padding: 8px 15px; cursor: pointer; border-bottom: 1px solid #f8f9fa;';
                
                if (result.type === 'create') {
                    item.innerHTML = `
                        <div class="d-flex align-items-center">
                            <i class="fas fa-plus-circle text-success me-2"></i>
                            <span class="text-success">${result.display}</span>
                        </div>
                    `;
                } else {
                    item.innerHTML = `
                        <div class="d-flex align-items-center">
                            <i class="fas fa-tag me-2"></i>
                            <span>${result.name}</span>
                        </div>
                    `;
                }

                item.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.selectBrand(result);
                });

                dropdown.appendChild(item);
            });
        }

        dropdown.style.display = 'block';
    }

    selectBrand(brand) {
        const searchInput = document.getElementById('device_brand_search');
        const hiddenInput = document.getElementById('device_brand');
        const dropdown = document.getElementById('brand_dropdown');

        if (brand.type === 'create') {
            this.createBrand(brand.name);
        } else {
            searchInput.value = brand.name;
            hiddenInput.value = brand.name;
            this.currentBrand = brand.name;
            dropdown.style.display = 'none';
            this.clearModelField();
        }
    }

    createBrand(name) {
        const formData = new FormData();
        formData.append('action', 'create_brand');
        formData.append('name', name);

        fetch('../devices/device_autocomplete_ajax.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const searchInput = document.getElementById('device_brand_search');
                const hiddenInput = document.getElementById('device_brand');
                const dropdown = document.getElementById('brand_dropdown');
                
                searchInput.value = data.brand.name;
                hiddenInput.value = data.brand.name;
                this.currentBrand = data.brand.name;
                dropdown.style.display = 'none';
                this.clearModelField();
                
                this.showSuccessMessage(data.message);
            } else {
                this.showErrorMessage(data.error || 'Error al crear la marca');
            }
        })
        .catch(error => {
            console.error('Error al crear marca:', error);
            this.showErrorMessage('Error de conexión al crear la marca');
        });
    }

    // Autocompletado para modelos
    initModelAutocomplete() {
        const searchInput = document.getElementById('device_model_search');
        const hiddenInput = document.getElementById('device_model');
        const dropdown = document.getElementById('model_dropdown');

        if (!searchInput || !hiddenInput || !dropdown) return;

        // Establecer valor inicial si existe
        if (hiddenInput.value) {
            searchInput.value = hiddenInput.value;
        }

        searchInput.addEventListener('input', (e) => {
            const searchTerm = e.target.value.trim();
            
            if (searchTerm === '') {
                hiddenInput.value = '';
                dropdown.style.display = 'none';
                return;
            }

            // Debounce
            clearTimeout(this.searchTimeouts.model);
            this.searchTimeouts.model = setTimeout(() => {
                this.searchModels(searchTerm);
            }, 300);
        });

        // Ocultar dropdown al hacer clic fuera
        document.addEventListener('click', (e) => {
            if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        });
    }

    searchModels(searchTerm) {
        const formData = new FormData();
        formData.append('action', 'search_models');
        formData.append('search', searchTerm);
        formData.append('brand_name', this.currentBrand);

        fetch('../devices/device_autocomplete_ajax.php', {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body: formData,
            credentials: 'same-origin'
        })
        .then((response) => this.parseJson(response))
        .then(data => {
            if (data.results) {
                this.displayModelResults(data.results);
            }
        })
        .catch(error => {
            console.error('Error en búsqueda de modelos:', error);
        });
    }

    displayModelResults(results) {
        const dropdown = document.getElementById('model_dropdown');
        dropdown.innerHTML = '';

        if (results.length === 0) {
            const noResults = document.createElement('div');
            noResults.className = 'dropdown-item-text text-muted text-center';
            noResults.style.padding = '12px';
            noResults.innerHTML = '<i class="fas fa-search me-2"></i>No se encontraron modelos';
            dropdown.appendChild(noResults);
        } else {
            results.forEach(result => {
                const item = document.createElement('a');
                item.className = 'dropdown-item';
                item.href = '#';
                item.style.cssText = 'padding: 8px 15px; cursor: pointer; border-bottom: 1px solid #f8f9fa;';
                
                if (result.type === 'create') {
                    item.innerHTML = `
                        <div class="d-flex align-items-center">
                            <i class="fas fa-plus-circle text-success me-2"></i>
                            <span class="text-success">${result.display}</span>
                        </div>
                    `;
                } else {
                    item.innerHTML = `
                        <div class="d-flex align-items-center">
                            <i class="fas fa-mobile-alt me-2"></i>
                            <span>${result.display || result.name}</span>
                        </div>
                    `;
                }

                item.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.selectModel(result);
                });

                dropdown.appendChild(item);
            });
        }

        dropdown.style.display = 'block';
    }

    selectModel(model) {
        const searchInput = document.getElementById('device_model_search');
        const hiddenInput = document.getElementById('device_model');
        const dropdown = document.getElementById('model_dropdown');

        if (model.type === 'create') {
            this.createModel(model.name);
        } else {
            searchInput.value = model.name;
            hiddenInput.value = model.name;
            dropdown.style.display = 'none';
        }
    }

    createModel(name) {
        const formData = new FormData();
        formData.append('action', 'create_model');
        formData.append('name', name);
        formData.append('brand_name', this.currentBrand);
        formData.append('device_type_name', this.currentDeviceType);

        fetch('../devices/device_autocomplete_ajax.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const searchInput = document.getElementById('device_model_search');
                const hiddenInput = document.getElementById('device_model');
                const dropdown = document.getElementById('model_dropdown');
                
                searchInput.value = data.model.name;
                hiddenInput.value = data.model.name;
                dropdown.style.display = 'none';
                
                this.showSuccessMessage(data.message);
            } else {
                this.showErrorMessage(data.error || 'Error al crear el modelo');
            }
        })
        .catch(error => {
            console.error('Error al crear modelo:', error);
            this.showErrorMessage('Error de conexión al crear el modelo');
        });
    }

    // Autocompletado para tipos de dispositivo
    initDeviceTypeAutocomplete() {
        console.log('🔧 Iniciando initDeviceTypeAutocomplete...');
        
        const searchInput = document.getElementById('device_type_search');
        const hiddenInput = document.getElementById('device_type_id');
        const dropdown = document.getElementById('device_type_dropdown');

        console.log('🔍 Buscando elementos:');
        console.log('- searchInput:', searchInput);
        console.log('- hiddenInput:', hiddenInput);
        console.log('- dropdown:', dropdown);

        if (!searchInput || !hiddenInput || !dropdown) {
            console.log('❌ Elementos de autocompletado de tipo de dispositivo no encontrados');
            return;
        }

        console.log('✅ Elementos encontrados, configurando eventos...');

        // Establecer valor inicial si existe
        if (hiddenInput.value) {
            // Buscar el nombre del tipo de dispositivo seleccionado
            this.setInitialDeviceTypeValue(hiddenInput.value);
        }

        // Función para cargar y mostrar todos los tipos
        const showAllTypes = (e) => {
            if (e) e.stopPropagation();
            console.log('🔍 Cargando todos los tipos de dispositivo');
            
            const formData = new FormData();
            formData.append('action', 'get_all_device_types');
            
            fetch('../devices/device_autocomplete_ajax.php', {
                method: 'POST',
            headers: { 'Accept': 'application/json' },
                body: formData,
                credentials: 'same-origin'
            })
        .then((response) => this.parseJson(response))
            .then(data => {
                if (data.results) {
                    this.displayDeviceTypeResults(data.results);
                }
            })
            .catch(error => {
                console.error('❌ Error al obtener tipos de dispositivo:', error);
            });
        };

        // Mostrar tipos al hacer clic en el input (si está vacío o para ver la lista completa)
        searchInput.addEventListener('click', (e) => {
            if (dropdown.style.display !== 'block') {
                showAllTypes(e);
            }
        });

        // También al hacer clic en la flecha
        const arrowIcon = searchInput.parentNode.querySelector('.fas.fa-chevron-down');
        if (arrowIcon) {
            arrowIcon.addEventListener('click', showAllTypes);
        }

        searchInput.addEventListener('input', (e) => {
            const searchTerm = e.target.value.trim();
            
            if (searchTerm === '') {
                hiddenInput.value = '';
                dropdown.style.display = 'none';
                this.currentDeviceType = '';
                return;
            }

            // Debounce
            clearTimeout(this.searchTimeouts.deviceType);
            this.searchTimeouts.deviceType = setTimeout(() => {
                this.searchDeviceTypes(searchTerm);
            }, 300);
        });

        // Ocultar dropdown al hacer clic fuera
        document.addEventListener('click', (e) => {
            if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        });
    }

    searchDeviceTypes(searchTerm) {
        const formData = new FormData();
        formData.append('action', 'search_device_types');
        formData.append('search', searchTerm);

        fetch('../devices/device_autocomplete_ajax.php', {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body: formData,
            credentials: 'same-origin'
        })
        .then((response) => this.parseJson(response))
        .then(data => {
            if (data.results) {
                this.displayDeviceTypeResults(data.results);
            }
        })
        })
        .catch(error => {
            console.error('Error en búsqueda de tipos de dispositivo:', error);
        });
    }

    displayDeviceTypeResults(results) {
        console.log('📋 Mostrando resultados:', results);
        
        const dropdown = document.getElementById('device_type_dropdown');
        dropdown.innerHTML = '';

        if (results.length === 0) {
            const noResults = document.createElement('div');
            noResults.className = 'dropdown-item-text text-muted text-center';
            noResults.style.padding = '12px';
            noResults.innerHTML = '<i class="fas fa-search me-2"></i>No se encontraron tipos';
            dropdown.appendChild(noResults);
        } else {
            results.forEach((result, index) => {
                console.log(`📋 Procesando resultado ${index}:`, result);
                
                const item = document.createElement('a');
                item.className = 'dropdown-item';
                item.href = '#';
                item.style.cssText = 'padding: 8px 15px; cursor: pointer; border-bottom: 1px solid #f8f9fa;';
                
                if (result.type === 'create') {
                    item.innerHTML = `
                        <div class="d-flex align-items-center">
                            <i class="fas fa-plus-circle text-success me-2"></i>
                            <span class="text-success">Crear: ${result.name}</span>
                        </div>
                    `;
                } else {
                    item.innerHTML = `
                        <div class="d-flex align-items-center">
                            <span>${result.name || 'Sin nombre'}</span>
                        </div>
                    `;
                }

                item.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.selectDeviceType(result);
                });

                dropdown.appendChild(item);
            });
        }

        dropdown.style.display = 'block';
    }

    selectDeviceType(deviceType) {
        const searchInput = document.getElementById('device_type_search');
        const hiddenInput = document.getElementById('device_type_id');
        const dropdown = document.getElementById('device_type_dropdown');

        if (deviceType.type === 'create') {
            this.createDeviceType(deviceType.name);
        } else {
            searchInput.value = deviceType.name;
            hiddenInput.value = deviceType.id;
            this.currentDeviceType = deviceType.name;
            dropdown.style.display = 'none';
            
            // Debug: Log para verificar qué tipo se seleccionó
            console.log('Tipo de dispositivo seleccionado:', deviceType.name);
            console.log('ID del tipo:', deviceType.id);
            
            // Generar número de serie aleatorio si se selecciona "motherboard"
            if (deviceType.name.toLowerCase() === 'motherboard' || 
                deviceType.name.toLowerCase() === 'placa madre' ||
                deviceType.name.toLowerCase() === 'tarjeta madre' ||
                deviceType.name.toLowerCase().includes('motherboard') ||
                deviceType.name.toLowerCase().includes('placa') ||
                deviceType.name.toLowerCase().includes('tarjeta')) {
                console.log('Generando número de serie para motherboard...');
                this.generateRandomSerialNumber();
            } else {
                console.log('No es motherboard, no se genera número de serie');
            }
        }
    }

    // Establecer valor inicial del tipo de dispositivo
    setInitialDeviceTypeValue(deviceTypeId) {
        const formData = new FormData();
        formData.append('action', 'get_device_type_name');
        formData.append('id', deviceTypeId);

        fetch('../devices/device_autocomplete_ajax.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const searchInput = document.getElementById('device_type_search');
                searchInput.value = data.name;
                this.currentDeviceType = data.name;
            }
        })
        .catch(error => {
            console.error('Error al obtener nombre del tipo de dispositivo:', error);
        });
    }

    createDeviceType(name) {
        const formData = new FormData();
        formData.append('action', 'create_device_type');
        formData.append('name', name);

        fetch('../devices/device_autocomplete_ajax.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const searchInput = document.getElementById('device_type_search');
                const hiddenInput = document.getElementById('device_type_id');
                const dropdown = document.getElementById('device_type_dropdown');
                
                searchInput.value = data.device_type.name;
                hiddenInput.value = data.device_type.id;
                this.currentDeviceType = data.device_type.name;
                dropdown.style.display = 'none';
                
                this.showSuccessMessage(data.message);
            } else {
                this.showErrorMessage(data.error || 'Error al crear el tipo de dispositivo');
            }
        })
        .catch(error => {
            console.error('Error al crear tipo de dispositivo:', error);
            this.showErrorMessage('Error de conexión al crear el tipo de dispositivo');
        });
    }

    // Generar número de serie aleatorio para motherboards
    generateRandomSerialNumber() {
        console.log('Iniciando generación de número de serie...');
        
        const serialNumberInput = document.getElementById('serial_number');
        if (!serialNumberInput) {
            console.error('No se encontró el campo serial_number');
            return;
        }
        
        console.log('Campo serial_number encontrado');
        
        // Generar número aleatorio de 7 dígitos
        const randomNumber = Math.floor(1000000 + Math.random() * 9000000);
        
        // Formato: MB-XXXXXXX (MB = Motherboard)
        const serialNumber = `MB-${randomNumber}`;
        
        console.log('Número de serie generado:', serialNumber);
        
        // Asignar al campo de número de serie
        serialNumberInput.value = serialNumber;
        
        console.log('Valor asignado al campo:', serialNumberInput.value);
        
        // Mostrar mensaje informativo
        this.showInfoMessage('Número de serie generado automáticamente para placa madre. Puedes cambiarlo si consigues el serial original.');
        
        // Agregar clase visual para indicar que es un número generado
        serialNumberInput.classList.add('generated-serial');
        serialNumberInput.style.backgroundColor = '#f8f9fa';
        serialNumberInput.style.borderColor = '#28a745';
        
        console.log('Estilos aplicados al campo');
        
        // Remover la clase después de 3 segundos
        setTimeout(() => {
            serialNumberInput.classList.remove('generated-serial');
            serialNumberInput.style.backgroundColor = '';
            serialNumberInput.style.borderColor = '';
            console.log('Estilos removidos del campo');
        }, 3000);
    }

    // Métodos auxiliares
    clearModelField() {
        const modelSearchInput = document.getElementById('device_model_search');
        const modelHiddenInput = document.getElementById('device_model');
        const modelDropdown = document.getElementById('model_dropdown');
        
        if (modelSearchInput) modelSearchInput.value = '';
        if (modelHiddenInput) modelHiddenInput.value = '';
        if (modelDropdown) modelDropdown.style.display = 'none';
    }

    setupFormValidation() {
        const form = document.getElementById('orderForm');
        if (!form) return;

        form.addEventListener('submit', (e) => {
            // Validar que los campos ocultos tengan valores si los campos visibles tienen texto
            const brandSearch = document.getElementById('device_brand_search');
            const brandHidden = document.getElementById('device_brand');
            const modelSearch = document.getElementById('device_model_search');
            const modelHidden = document.getElementById('device_model');
            const typeSearch = document.getElementById('device_type_search');
            const typeHidden = document.getElementById('device_type_id');

            // Si hay texto en el campo de búsqueda pero no hay valor en el campo oculto,
            // usar el texto como valor
            if (brandSearch && brandHidden && brandSearch.value && !brandHidden.value) {
                brandHidden.value = brandSearch.value;
            }
            if (modelSearch && modelHidden && modelSearch.value && !modelHidden.value) {
                modelHidden.value = modelSearch.value;
            }
            if (typeSearch && typeHidden && typeSearch.value && !typeHidden.value) {
                // Para tipos de dispositivo, si no hay ID, no podemos simplemente usar el texto
                // porque el backend espera un ID entero. 
                // Sin embargo, si es un caso de creación al vuelo (si se implementara), 
                // esto podría necesitar lógica adicional.
                // Por ahora, solo aseguramos que no rompa el JS.
                console.warn('Tipo de dispositivo tiene texto pero no ID seleccionado');
            }
        });
    }

    showSuccessMessage(message) {
        // Crear un toast o alerta temporal
        const alert = document.createElement('div');
        alert.className = 'alert alert-success alert-dismissible fade show position-fixed';
        alert.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        alert.innerHTML = `
            <i class="fas fa-check-circle me-2"></i>${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(alert);
        
        // Auto-remover después de 3 segundos
        setTimeout(() => {
            if (alert.parentNode) {
                alert.parentNode.removeChild(alert);
            }
        }, 3000);
    }

    showErrorMessage(message) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: message,
                timer: 6000,
                showConfirmButton: false,
                timerProgressBar: true
            });
        }
    }

    showInfoMessage(message) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'info',
                title: 'Información',
                text: message,
                timer: 1500,
                showConfirmButton: false,
                timerProgressBar: true
            });
        }
    }
}

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 DOM cargado, inicializando DeviceAutocomplete...');
    try {
        const autocomplete = new DeviceAutocomplete();
        console.log('✅ DeviceAutocomplete inicializado correctamente');
        
        // Verificar que los elementos existen
        const searchInput = document.getElementById('device_type_search');
        const hiddenInput = document.getElementById('device_type_id');
        const dropdown = document.getElementById('device_type_dropdown');
        
        console.log('🔍 Verificando elementos:');
        console.log('- device_type_search:', searchInput ? '✅ Encontrado' : '❌ No encontrado');
        console.log('- device_type_id:', hiddenInput ? '✅ Encontrado' : '❌ No encontrado');
        console.log('- device_type_dropdown:', dropdown ? '✅ Encontrado' : '❌ No encontrado');
        
    } catch (error) {
        console.error('❌ Error al inicializar DeviceAutocomplete:', error);
    }
});
