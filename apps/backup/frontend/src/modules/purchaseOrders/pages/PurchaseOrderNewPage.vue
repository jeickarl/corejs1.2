<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { apiGet, apiPost } from '../../../api/http'

type SupplierDto = { id: number; companyName: string }

type PurchaseOrderDto = { id: number }

function opt(v: string): string | undefined {
  const t = v.trim()
  return t ? t : undefined
}

const router = useRouter()

const suppliers = ref<SupplierDto[]>([])
const supplierId = ref<number | null>(null)
const orderDate = ref(new Date().toISOString().slice(0, 10))
const expectedDate = ref('')
const paymentMethod = ref('')
const paymentTerms = ref('')
const notes = ref('')

const message = ref('')
const saving = ref(false)

const supplierOptions = computed(() => suppliers.value.slice().sort((a, b) => a.companyName.localeCompare(b.companyName)))

async function loadSuppliers() {
  const res = await apiGet<{ items: SupplierDto[]; total: number; page: number; perPage: number }>(
    '/suppliers?onlyActive=1&page=1&perPage=200',
  )
  suppliers.value = res.ok ? res.data.items : []
}

async function save() {
  if (saving.value) return
  if (!supplierId.value) return
  saving.value = true
  message.value = ''

  const body: Record<string, unknown> = {
    supplierId: supplierId.value,
    orderDate: orderDate.value,
  }
  const ex = opt(expectedDate.value)
  if (ex) body.expectedDate = ex
  const pm = opt(paymentMethod.value)
  if (pm) body.paymentMethod = pm
  const pt = opt(paymentTerms.value)
  if (pt) body.paymentTerms = pt
  const nt = opt(notes.value)
  if (nt) body.notes = nt

  const res = await apiPost<PurchaseOrderDto, Record<string, unknown>>('/purchase-orders', body)
  if (!res.ok) {
    message.value = res.error.message
    saving.value = false
    return
  }
  await router.push(`/purchase-orders/${res.data.id}`)
}

onMounted(loadSuppliers)
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div>
        <h5 class="fw-semibold mb-1">Nueva Orden de Compra</h5>
        <div class="text-secondary small">Crea una orden de compra</div>
      </div>
      <button class="btn btn-light border rounded-pill px-4 fw-bold text-muted" type="button" @click="$router.push('/purchase-orders')">
        Cancelar
      </button>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-0">
      {{ message }}
    </div>

    <div class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-medium">Proveedor <span class="text-danger">*</span></label>
            <select v-model="supplierId" class="form-select">
              <option :value="null">Seleccionar</option>
              <option v-for="s in supplierOptions" :key="s.id" :value="s.id">{{ s.companyName }}</option>
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
          <div class="col-md-6">
            <label class="form-label fw-medium">Método de pago</label>
            <input v-model="paymentMethod" class="form-control" placeholder="Ej: Efectivo" />
          </div>
          <div class="col-md-6">
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

