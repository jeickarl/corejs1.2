<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { apiGet, apiPost } from '../../../api/http'

type PurchaseOrderDto = { id: number; poNumber: string; supplierId: number; supplierName: string; status: string }
type InventoryProductDto = { id: number; name: string; sku: string }

type CreateItem = { productId: number | null; quantity: number | null; unitCost: number | null }

const router = useRouter()
const message = ref('')
const saving = ref(false)

const purchaseOrders = ref<PurchaseOrderDto[]>([])
const products = ref<InventoryProductDto[]>([])

const purchaseOrderId = ref<number | null>(null)
const receivedDate = ref(new Date().toISOString().slice(0, 10))
const notes = ref('')

const items = ref<CreateItem[]>([{ productId: null, quantity: 1, unitCost: 0 }])

const selectedOrder = computed(() => purchaseOrders.value.find((x) => x.id === purchaseOrderId.value) ?? null)

const total = computed(() => {
  return items.value.reduce((acc, it) => {
    const q = Number(it.quantity ?? 0)
    const u = Number(it.unitCost ?? 0)
    if (!Number.isFinite(q) || !Number.isFinite(u)) return acc
    return acc + q * u
  }, 0)
})

async function loadData() {
  const [o, p] = await Promise.all([
    apiGet<{ items: PurchaseOrderDto[]; page: number; perPage: number; total: number }>('/purchase-orders?page=1&perPage=200'),
    apiGet<{ items: InventoryProductDto[]; page: number; perPage: number; total: number }>('/inventory/products?onlyActive=1&page=1&perPage=200'),
  ])
  purchaseOrders.value = o.ok ? o.data.items.filter((x) => x.status !== 'cancelled') : []
  products.value = p.ok ? p.data.items : []
}

function addRow() {
  items.value.push({ productId: null, quantity: 1, unitCost: 0 })
}

function removeRow(i: number) {
  items.value.splice(i, 1)
  if (items.value.length === 0) items.value.push({ productId: null, quantity: 1, unitCost: 0 })
}

async function save() {
  if (!purchaseOrderId.value) return
  if (saving.value) return
  message.value = ''

  const payloadItems = items.value
    .map((it) => ({
      productId: Number(it.productId),
      quantity: Number(it.quantity),
      unitCost: Number(it.unitCost),
    }))
    .filter((it) => Number.isFinite(it.productId) && it.productId > 0 && Number.isFinite(it.quantity) && it.quantity > 0)

  if (payloadItems.length === 0) {
    message.value = 'Agrega al menos un ítem válido'
    return
  }

  saving.value = true
  const res = await apiPost<{ id: number }, Record<string, unknown>>('/purchase-receipts', {
    purchaseOrderId: purchaseOrderId.value,
    receivedDate: receivedDate.value,
    notes: notes.value.trim() || undefined,
    items: payloadItems,
  })
  if (!res.ok) {
    message.value = res.error.message
    saving.value = false
    return
  }
  saving.value = false
  await router.push(`/purchase-receipts/${res.data.id}`)
}

onMounted(loadData)
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div>
        <h5 class="fw-semibold mb-1">Nueva Recepción</h5>
        <div v-if="selectedOrder" class="text-secondary small">{{ selectedOrder.poNumber }} · {{ selectedOrder.supplierName }}</div>
        <div v-else class="text-secondary small">Registrar entrada a inventario</div>
      </div>
      <button class="btn btn-light border rounded-pill px-4 fw-bold text-muted" type="button" @click="$router.push('/purchase-receipts')">
        Cancelar
      </button>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-0">{{ message }}</div>

    <div class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-medium">Orden de compra <span class="text-danger">*</span></label>
            <select v-model="purchaseOrderId" class="form-select">
              <option :value="null">Seleccionar</option>
              <option v-for="o in purchaseOrders" :key="o.id" :value="o.id">{{ o.poNumber }} · {{ o.supplierName }}</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label fw-medium">Fecha recepción <span class="text-danger">*</span></label>
            <input v-model="receivedDate" class="form-control" type="date" />
          </div>
          <div class="col-md-3">
            <label class="form-label fw-medium">Total</label>
            <input :value="total" class="form-control" disabled />
          </div>
          <div class="col-md-12">
            <label class="form-label fw-medium">Notas</label>
            <textarea v-model="notes" class="form-control" rows="2"></textarea>
          </div>
        </div>

        <hr class="my-4" />

        <div class="d-flex align-items-center justify-content-between">
          <h6 class="fw-semibold mb-0">Ítems</h6>
          <button class="btn btn-sm btn-outline-dark rounded-pill" type="button" @click="addRow">Agregar</button>
        </div>

        <div class="table-responsive mt-3">
          <table class="table align-middle">
            <thead>
              <tr>
                <th style="min-width: 280px">Producto</th>
                <th style="width: 140px">Cantidad</th>
                <th style="width: 160px">Costo</th>
                <th style="width: 160px">Subtotal</th>
                <th style="width: 90px"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(it, idx) in items" :key="idx">
                <td>
                  <select v-model="it.productId" class="form-select">
                    <option :value="null">Seleccionar</option>
                    <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }} <span v-if="p.sku">({{ p.sku }})</span></option>
                  </select>
                </td>
                <td><input v-model.number="it.quantity" class="form-control" type="number" min="0" step="0.01" /></td>
                <td><input v-model.number="it.unitCost" class="form-control" type="number" min="0" step="0.01" /></td>
                <td class="fw-semibold">{{ (Number(it.quantity ?? 0) * Number(it.unitCost ?? 0)).toFixed(2) }}</td>
                <td class="text-end">
                  <button class="btn btn-sm btn-outline-danger rounded-pill" type="button" @click="removeRow(idx)">X</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="d-flex justify-content-end gap-2 mt-3">
          <button class="btn btn-dark rounded-pill px-4" type="button" :disabled="saving || !purchaseOrderId" @click="save">
            Guardar
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
