
function exportClients() {
    const format = document.getElementById('export_format')?.value || 'csv';
    
    // Check checkboxes
    const fields = [];
    if(document.getElementById('export_name')?.checked) fields.push('name');
    if(document.getElementById('export_phone')?.checked) fields.push('phone');
    if(document.getElementById('export_email')?.checked) fields.push('email');
    if(document.getElementById('export_address')?.checked) fields.push('address');
    if(document.getElementById('export_id')?.checked) fields.push('identification');
    if(document.getElementById('export_dates')?.checked) fields.push('dates');
    
    if (fields.length === 0) {
        showNotification('warning', 'Atención', 'Selecciona al menos un campo para exportar');
        return;
    }

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'clients_data_operations.php';
    form.target = '_blank'; // Download in new tab/window usually triggers download and closes, or just downloads
    
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'export';
    form.appendChild(actionInput);
    
    const formatInput = document.createElement('input');
    formatInput.type = 'hidden';
    formatInput.name = 'format';
    formatInput.value = format;
    form.appendChild(formatInput);
    
    fields.forEach(f => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'fields[]';
        input.value = f;
        form.appendChild(input);
    });
    
    document.body.appendChild(form);
    form.submit();
    setTimeout(() => document.body.removeChild(form), 1000);
}

function importClients() {
    const fileInput = document.createElement('input');
    fileInput.type = 'file';
    fileInput.accept = '.csv, .xlsx, .xls';
    
    fileInput.onchange = function(e) {
        const file = e.target.files[0];
        if(!file) return;
        
        const formData = new FormData();
        formData.append('action', 'import');
        formData.append('file', file);
        formData.append('update_existing', '1');
        
        showNotification('info', 'Importando...', 'Por favor espere mientras se procesa el archivo');
        
        fetch('clients_data_operations.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if(data.success) {
                let msg = `Importados: ${data.imported}, Actualizados: ${data.updated}`;
                if (data.skipped > 0) msg += `, Omitidos: ${data.skipped}`;
                if (data.errors > 0) msg += `, Errores: ${data.errors}`;
                
                showNotification('success', 'Importación Completada', msg);
            } else {
                showNotification('error', 'Error', data.message || 'Error en la importación');
            }
        })
        .catch(err => {
            console.error(err);
            showNotification('error', 'Error', 'Error de conexión o respuesta inválida del servidor');
        });
    };
    
    fileInput.click();
}
