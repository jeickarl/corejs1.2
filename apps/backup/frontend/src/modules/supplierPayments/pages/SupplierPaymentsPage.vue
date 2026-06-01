<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { apiGet, apiPatch, apiPost } from '../../../api/http'

type PendingOrderDto = {
  id: number
  poNumber: string
  supplierId: number
  supplierName: string
  orderDate: string
  status: string
  paymentStatus: string
  totalAmount: number
  paidAmount: number
  pendingAmount: number
}

type SupplierPaymentDto = {
  id: number
  supplierId: number
  supplierName: string
  purchaseOrderId: number | null
  poNumber: string | null
  paymentAmount: number
  paymentMethod: string | null
  paymentDate: string
  referenceNumber: string | null
  notes: string | null
  status: string
  createdAt: string
}

type PageDto<T> = { items: T[]; page: number; perPage: number; total: number }

const pendingOrders = ref<PendingOrderDto[]>([])
const recentPayments = ref<SupplierPaymentDto[]>([])

const message = ref('')
const busy = ref(false)

const showPayModal = ref(false)
const payOrder = ref<PendingOrderDto | null>(null)
const payAmount = ref<number | null>(null)
const payMethod = ref('')
const payDate = ref(new Date().toISOString().slice(0, 10))
const payReference = ref('')
const payNotes = ref('')

const showVoidModal = ref(false)
const voidPayment = ref<SupplierPaymentDto | null>(null)
const voidReason = ref('')

const canPay = computed(() => {
  if (!payOrder.value) return false
  const a = Number(payAmount.value ?? 0)
  return Number.isFinite(a) && a > 0
})

function openPay(o: PendingOrderDto) {
  payOrder.value = o
  payAmount.value = o.pendingAmount > 0 ? o.pendingAmount : null
  payMethod.value = ''
  payDate.value = new Date().toISOString().slice(0, 10)
  payReference.value = ''
  payNotes.value = ''
  showPayModal.value = true
}

function openVoid(p: SupplierPaymentDto) {
  voidPayment.value = p
  voidReason.value = ''
  showVoidModal.value = true
}

function closeModals() {
  showPayModal.value = false
  showVoidModal.value = false
}

async function load() {
  message.value = ''
  const [p1, p2] = await Promise.all([
    apiGet<PageDto<PendingOrderDto>>('/supplier-payments/pending-orders?page=1&perPage=50'),
    apiGet<PageDto<SupplierPaymentDto>>('/supplier-payments/recent?page=1&perPage=50'),
  ])

  pendingOrders.value = p1.ok ? p1.data.items : []
  recentPayments.value = p2.ok ? p2.data.items : []

  if (!p1.ok) message.value = p1.error.message
  else if (!p2.ok) message.value = p2.error.message
}

async function submitPayment() {
  if (!payOrder.value || !canPay.value || busy.value) return
  busy.value = true
  message.value = ''

  const res = await apiPost<{ id: number }, Record<string, unknown>>('/supplier-payments', {
    supplierId: payOrder.value.supplierId,
    purchaseOrderId: payOrder.value.id,
    paymentAmount: Number(payAmount.value),
    paymentMethod: payMethod.value.trim() || undefined,
    paymentDate: payDate.value,
    referenceNumber: payReference.value.trim() || undefined,
    notes: payNotes.value.trim() || undefined,
  })

  if (!res.ok) {
    message.value = res.error.message
    busy.value = false
    return
  }

  closeModals()
  busy.value = false
  await load()
}

async function submitVoid() {
  if (!voidPayment.value || busy.value) return
  const r = voidReason.value.trim()
  if (!r) return
  busy.value = true
  message.value = ''

  const res = await apiPatch<{ done: true }, { reason: string }>(`/supplier-payments/${voidPayment.value.id}/void`, { reason: r })
  if (!res.ok) {
    message.value = res.error.message
    busy.value = false
    return
  }

  closeModals()
  busy.value = false
  await load()
}

