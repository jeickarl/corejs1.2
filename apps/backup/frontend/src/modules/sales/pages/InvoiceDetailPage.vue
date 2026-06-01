<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { apiGet, apiPatch, apiPost } from '../../../api/http'

type InvoiceItem = {
  id: number
  itemType: 'manual' | 'product' | 'service'
  description: string
  quantity: number
  unitPrice: number
  totalPrice: number
}

type InvoicePayment = {
  id: number
  paymentAmount: number
  paymentMethod: string
  paymentDate: string
  referenceNumber: string | null
  notes: string | null
}

type Invoice = {
  id: number
  invoiceNumber: string
  clientId: number
  clientName: string
  invoiceDate: string
  dueDate: string | null
  documentType: string | null
  subtotal: number
  discountAmount: number
  taxAmount: number
  totalAmount: number
  paidAmount: number
  pendingAmount: number
  paymentStatus: 'pending' | 'partial' | 'paid'
  status: 'draft' | 'sent' | 'paid' | 'cancelled'
  notes: string | null
  termsConditions: string | null
  createdAt: string
  cancelledAt: string | null
  cancellationReason: string | null
  items: InvoiceItem[]
  payments: InvoicePayment[]
}

function badgeForPayment(ps: Invoice['paymentStatus']) {
  if (ps === 'paid') return { text: 'Pagado', color: '#28a745' }
  if (ps === 'partial') return { text: 'Parcial', color: '#ffc107' }
  return { text: 'Pendiente', color: '#6c757d' }
}

function badgeForStatus(st: Invoice['status']) {
  if (st === 'cancelled') return { text: 'Anulada', color: '#dc3545' }
  if (st === 'paid') return { text: 'Cerrada', color: '#28a745' }
  if (st === 'sent') return { text: 'Emitida', color: '#0d6efd' }
  return { text: 'Borrador', color: '#6c757d' }
}

const route = useRoute()
const id = computed(() => Number(route.params.id))

const loading = ref(false)
const busy = ref(false)
const message = ref('')
const invoice = ref<Invoice | null>(null)

const paymentOpen = ref(false)
const paymentAmountInput = ref('')
const paymentMethodInput = ref('')
const paymentReferenceInput = ref('')
const paymentNotesInput = ref('')

const cancelOpen = ref(false)
const cancelReasonInput = ref('')

async function load() {
  loading.value = true
  message.value = ''
  const res = await apiGet<Invoice>(`/sales/invoices/${id.value}`)
  if (!res.ok) {
    message.value = res.error.message
    invoice.value = null
    loading.value = false
    return
  }
  invoice.value = res.data
  loading.value = false
}

async function addPayment() {
  if (!invoice.value) return
  if (busy.value) return
  paymentAmountInput.value = String(invoice.value.pendingAmount)
  paymentMethodInput.value = ''
  paymentReferenceInput.value = ''
  paymentNotesInput.value = ''
  paymentOpen.value = true
}

async function submitPayment() {
  if (!invoice.value) return
  if (busy.value) return
  const amount = Number(paymentAmountInput.value)
  if (!Number.isFinite(amount) || amount <= 0) return
  const method = paymentMethodInput.value.trim()
  if (!method) return

  busy.value = true
  message.value = ''
  const res = await apiPost<{ done: true }, { paymentAmount: number; paymentMethod: string; referenceNumber?: string; notes?: string }>(
    `/sales/invoices/${invoice.value.id}/payments`,
    {
      paymentAmount: amount,
      paymentMethod: method,
      referenceNumber: paymentReferenceInput.value.trim() || undefined,
      notes: paymentNotesInput.value.trim() || undefined,
    },
  )
  if (!res.ok) {
    message.value = res.error.message
    busy.value = false
    return
  }
  busy.value = false
  paymentOpen.value = false
  await load()
}

async function cancel() {
  if (!invoice.value) return
  if (busy.value) return
  cancelReasonInput.value = ''
  cancelOpen.value = true
}

