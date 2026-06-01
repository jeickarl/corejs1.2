<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiGet, apiPatch, apiPost } from '../../../api/http'

type Product = {
  id: number
  sku: string
  name: string
  description: string
  salePrice: number
  costPrice: number
  currentStock: number
  minStock: number
  isActive: boolean
}

const route = useRoute()
const router = useRouter()
const id = computed(() => Number(route.params.id))

const loading = ref(false)
const busy = ref(false)
const message = ref('')
const product = ref<Product | null>(null)

const sku = ref('')
const name = ref('')
const description = ref('')
const salePrice = ref<number>(0)
const costPrice = ref<number>(0)
const minStock = ref<number>(0)
const isActive = ref(true)

const moveType = ref<'in' | 'out' | 'adjust'>('in')
const moveQty = ref<number>(0)
const moveNotes = ref('')

async function load() {
  loading.value = true
  message.value = ''
  const res = await apiGet<Product>(`/inventory/products/${id.value}`)
  if (!res.ok) {
    message.value = res.error.message
    product.value = null
    loading.value = false
    return
  }
  product.value = res.data
  sku.value = res.data.sku ?? ''
  name.value = res.data.name ?? ''
  description.value = res.data.description ?? ''
  salePrice.value = Number(res.data.salePrice ?? 0)
  costPrice.value = Number(res.data.costPrice ?? 0)
  minStock.value = Number(res.data.minStock ?? 0)
  isActive.value = Boolean(res.data.isActive)
  loading.value = false
}

async function save() {
  if (!product.value || busy.value) return
  busy.value = true
  message.value = ''
  const res = await apiPatch<{ done: true }, Record<string, unknown>>(`/inventory/products/${product.value.id}`, {
    sku: sku.value.trim() || null,
    name: name.value.trim(),
    description: description.value.trim() || null,
    salePrice: Number(salePrice.value) || 0,
    costPrice: Number(costPrice.value) || 0,
    minStock: Number(minStock.value) || 0,
    isActive: Boolean(isActive.value),
  })
  if (!res.ok) {
    message.value = res.error.message
    busy.value = false
    return
  }
  busy.value = false
  await router.push(`/inventory/products/${product.value.id}`)
}

async function adjustStock() {
  if (!product.value || busy.value) return
  const qty = Number(moveQty.value)
  if (!Number.isFinite(qty) || qty <= 0) return
  busy.value = true
  message.value = ''
  const res = await apiPost<{ movementId: number; newStock: number }, Record<string, unknown>>('/inventory/movements', {
    productId: product.value.id,
    movementType: moveType.value,
    quantity: qty,
    notes: moveNotes.value.trim() || null,
  })
  if (!res.ok) {
    message.value = res.error.message
    busy.value = false
    return
  }
  moveQty.value = 0
  moveNotes.value = ''
  busy.value = false
  await load()
}

onMounted(load)
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div>
        <h5 class="fw-semibold mb-1">Editar Producto</h5>
        <div v-if="product" class="text-secondary small">{{ product.name }} · Stock: {{ product.currentStock }}</div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-light border rounded-pill px-4 fw-bold text-muted" type="button" @click="$router.push(`/inventory/products/${id}`)">
          Cancelar
        </button>
      </div>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-0">{{ message }}</div>
    <div v-if="loading" class="text-secondary">Cargando...</div>

    <div v-if="product" class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label fw-medium">SKU</label>
            <input v-model="sku" class="form-control" />
          </div>
          <div class="col-md-6">
            <label class="form-label fw-medium">Nombre</label>
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
            <label class="form-label fw-medium">Stock Mínimo</label>
            <input v-model.number="minStock" class="form-control" type="number" step="0.01" />
          </div>
          <div class="col-md-3">
            <label class="form-label fw-medium">Stock Actual</label>
            <input :value="product.currentStock" class="form-control" disabled />
          </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mt-4 flex-wrap">
          <button class="btn btn-dark rounded-pill px-4" type="button" :disabled="busy" @click="save">Guardar</button>
        </div>

        <div class="border-top my-4"></div>

        <h6 class="fw-semibold mb-3">Ajuste de Stock</h6>
        <div class="row g-3 align-items-end">
          <div class="col-md-3">
            <label class="form-label fw-medium">Tipo</label>
            <select v-model="moveType" class="form-select">
              <option value="in">Entrada</option>
              <option value="out">Salida</option>
              <option value="adjust">Ajustar (set)</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label fw-medium">Cantidad</label>
            <input v-model.number="moveQty" class="form-control" type="number" step="0.01" />
          </div>
          <div class="col-md-4">
            <label class="form-label fw-medium">Notas</label>
            <input v-model="moveNotes" class="form-control" />
          </div>
          <div class="col-md-2 text-end">
            <button class="btn btn-outline-dark rounded-pill w-100" type="button" :disabled="busy" @click="adjustStock">
              Aplicar
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

