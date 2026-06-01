<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiDelete, apiGet } from '../../../api/http'

type PurchaseOrderDto = {
  id: number
  poNumber: string
  supplierId: number
  supplierName: string
  orderDate: string
  expectedDate: string | null
  paymentMethod: string
  paymentTerms: string
  notes: string
  totalAmount: number
  paymentStatus: string
  status: string
  createdAt: string
  updatedAt: string
}

const route = useRoute()
const router = useRouter()
const id = computed(() => Number(route.params.id))

const loading = ref(false)
const busy = ref(false)
const message = ref('')
const po = ref<PurchaseOrderDto | null>(null)

async function load() {
  loading.value = true
  message.value = ''
  const res = await apiGet<PurchaseOrderDto>(`/purchase-orders/${id.value}`)
  po.value = res.ok ? res.data : null
  if (!res.ok) message.value = res.error.message
  loading.value = false
}

async function cancel() {
  if (!po.value) return
  const ok = window.confirm(`¿Cancelar orden "${po.value.poNumber}"?`)
  if (!ok) return
  busy.value = true
  message.value = ''
  const res = await apiDelete<{ done: true }>(`/purchase-orders/${po.value.id}`)
  if (!res.ok) {
    message.value = res.error.message
    busy.value = false
    return
  }
  busy.value = false
  await router.push('/purchase-orders')
}

onMounted(load)
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div>
        <h5 class="fw-semibold mb-1">Detalle Orden de Compra</h5>
        <div v-if="po" class="text-secondary small">{{ po.poNumber }} · {{ po.supplierName }}</div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-light border rounded-pill px-4 fw-bold text-muted" type="button" @click="$router.push('/purchase-orders')">
          Volver
        </button>
        <button v-if="po" class="btn btn-outline-dark rounded-pill px-4 fw-bold" type="button" :disabled="busy" @click="$router.push(`/purchase-orders/${po.id}/edit`)">
          Editar
        </button>
        <button v-if="po" class="btn btn-outline-danger rounded-pill px-4 fw-bold" type="button" :disabled="busy" @click="cancel">
          Cancelar
        </button>
      </div>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-0">{{ message }}</div>
    <div v-if="loading" class="text-secondary">Cargando...</div>

    <div v-if="po" class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-4">
            <div class="text-secondary small">PO</div>
            <div class="fw-semibold">{{ po.poNumber }}</div>
          </div>
          <div class="col-md-8">
            <div class="text-secondary small">Proveedor</div>
            <div class="fw-semibold">{{ po.supplierName }}</div>
          </div>
          <div class="col-md-4">
            <div class="text-secondary small">Fecha</div>
            <div class="fw-semibold">{{ po.orderDate }}</div>
          </div>
          <div class="col-md-4">
            <div class="text-secondary small">Esperada</div>
            <div class="fw-semibold">{{ po.expectedDate || '-' }}</div>
          </div>
          <div class="col-md-4">
            <div class="text-secondary small">Estado</div>
            <div class="fw-semibold">{{ po.status }}</div>
          </div>
          <div class="col-md-4">
            <div class="text-secondary small">Pago</div>
            <div class="fw-semibold">{{ po.paymentStatus }}</div>
          </div>
          <div class="col-md-4">
            <div class="text-secondary small">Total</div>
            <div class="fw-semibold">{{ po.totalAmount }}</div>
          </div>
          <div class="col-md-4">
            <div class="text-secondary small">Método</div>
            <div class="fw-semibold">{{ po.paymentMethod || '-' }}</div>
          </div>
          <div class="col-md-12">
            <div class="text-secondary small">Notas</div>
            <div class="fw-semibold">{{ po.notes || '-' }}</div>
          </div>
          <div class="col-md-6">
            <div class="text-secondary small">Creado</div>
            <div class="fw-semibold">{{ po.createdAt }}</div>
          </div>
          <div class="col-md-6">
            <div class="text-secondary small">Actualizado</div>
            <div class="fw-semibold">{{ po.updatedAt }}</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