async function submitCancel() {
  if (!invoice.value) return
  if (busy.value) return
  const reason = cancelReasonInput.value.trim()
  if (!reason) return
  busy.value = true
  message.value = ''
  const res = await apiPatch<{ done: true }, { reason: string }>(`/sales/invoices/${invoice.value.id}/cancel`, { reason })
  if (!res.ok) {
    message.value = res.error.message
    busy.value = false
    return
  }
  busy.value = false
  cancelOpen.value = false
  await load()
}

onMounted(load)
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <teleport to="body">
      <div
        v-if="paymentOpen"
        class="position-fixed top-0 start-0 w-100 h-100"
        style="background: rgba(0,0,0,.5); z-index: 1050;"
      >
        <div
          class="position-absolute top-50 start-50 translate-middle bg-white rounded-4 p-3 shadow"
          style="min-width: 340px; max-width: 92vw;"
        >
          <div class="d-flex align-items-start justify-content-between gap-3">
            <div class="fw-semibold">Registrar pago</div>
            <button class="btn-close" type="button" :disabled="busy" @click="paymentOpen = false"></button>
          </div>
          <div class="mt-3">
            <div class="row g-2">
              <div class="col-12">
                <label class="form-label">Monto</label>
                <input v-model="paymentAmountInput" class="form-control" type="number" :disabled="busy" />
              </div>
              <div class="col-12">
                <label class="form-label">Método</label>
                <input v-model="paymentMethodInput" class="form-control" placeholder="Ej: Efectivo" :disabled="busy" />
              </div>
              <div class="col-12">
                <label class="form-label">Referencia (opcional)</label>
                <input v-model="paymentReferenceInput" class="form-control" :disabled="busy" />
              </div>
              <div class="col-12">
                <label class="form-label">Notas (opcional)</label>
                <input v-model="paymentNotesInput" class="form-control" :disabled="busy" />
              </div>
            </div>
          </div>
          <div class="d-flex justify-content-end gap-2 mt-3">
            <button class="btn btn-outline-secondary rounded-pill" type="button" :disabled="busy" @click="paymentOpen = false">
              Cancelar
            </button>
            <button class="btn btn-dark rounded-pill" type="button" :disabled="busy" @click="submitPayment">
              Guardar
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="cancelOpen"
        class="position-fixed top-0 start-0 w-100 h-100"
        style="background: rgba(0,0,0,.5); z-index: 1050;"
      >
        <div
          class="position-absolute top-50 start-50 translate-middle bg-white rounded-4 p-3 shadow"
          style="min-width: 340px; max-width: 92vw;"
        >
          <div class="d-flex align-items-start justify-content-between gap-3">
            <div class="fw-semibold">Anular factura</div>
            <button class="btn-close" type="button" :disabled="busy" @click="cancelOpen = false"></button>
          </div>
          <div class="mt-3">
            <label class="form-label">Motivo de anulación</label>
            <input v-model="cancelReasonInput" class="form-control" :disabled="busy" />
          </div>
          <div class="d-flex justify-content-end gap-2 mt-3">
            <button class="btn btn-outline-secondary rounded-pill" type="button" :disabled="busy" @click="cancelOpen = false">
              Cancelar
            </button>
            <button class="btn btn-danger rounded-pill" type="button" :disabled="busy" @click="submitCancel">
              Anular
            </button>
          </div>
        </div>
      </div>
    </teleport>

    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div>
        <h5 class="fw-semibold mb-1">Detalle de Venta</h5>
        <div v-if="invoice" class="text-secondary small">{{ invoice.invoiceNumber }} · {{ invoice.clientName }}</div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-light border rounded-pill px-4 fw-bold text-muted" type="button" @click="$router.push('/sales')">
          Volver
        </button>
        <button v-if="invoice" class="btn btn-outline-success rounded-pill px-4 fw-bold" type="button" :disabled="busy || invoice.status === 'cancelled'" @click="addPayment">
          Registrar Pago
        </button>
        <button v-if="invoice" class="btn btn-outline-danger rounded-pill px-4 fw-bold" type="button" :disabled="busy || invoice.status === 'cancelled'" @click="cancel">
          Anular
        </button>
      </div>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-0">{{ message }}</div>
    <div v-if="loading" class="text-secondary">Cargando...</div>

    <div v-if="invoice" class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-4">
            <div class="text-secondary small">Factura</div>
            <div class="fw-semibold">{{ invoice.invoiceNumber }}</div>
          </div>
          <div class="col-md-4">
            <div class="text-secondary small">Pago</div>
            <div>
              <span class="badge rounded-pill" :style="{ backgroundColor: badgeForPayment(invoice.paymentStatus).color }">
                {{ badgeForPayment(invoice.paymentStatus).text }}
              </span>
            </div>
          </div>
          <div class="col-md-4">
            <div class="text-secondary small">Estado</div>
            <div>
              <span class="badge rounded-pill" :style="{ backgroundColor: badgeForStatus(invoice.status).color }">
                {{ badgeForStatus(invoice.status).text }}
              </span>
            </div>
          </div>
          <div class="col-md-6">
            <div class="text-secondary small">Cliente</div>
            <div class="fw-semibold">{{ invoice.clientName }} (ID {{ invoice.clientId }})</div>
          </div>
          <div class="col-md-3">
            <div class="text-secondary small">Fecha</div>
            <div class="fw-semibold">{{ invoice.invoiceDate }}</div>
          </div>
          <div class="col-md-3">
            <div class="text-secondary small">Vence</div>
            <div class="fw-semibold">{{ invoice.dueDate || '-' }}</div>
          </div>
          <div v-if="invoice.cancellationReason" class="col-md-12">
            <div class="text-secondary small">Motivo de anulación</div>
            <div class="fw-semibold">{{ invoice.cancellationReason }}</div>
          </div>
        </div>

        <div class="border-top my-4"></div>

        <h6 class="fw-semibold mb-3">Ítems</h6>
        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>Descripción</th>
                <th style="width: 110px;">Cant.</th>
                <th style="width: 140px;">Valor</th>
                <th style="width: 140px;">Total</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="it in invoice.items" :key="it.id">
                <td>{{ it.description }}</td>
                <td>{{ it.quantity }}</td>
                <td>{{ it.unitPrice }}</td>
                <td class="fw-semibold">{{ it.totalPrice }}</td>
              </tr>
              <tr v-if="invoice.items.length === 0">
                <td colspan="4" class="text-secondary">Sin ítems</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="row justify-content-end">
          <div class="col-md-5">
            <div class="border rounded-4 p-3 bg-light">
              <div class="d-flex justify-content-between">
                <div class="text-secondary">Subtotal</div>
                <div class="fw-semibold">{{ invoice.subtotal }}</div>
              </div>
              <div class="d-flex justify-content-between">
                <div class="text-secondary">IVA</div>
                <div class="fw-semibold">{{ invoice.taxAmount }}</div>
              </div>
              <div class="d-flex justify-content-between border-top pt-2 mt-2">
                <div class="fw-semibold">Total</div>
                <div class="fw-bold">{{ invoice.totalAmount }}</div>
              </div>
              <div class="d-flex justify-content-between mt-2">
                <div class="text-secondary">Pagado</div>
                <div class="fw-semibold">{{ invoice.paidAmount }}</div>
              </div>
              <div class="d-flex justify-content-between">
                <div class="text-secondary">Pendiente</div>
                <div class="fw-semibold">{{ invoice.pendingAmount }}</div>
              </div>
            </div>
          </div>
        </div>

        <div class="border-top my-4"></div>

        <h6 class="fw-semibold mb-3">Pagos</h6>
        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>ID</th>
                <th>Monto</th>
                <th>Método</th>
                <th>Fecha</th>
                <th>Referencia</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in invoice.payments" :key="p.id">
                <td>{{ p.id }}</td>
                <td class="fw-semibold">{{ p.paymentAmount }}</td>
                <td>{{ p.paymentMethod }}</td>
                <td>{{ p.paymentDate }}</td>
                <td>{{ p.referenceNumber || '-' }}</td>
              </tr>
              <tr v-if="invoice.payments.length === 0">
                <td colspan="5" class="text-secondary">Sin pagos</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>
