<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiGet, apiDelete } from '../../../api/http'

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
  createdAt: string
  updatedAt: string
}

type Movement = {
  id: number
  productId: number
  movementType: 'in' | 'out' | 'adjust'
  quantity: number
  referenceType: string | null
  referenceId: number | null
  notes: string | null
  createdBy: number | null
  createdAt: string
}

type MovementsPage = { items: Movement[]; page: number; perPage: number; total: number }

const route = useRoute()
const router = useRouter()
const id = computed(() => Number(route.params.id))

const loading = ref(false)
const message = ref('')
const product = ref<Product | null>(null)
const movements = ref<Movement[]>([])

async function load() {
  loading.value = true
  message.value = ''
  const res = await apiGet<Product>(`/inventory/products/${id.value}`)
  if (!res.ok) {
    message.value = res.error.message
    product.value = null
    movements.value = []
    loading.value = false
    return
  }
  product.value = res.data
  const resMov = await apiGet<MovementsPage>(`/inventory/products/${id.value}/movements?page=1&perPage=20`)
  movements.value = resMov.ok ? resMov.data.items : []
  loading.value = false
}

async function remove() {
  if (!product.value) return
  const ok = window.confirm('¿Desactivar este producto?')
  if (!ok) return
  const res = await apiDelete<{ done: true }>(`/inventory/products/${product.value.id}`)
  if (!res.ok) {
    message.value = res.error.message
    return
  }
  await load()
}

onMounted(load)
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div>
        <h5 class="fw-semibold mb-1">Detalle de Producto</h5>
        <div v-if="product" class="text-secondary small">{{ product.name }} · ID {{ product.id }}</div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-light border rounded-pill px-4 fw-bold text-muted" type="button" @click="$router.push('/inventory/products')">
          Volver
        </button>
        <button v-if="product" class="btn btn-outline-secondary rounded-pill px-4 fw-bold" type="button" @click="router.push(`/inventory/products/${product.id}/edit`)">
          Editar
        </button>
        <button v-if="product" class="btn btn-outline-danger rounded-pill px-4 fw-bold" type="button" @click="remove">Desactivar</button>
      </div>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-0">{{ message }}</div>
    <div v-if="loading" class="text-secondary">Cargando...</div>

    <div v-if="product" class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-3">
            <div class="text-secondary small">SKU</div>
            <div class="fw-semibold">{{ product.sku || '-' }}</div>
          </div>
          <div class="col-md-5">
            <div class="text-secondary small">Nombre</div>
            <div class="fw-semibold">{{ product.name }}</div>
          </div>
          <div class="col-md-2">
            <div class="text-secondary small">Activo</div>
            <div class="fw-semibold">{{ product.isActive ? 'Sí' : 'No' }}</div>
          </div>
          <div class="col-md-2">
            <div class="text-secondary small">Stock</div>
            <div class="fw-semibold">
              <span class="badge rounded-pill" :class="product.currentStock <= product.minStock ? 'text-bg-danger' : 'text-bg-success'">
                {{ product.currentStock }}
              </span>
            </div>
          </div>
          <div class="col-md-12">
            <div class="text-secondary small">Descripción</div>
            <div class="fw-semibold">{{ product.description || '-' }}</div>
          </div>
        </div>

        <div class="border-top my-4"></div>

        <h6 class="fw-semibold mb-3">Movimientos recientes</h6>
        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>ID</th>
                <th>Tipo</th>
                <th>Cantidad</th>
                <th>Referencia</th>
                <th>Notas</th>
                <th>Fecha</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="m in movements" :key="m.id">
                <td>{{ m.id }}</td>
                <td class="fw-semibold">{{ m.movementType }}</td>
                <td>{{ m.quantity }}</td>
                <td>{{ m.referenceType }}{{ m.referenceId ? ' #' + m.referenceId : '' }}</td>
                <td>{{ m.notes || '-' }}</td>
                <td>{{ m.createdAt }}</td>
              </tr>
              <tr v-if="movements.length === 0">
                <td colspan="6" class="text-secondary">Sin movimientos</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

