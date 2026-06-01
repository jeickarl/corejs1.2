<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { apiGet, apiPost } from '../../../api/http'

type ClientListItem = {
  id: number
  clientType: string
  firstName: string
  companyName: string
  phone: string
  email: string
  idNumber: string
  createdAt: string
}

type ClientsPage = { items: ClientListItem[]; page: number; perPage: number; total: number }

type ProductListItem = {
  id: number
  sku: string
  name: string
  salePrice: number
  currentStock: number
  minStock: number
}

type ProductsPage = { items: ProductListItem[]; page: number; perPage: number; total: number }

type PaymentMethodRow = { id: number; name: string; isDefault: boolean; isActive: boolean }
type PaymentAccountRow = { id: number; paymentMethodId: number; alias: string; accountNumber: string; isActive: boolean }

type CreateItem = {
  itemType: 'manual' | 'product' | 'service'
  productId?: number | null
  description: string
  quantity: number
  unitPrice: number
  taxPercent: number
}

type CreatePayment = {
  paymentAmount: number
  paymentMethod: string
  paymentDate?: string
  referenceNumber?: string
  notes?: string
}

function nowDateTime(): string {
  const d = new Date()
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`
}

function money(v: unknown): number {
  const n = Number(v ?? 0)
  if (!Number.isFinite(n)) return 0
  return Math.round(n * 100) / 100
}

const router = useRouter()

const clientSearch = ref('')
const clientId = ref<number | null>(null)
const clientName = ref('')
const clientResults = ref<ClientListItem[]>([])
const showClientResults = ref(false)

const productSearch = ref('')
const productResults = ref<ProductListItem[]>([])
const showProductResults = ref(false)

const documentType = ref('Factura')
const invoiceDate = ref(nowDateTime())
const dueDate = ref<string>('')
const notes = ref('')
const termsConditions = ref('')

const items = ref<CreateItem[]>([{ itemType: 'manual', description: '', quantity: 1, unitPrice: 0, taxPercent: 0 }])

const registerPaymentNow = ref(false)
const paymentAmount = ref<number>(0)
const paymentMethod = ref('Efectivo')
const paymentMethods = ref<PaymentMethodRow[]>([])
const paymentAccountId = ref<number | null>(null)
const paymentAccounts = ref<PaymentAccountRow[]>([])
const paymentReference = ref('')
const paymentNotes = ref('')

const message = ref('')
const saving = ref(false)

const subtotal = computed(() => money(items.value.reduce((a, it) => a + money(it.quantity) * money(it.unitPrice), 0)))
const taxAmount = computed(() =>
  money(items.value.reduce((a, it) => a + (money(it.quantity) * money(it.unitPrice) * money(it.taxPercent)) / 100, 0)),
)
const totalAmount = computed(() => money(subtotal.value + taxAmount.value))

watch(
  totalAmount,
  (t) => {
    if (registerPaymentNow.value && money(paymentAmount.value) === 0) paymentAmount.value = t
  },
  { immediate: true },
)

async function searchClients() {
  const q = clientSearch.value.trim()
  if (q.length < 2) {
    clientResults.value = []
    showClientResults.value = false
    return
  }
  const qs = new URLSearchParams({ search: q, page: '1', perPage: '8' })
  const res = await apiGet<ClientsPage>(`/clients?${qs.toString()}`)
  clientResults.value = res.ok ? res.data.items : []
  showClientResults.value = true
}

watch(clientSearch, () => {
  clientId.value = null
  clientName.value = ''
  void searchClients()
})

function pickClient(c: ClientListItem) {
  clientId.value = c.id
  const nm = c.companyName?.trim() || c.firstName?.trim() || `Cliente #${c.id}`
  clientName.value = nm
  clientSearch.value = nm
  showClientResults.value = false
}