onMounted(load)
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
      <div>
        <h5 class="fw-semibold mb-1">Pagos a Proveedores</h5>
        <div class="text-secondary small">Registrar pagos y ver pendientes</div>
      </div>
      <div class="text-secondary small">Pendientes: {{ pendingOrders.length }} · Pagos recientes: {{ recentPayments.length }}</div>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-0">
      {{ message }}
    </div>

    <div class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <h6 class="fw-semibold mb-3">Órdenes pendientes</h6>
        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>PO</th>
                <th>Proveedor</th>
                <th>Fecha</th>
                <th>Estado</th>
                <th>Pago</th>
                <th>Total</th>
                <th>Pagado</th>
                <th>Pendiente</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="o in pendingOrders" :key="o.id">
                <td class="fw-semibold">{{ o.poNumber }}</td>
                <td>{{ o.supplierName }}</td>
                <td>{{ o.orderDate }}</td>
                <td>{{ o.status }}</td>
                <td>{{ o.paymentStatus }}</td>
                <td>{{ o.totalAmount }}</td>
                <td>{{ o.paidAmount }}</td>
                <td class="fw-semibold">{{ o.pendingAmount }}</td>
                <td class="text-end">
                  <button class="btn btn-sm btn-dark rounded-pill" type="button" @click="openPay(o)">Pagar</button>
                </td>
              </tr>
              <tr v-if="pendingOrders.length === 0">
                <td colspan="9" class="text-secondary">Sin pendientes</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <h6 class="fw-semibold mb-3">Pagos recientes</h6>
        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Proveedor</th>
                <th>PO</th>
                <th>Monto</th>
                <th>Método</th>
                <th>Estado</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in recentPayments" :key="p.id">
                <td>{{ p.paymentDate }}</td>
                <td class="fw-semibold">{{ p.supplierName }}</td>
                <td>{{ p.poNumber || '-' }}</td>
                <td>{{ p.paymentAmount }}</td>
                <td>{{ p.paymentMethod || '-' }}</td>
                <td>{{ p.status }}</td>
                <td class="text-end">
                  <button v-if="p.status !== 'voided'" class="btn btn-sm btn-outline-danger rounded-pill" type="button" @click="openVoid(p)">
                    Anular
                  </button>
                </td>
              </tr>
              <tr v-if="recentPayments.length === 0">
                <td colspan="7" class="text-secondary">Sin pagos</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div v-if="showPayModal" class="modal-backdrop fade show"></div>
    <div v-if="showPayModal" class="modal fade show d-block" tabindex="-1" role="dialog">
      <div class="modal-dialog">
        <div class="modal-content border-0 rounded-custom shadow">
          <div class="modal-header">
            <h5 class="modal-title">Registrar pago</h5>
            <button type="button" class="btn-close" @click="closeModals"></button>
          </div>
          <div class="modal-body">
            <div v-if="payOrder" class="small text-secondary mb-3">{{ payOrder.supplierName }} · {{ payOrder.poNumber }}</div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Monto</label>
                <input v-model.number="payAmount" class="form-control" type="number" min="0" step="0.01" />
              </div>
              <div class="col-md-6">
                <label class="form-label">Fecha</label>
                <input v-model="payDate" class="form-control" type="date" />
              </div>
              <div class="col-md-6">
                <label class="form-label">Método</label>
                <input v-model="payMethod" class="form-control" placeholder="Ej: Efectivo" />
              </div>
              <div class="col-md-6">
                <label class="form-label">Referencia</label>
                <input v-model="payReference" class="form-control" />
              </div>
              <div class="col-md-12">
                <label class="form-label">Notas</label>
                <textarea v-model="payNotes" class="form-control" rows="3"></textarea>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-light border rounded-pill px-4 fw-bold text-muted" type="button" :disabled="busy" @click="closeModals">
              Cancelar
            </button>
            <button class="btn btn-dark rounded-pill px-4" type="button" :disabled="busy || !canPay" @click="submitPayment">
              Guardar
            </button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="showVoidModal" class="modal-backdrop fade show"></div>
    <div v-if="showVoidModal" class="modal fade show d-block" tabindex="-1" role="dialog">
      <div class="modal-dialog">
        <div class="modal-content border-0 rounded-custom shadow">
          <div class="modal-header">
            <h5 class="modal-title">Anular pago</h5>
            <button type="button" class="btn-close" @click="closeModals"></button>
          </div>
          <div class="modal-body">
            <div v-if="voidPayment" class="small text-secondary mb-3">
              {{ voidPayment.supplierName }} · {{ voidPayment.poNumber || '-' }} · {{ voidPayment.paymentAmount }}
            </div>
            <label class="form-label">Motivo</label>
            <textarea v-model="voidReason" class="form-control" rows="3"></textarea>
          </div>
          <div class="modal-footer">
            <button class="btn btn-light border rounded-pill px-4 fw-bold text-muted" type="button" :disabled="busy" @click="closeModals">
              Cancelar
            </button>
            <button class="btn btn-danger rounded-pill px-4" type="button" :disabled="busy || !voidReason.trim()" @click="submitVoid">
              Anular
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

