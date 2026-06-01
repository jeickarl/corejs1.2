<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiGet, apiPatch } from '../../../api/http'

type SupplierDto = { id: number; companyName: string }

type PurchaseOrderDto = {
  id: number
  supplierId: number
  supplierName: string
  poNumber: string
  orderDate: string
  expectedDate: string | null
  paymentMethod: string
  paymentTerms: string
  notes: string
  status: string
}

function opt(v: string): string | undefined {
  const t = v.trim()
  return t ? t : undefined
}

const route = useRoute()
const router = useRouter()
const id = computed(() => Number(route.params.id))

const loading = ref(false)
const saving = ref(false)
const message = ref('')

const suppliers = ref<SupplierDto[]>([])
const supplierId = ref<number | null>(null)
const orderDate = ref('')
const expectedDate = ref('')
const paymentMethod = ref('')
const paymentTerms = ref('')
const notes = ref('')
const status = ref('draft')
const poNumber = ref('')

async function loadSuppliers() {
  const res = await apiGet<{ items: SupplierDto[]; total: number; page: number; perPage: number }>(
    '/suppliers?onlyActive=1&page=1&perPage=200',
  )
  suppliers.value = res.ok ? res.data.items : []
}

async function load() {
  loading.value = true
  message.value = ''
  const res = await apiGet<PurchaseOrderDto>(`/purchase-orders/${id.value}`)
  if (!res.ok) {
    message.value = res.error.message
    loading.value = false
    return
  }
  supplierId.value = res.data.supplierId
  orderDate.value = res.data.orderDate
  expectedDate.value = res.data.expectedDate ?? ''
  paymentMethod.value = res.data.paymentMethod ?? ''
  paymentTerms.value = res.data.paymentTerms ?? ''
  notes.value = res.data.notes ?? ''
  status.value = res.data.status ?? 'draft'
  poNumber.value = res.data.poNumber ?? ''
  loading.value = false
}

async function save() {
  if (saving.value) return
  if (!supplierId.value) return
  saving.value = true
  message.value = ''

  const body: Record<string, unknown> = {
    supplierId: supplierId.value,
    orderDate: orderDate.value,
    status: status.value,
  }
  const ex = opt(expectedDate.value)
  if (ex) body.expectedDate = ex
  const pm = opt(paymentMethod.value)
  if (pm) body.paymentMethod = pm
  const pt = opt(paymentTerms.value)
  if (pt) body.paymentTerms = pt
  const nt = opt(notes.value)
  if (nt) body.notes = nt

  const res = await apiPatch<{ done: true }, Record<string, unknown>>(`/purchase-orders/${id.value}`, body)
  if (!res.ok) {
    message.value = res.error.message
    saving.value = false
    return
  }
  await router.push(`/purchase-orders/${id.value}`)
}

onMounted(async () => {
  await loadSuppliers()
  await load()
})
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div>
        <h5 class="fw-semibold mb-1">Editar Orden de Compra</h5>
        <div class="text-secondary small">{{ poNumber }}</div>
      </div>
      <button class="btn btn-light border rounded-pill px-4 fw-bold text-muted" type="button" @click="$router.push(`/purchase-orders/${id}`)">
        Cancelar
      </button>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-0">
      {{ message }}
    </div>
    <div v-if="loading" class="text-secondary">Cargando...</div>

    <div class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-medium">Proveedor <span class="text-danger">*</span></label>
            <select v-model="supplierId" class="form-select">
              <option :value="null">Seleccionar</option>
              <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.companyName }}</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label fw-medium">Fecha orden <span class="text-danger">*</span></label>
            <input v-model="orderDate" class="form-control" type="date" />
          </div>
          <div class="col-md-3">
            <label class="form-label fw-medium">Fecha esperada</label>
            <input v-model="expectedDate" class="form-control" type="date" />
          </div>
          <div class="col-md-4">
            <label class="form-label fw-medium">Estado</label>
            <select v-model="status" class="form-select">
              <option value="draft">draft</option>
              <option value="sent">sent</option>
              <option value="received">received</option>
              <option value="cancelled">cancelled</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-medium">Método de pago</label>
            <input v-model="paymentMethod" class="form-control" placeholder="Ej: Efectivo" />
          </div>
          <div class="col-md-4">
            <label class="form-label fw-medium">Términos de pago</label>
            <input v-model="paymentTerms" class="form-control" placeholder="Ej: 30_days" />
          </div>
          <div class="col-md-12">
            <label class="form-label fw-medium">Notas</label>
            <textarea v-model="notes" class="form-control" rows="3"></textarea>
          </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mt-3">
          <button class="btn btn-dark rounded-pill px-4" type="button" :disabled="saving || !supplierId" @click="save">
            Guardar
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