async function searchProducts() {
  const q = productSearch.value.trim()
  if (q.length < 2) {
    productResults.value = []
    showProductResults.value = false
    return
  }
  const qs = new URLSearchParams({ search: q, onlyActive: '1', page: '1', perPage: '8' })
  const res = await apiGet<ProductsPage>(`/inventory/products?${qs.toString()}`)
  productResults.value = res.ok ? res.data.items : []
  showProductResults.value = true
}

watch(productSearch, () => {
  void searchProducts()
})

function pickProduct(p: ProductListItem) {
  items.value = [
    ...items.value,
    {
      itemType: 'product',
      productId: p.id,
      description: p.name,
      quantity: 1,
      unitPrice: Number(p.salePrice ?? 0),
      taxPercent: 0,
    },
  ]
  productSearch.value = ''
  showProductResults.value = false
}

async function loadPaymentMethods() {
  const res = await apiGet<PaymentMethodRow[]>('/settings/payment-methods?onlyActive=1')
  if (!res.ok) {
    paymentMethods.value = []
    return
  }
  paymentMethods.value = res.data
  const def = paymentMethods.value.find((m) => m.isDefault) || paymentMethods.value[0]
  if (def?.name) paymentMethod.value = def.name
}

async function loadPaymentAccountsForSelectedMethod() {
  const pm = paymentMethods.value.find((m) => m.name === paymentMethod.value)
  if (!pm) {
    paymentAccounts.value = []
    paymentAccountId.value = null
    return
  }
  const res = await apiGet<PaymentAccountRow[]>(`/settings/payment-methods/${pm.id}/accounts?onlyActive=1`)
  if (!res.ok) {
    paymentAccounts.value = []
    paymentAccountId.value = null
    return
  }
  paymentAccounts.value = res.data
  const first = paymentAccounts.value[0]
  paymentAccountId.value = first ? first.id : null
}

watch(paymentMethod, () => {
  paymentAccounts.value = []
  paymentAccountId.value = null
  if (registerPaymentNow.value) void loadPaymentAccountsForSelectedMethod()
})

watch(registerPaymentNow, (v) => {
  if (v) void loadPaymentAccountsForSelectedMethod()
})

watch(paymentAccountId, () => {
  const acc = paymentAccounts.value.find((a) => a.id === paymentAccountId.value)
  if (!acc) return
  if (!paymentReference.value.trim()) {
    paymentReference.value = acc.accountNumber || acc.alias
  }
})

function addItem() {
  items.value = [...items.value, { itemType: 'manual', description: '', quantity: 1, unitPrice: 0, taxPercent: 0 }]
}

function removeItem(idx: number) {
  items.value = items.value.filter((_, i) => i !== idx)
  if (items.value.length === 0) addItem()
}

async function save(action: 'save' | 'save_pending') {
  if (saving.value) return
  saving.value = true
  message.value = ''

  if (!clientId.value) {
    message.value = 'Debe seleccionar un cliente.'
    saving.value = false
    return
  }

  const validItems = items.value
    .map((it) => ({
      itemType: it.itemType,
      productId: it.itemType === 'product' ? Number(it.productId ?? 0) || null : null,
      description: it.description.trim(),
      quantity: money(it.quantity),
      unitPrice: money(it.unitPrice),
      taxPercent: money(it.taxPercent),
    }))
    .filter((it) => it.description && it.quantity > 0)

  for (const it of validItems) {
    if (it.itemType === 'product' && !it.productId) {
      message.value = 'Debe seleccionar un producto válido.'
      saving.value = false
      return
    }
  }

  if (validItems.length === 0) {
    message.value = 'Debe agregar al menos un ítem.'
    saving.value = false
    return
  }

  const payments: CreatePayment[] = []
  if (action === 'save' && registerPaymentNow.value && money(paymentAmount.value) > 0) {
    payments.push({
      paymentAmount: money(paymentAmount.value),
      paymentMethod: paymentMethod.value.trim(),
      paymentDate: nowDateTime(),
      referenceNumber: paymentReference.value.trim() || undefined,
      notes: paymentNotes.value.trim() || undefined,
    })
  }

  const body: Record<string, unknown> = {
    clientId: clientId.value,
    documentType: documentType.value.trim() || undefined,
    invoiceDate: invoiceDate.value.trim(),
    dueDate: dueDate.value.trim() ? dueDate.value.trim() : null,
    notes: notes.value.trim() || undefined,
    termsConditions: termsConditions.value.trim() || undefined,
    action,
    items: validItems,
    payments,
  }

  const res = await apiPost<{ id: number }, Record<string, unknown>>('/sales/invoices', body)
  if (!res.ok) {
    message.value = res.error.message
    saving.value = false
    return
  }
  await router.push(`/sales/${res.data.id}`)
}

