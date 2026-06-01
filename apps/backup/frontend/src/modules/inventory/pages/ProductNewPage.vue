<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { apiPost } from '../../../api/http'

const router = useRouter()

const sku = ref('')
const name = ref('')
const description = ref('')
const salePrice = ref<number>(0)
const costPrice = ref<number>(0)
const currentStock = ref<number>(0)
const minStock = ref<number>(0)
const isActive = ref(true)

const message = ref('')
const saving = ref(false)

async function save() {
  if (saving.value) return
  saving.value = true
  message.value = ''

  const res = await apiPost<{ id: number }, Record<string, unknown>>('/inventory/products', {
    sku: sku.value.trim() || null,
    name: name.value.trim(),
    description: description.value.trim() || null,
    salePrice: Number(salePrice.value) || 0,
    costPrice: Number(costPrice.value) || 0,
    currentStock: Number(currentStock.value) || 0,
    minStock: Number(minStock.value) || 0,
    isActive: Boolean(isActive.value),
  })
  if (!res.ok) {
    message.value = res.error.message
    saving.value = false
    return
  }
  await router.push(`/inventory/products/${res.data.id}`)
}
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div>
        <h5 class="fw-semibold mb-1">Nuevo Producto</h5>
        <div class="text-secondary small">Inventario</div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-light border rounded-pill px-4 fw-bold text-muted" type="button" @click="$router.push('/inventory/products')">
          Cancelar
        </button>
      </div>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-0">{{ message }}</div>

    <div class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label fw-medium">SKU</label>
            <input v-model="sku" class="form-control" />
          </div>
          <div class="col-md-6">
            <label class="form-label fw-medium">Nombre <span class="text-danger">*</span></label>
            <input v-model="name" class="form-control" />
          </div>
          <div class="col-md-3">
            <label class="form-label fw-medium">Activo</label>
            <select v-model="isActive" class="form-select">
              <option :value="true">Sí</option>
              <option :value="false">No</option>
            </select>
          </div>
          <div class="col-md-12">
            <label class="form-label fw-medium">Descripción</label>
            <textarea v-model="description" class="form-control" rows="2"></textarea>
          </div>

          <div class="col-md-3">
            <label class="form-label fw-medium">Precio Venta</label>
            <input v-model.number="salePrice" class="form-control" type="number" min="0" step="0.01" />
          </div>
          <div class="col-md-3">
            <label class="form-label fw-medium">Costo</label>
            <input v-model.number="costPrice" class="form-control" type="number" min="0" step="0.01" />
          </div>
          <div class="col-md-3">
            <label class="form-label fw-medium">Stock Inicial</label>
            <input v-model.number="currentStock" class="form-control" type="number" step="0.01" />
          </div>
          <div class="col-md-3">
            <label class="form-label fw-medium">Stock Mínimo</label>
            <input v-model.number="minStock" class="form-control" type="number" step="0.01" />
          </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mt-4 flex-wrap">
          <button class="btn btn-dark rounded-pill px-4" type="button" :disabled="saving" @click="save">Guardar</button>
        </div>
      </div>
    </div>
  </div>
</template>