onMounted(() => {
  void loadPaymentMethods()
})
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div>
        <h5 class="fw-semibold mb-1">Nueva Venta</h5>
        <div class="text-secondary small">Crear factura</div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-light border rounded-pill px-4 fw-bold text-muted" type="button" @click="$router.push('/sales')">
          Cancelar
        </button>
      </div>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-0">{{ message }}</div>

    <div class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6 position-relative">
            <label class="form-label fw-medium">Cliente <span class="text-danger">*</span></label>
            <input v-model="clientSearch" class="form-control" placeholder="Buscar cliente..." @focus="showClientResults = true" />
            <div v-if="showClientResults && clientResults.length > 0" class="position-absolute bg-white border rounded shadow-sm mt-1" style="z-index: 20; width: 100%;">
              <button
                v-for="c in clientResults"
                :key="c.id"
                class="btn btn-link text-start w-100 text-decoration-none px-3 py-2"
                type="button"
                @click="pickClient(c)"
              >
                <div class="fw-semibold">{{ c.companyName || c.firstName || `Cliente #${c.id}` }}</div>
                <div class="text-secondary small">{{ c.phone }} {{ c.email ? '· ' + c.email : '' }}</div>
              </button>
            </div>
            <div v-if="clientId" class="text-secondary small mt-1">Seleccionado: {{ clientName }} (ID {{ clientId }})</div>
          </div>
          <div class="col-md-3">
            <label class="form-label fw-medium">Tipo Documento</label>
            <input v-model="documentType" class="form-control" />
          </div>
          <div class="col-md-3">
            <label class="form-label fw-medium">Fecha</label>
            <input v-model="invoiceDate" class="form-control" />
          </div>
          <div class="col-md-3">
            <label class="form-label fw-medium">Vence</label>
            <input v-model="dueDate" class="form-control" placeholder="YYYY-MM-DD" />
          </div>
          <div class="col-md-9">
            <label class="form-label fw-medium">Notas</label>
            <input v-model="notes" class="form-control" />
          </div>
          <div class="col-md-12">
            <label class="form-label fw-medium">Términos y Condiciones</label>
            <textarea v-model="termsConditions" class="form-control" rows="2"></textarea>
          </div>
        </div>

        <div class="border-top my-4"></div>

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <h6 class="fw-semibold mb-0">Ítems</h6>
          <div class="d-flex gap-2 align-items-center flex-wrap">
            <div class="position-relative" style="min-width: 280px; max-width: 360px; width: 100%;">
              <input
                v-model="productSearch"
                class="form-control"
                placeholder="Buscar producto..."
                @focus="showProductResults = true"
              />
              <div
                v-if="showProductResults && productResults.length > 0"
                class="position-absolute bg-white border rounded shadow-sm mt-1"
                style="z-index: 20; width: 100%;"
              >
                <button
                  v-for="p in productResults"
                  :key="p.id"
                  class="btn btn-link text-start w-100 text-decoration-none px-3 py-2"
                  type="button"
                  @click="pickProduct(p)"
                >
                  <div class="fw-semibold">{{ p.name }}</div>
                  <div class="text-secondary small">
                    {{ p.sku ? p.sku + ' · ' : '' }}Stock: {{ p.currentStock }} · Precio: {{ p.salePrice }}
                  </div>
                </button>
              </div>
            </div>
            <button class="btn btn-sm btn-outline-dark rounded-pill" type="button" @click="addItem">Agregar Manual</button>
          </div>
        </div>

        <div class="table-responsive mt-3">
          <table class="table align-middle">
            <thead>
              <tr>
                <th style="width: 110px;">Tipo</th>
                <th>Descripción</th>
                <th style="width: 110px;">Cant.</th>
                <th style="width: 140px;">Valor</th>
                <th style="width: 110px;">IVA %</th>
                <th style="width: 140px;">Total</th>
                <th style="width: 80px;"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(it, idx) in items" :key="idx">
                <td>
                  <span
                    class="badge rounded-pill"
                    :class="it.itemType === 'product' ? 'text-bg-primary' : it.itemType === 'service' ? 'text-bg-info' : 'text-bg-secondary'"
                  >
                    {{ it.itemType === 'product' ? 'Producto' : it.itemType === 'service' ? 'Servicio' : 'Manual' }}
                  </span>
                </td>
                <td><input v-model="it.description" class="form-control" placeholder="Detalle..." /></td>
                <td><input v-model.number="it.quantity" class="form-control" type="number" min="0" step="0.01" /></td>
                <td><input v-model.number="it.unitPrice" class="form-control" type="number" min="0" step="0.01" /></td>
                <td><input v-model.number="it.taxPercent" class="form-control" type="number" min="0" step="0.01" /></td>
                <td class="fw-semibold">{{ money(it.quantity) * money(it.unitPrice) }}</td>
                <td class="text-end">
                  <button class="btn btn-sm btn-outline-danger rounded-pill" type="button" @click="removeItem(idx)">X</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="row justify-content-end">
          <div class="col-md-5">
            <div class="border rounded-4 p-3 bg-light">
              <div class="d-flex justify-content-between">
                <div class="text-secondary">Subtotal</div>
                <div class="fw-semibold">{{ subtotal }}</div>
              </div>
              <div class="d-flex justify-content-between">
                <div class="text-secondary">IVA</div>
                <div class="fw-semibold">{{ taxAmount }}</div>
              </div>
              <div class="d-flex justify-content-between border-top pt-2 mt-2">
                <div class="fw-semibold">Total</div>
                <div class="fw-bold">{{ totalAmount }}</div>
              </div>
            </div>
          </div>
        </div>

        <div class="border-top my-4"></div>

        <div class="form-check">
          <input id="pay-now" v-model="registerPaymentNow" class="form-check-input" type="checkbox" />
          <label class="form-check-label" for="pay-now">Registrar pago ahora</label>
        </div>

        <div v-if="registerPaymentNow" class="row g-3 mt-2">
          <div class="col-md-3">
            <label class="form-label fw-medium">Monto</label>
            <input v-model.number="paymentAmount" class="form-control" type="number" min="0" step="0.01" />
          </div>
          <div class="col-md-3">
            <label class="form-label fw-medium">Método</label>
            <select v-model="paymentMethod" class="form-select">
              <option v-for="m in paymentMethods" :key="m.id">{{ m.name }}</option>
              <option v-if="paymentMethods.length === 0">Efectivo</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label fw-medium">Cuenta</label>
            <select v-model="paymentAccountId" class="form-select">
              <option :value="null">-</option>
              <option v-for="a in paymentAccounts" :key="a.id" :value="a.id">
                {{ a.alias }}{{ a.accountNumber ? ' · ' + a.accountNumber : '' }}
              </option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label fw-medium">Referencia</label>
            <input v-model="paymentReference" class="form-control" />
          </div>
          <div class="col-md-12">
            <label class="form-label fw-medium">Notas Pago</label>
            <input v-model="paymentNotes" class="form-control" />
          </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mt-4 flex-wrap">
          <button class="btn btn-outline-secondary rounded-pill px-4" type="button" :disabled="saving" @click="save('save_pending')">
            Guardar Pendiente
          </button>
          <button class="btn btn-dark rounded-pill px-4" type="button" :disabled="saving" @click="save('save')">
            Guardar
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
