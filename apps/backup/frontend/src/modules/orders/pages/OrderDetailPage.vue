<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiDelete, apiGet, apiPatch, apiPost } from '../../../api/http'
import ChangeStatusModal from '../components/ChangeStatusModal.vue'

type Order = {
  id: number
  orderNumber: string
  clientId: number
  clientName: string
  deviceTypeId: number
  deviceTypeName: string
  deviceBrand: string
  deviceModel: string
  devicePassword: string
  serialNumber: string
  reportedIssue: string
  clientObservations: string
  status: string
  approvalStatus: string
  approvedAt: string | null
  approvedQuoteAmount: number | null
  approvalComment: string | null
  approvalSignature: string | null
  priority: string
  estimatedCost: number
  finalCost: number
  advancePayment: number
  paymentMethod: string
  paymentReference: string
  technicianNotes: string
  diagnosis: string
  solution: string
  estimatedCompletion: string | null
  accessoryIds: number[]
  createdAt: string
  updatedAt: string
}

type Client = {
  id: number
  clientType: string
  firstName: string
  companyName: string
  phone: string
  email: string
  idNumber: string
  createdAt: string
}

type OrderStatus = { slug: string; name: string; emoji: string; color: string; sortOrder: number }
type Accessory = { id: number; name: string }
type OrderHistory = { id: number; status: string; userId: number | null; createdAt: string }
type TechnicalReportListItem = { id: number; reportTitle: string; createdAt: string; createdBy: number | null }
type TechnicalReport = TechnicalReportListItem & {
  orderId: number
  diagnosis: string
  procedureTaken: string
  introduction: string
  conclusion: string
  photosJson: string | null
}

type ServiceRow = { id: number; name: string; basePrice: number; active: boolean }
type ServicesPage = { items: ServiceRow[]; page: number; perPage: number; total: number }
type OrderServiceItem = {
  id: number
  workOrderId: number
  serviceId: number
  serviceName: string
  quantity: number
  servicePrice: number
  totalPrice: number
  createdAt: string
}

const route = useRoute()
const router = useRouter()
const id = computed(() => Number(route.params.id))

const loading = ref(false)
const message = ref('')
const order = ref<Order | null>(null)
const client = ref<Client | null>(null)
const busy = ref(false)
const status = ref('')
const statusMessage = ref('')
const statuses = ref<OrderStatus[]>([])
const accessories = ref<Accessory[]>([])
const history = ref<OrderHistory[]>([])
const showStatusModal = ref(false)

const reports = ref<TechnicalReportListItem[]>([])
const reportsMessage = ref('')
const reportOpen = ref(false)
const reportBusy = ref(false)
const reportTitleInput = ref('')
const reportDiagnosisInput = ref('')
const reportProcedureInput = ref('')
const reportObservationsInput = ref('')
const reportConclusionsInput = ref('')

const services = ref<ServiceRow[]>([])
const orderServices = ref<OrderServiceItem[]>([])
const orderServicesMessage = ref('')
const addServiceId = ref<number | null>(null)
const addServiceQty = ref('1')
const addServicePrice = ref('')

const accessoriesText = computed(() => {
  if (!order.value) return ''
  const map = new Map(accessories.value.map((a) => [a.id, a.name]))
  const names = (order.value.accessoryIds ?? []).map((id) => map.get(id) ?? String(id)).filter(Boolean)
  return names.join(', ')
})

const priorityLabel = computed(() => {
  const p = (order.value?.priority ?? '').toLowerCase()
  if (p === 'urgent') return 'URGENTE'
  if (p === 'high') return 'ALTA'
  if (p === 'low') return 'BAJA'
  return 'MEDIA'
})

const orderStatusDisplay = computed(() => {
  const st = order.value?.status ?? ''
  const s = statuses.value.find((x) => x.slug === st)
  return { emoji: s?.emoji || '', name: s?.name || st }
})

function fmtMoney(v: number): string {
  const safe = Number.isFinite(v) ? v : 0
  try {
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(safe)
  } catch {
    return `$ ${Math.round(safe).toLocaleString('es-CO')}`
  }
}

function fmtDateTime(iso: string): string {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleString('es-CO', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' })
}

function statusBadgeStyle() {
  const st = status.value.trim()
  const s = statuses.value.find((x) => x.slug === st)
  const color = (s?.color ?? '').trim()
  if (color) return { backgroundColor: `${color}1A`, color }
  return { backgroundColor: '#fff3cd', color: '#b45309' }
}

function displayClientName(c: Client) {
  return (c.companyName ?? '').trim() || (c.firstName ?? '').trim() || `Cliente #${c.id}`
}

const printOpen = ref(false)

function scrollToSection(sectionId: string) {
  const el = document.getElementById(sectionId)
  if (!el) return
  el.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

async function loadStatuses() {
  const res = await apiGet<OrderStatus[]>('/orders/statuses')
  statuses.value = res.ok ? res.data : []
}

async function loadAccessories() {
  const res = await apiGet<Accessory[]>('/orders/accessories')
  accessories.value = res.ok ? res.data : []
}

async function loadHistory() {
  const res = await apiGet<OrderHistory[]>(`/orders/${id.value}/history`)
  history.value = res.ok ? res.data : []
}

async function loadReports() {
  reportsMessage.value = ''
  const res = await apiGet<TechnicalReportListItem[]>(`/orders/${id.value}/reports`)
  reports.value = res.ok ? res.data : []
  if (!res.ok) reportsMessage.value = res.error.message
}

async function loadServicesCatalog() {
  const qs = new URLSearchParams({ onlyActive: '1', page: '1', perPage: '200' })
  const res = await apiGet<ServicesPage>(`/services?${qs.toString()}`)
  services.value = res.ok ? res.data.items : []
}

async function loadOrderServices() {
  orderServicesMessage.value = ''
  const res = await apiGet<OrderServiceItem[]>(`/orders/${id.value}/services`)
  orderServices.value = res.ok ? res.data : []
  if (!res.ok) orderServicesMessage.value = res.error.message
}

async function load() {
  loading.value = true
  message.value = ''
  const res = await apiGet<Order>(`/orders/${id.value}`)
  if (!res.ok) {
    message.value = res.error.message
    order.value = null
    client.value = null
    loading.value = false
    return
  }
  order.value = res.data
  status.value = res.data.status
  if (res.data.clientId) {
    const cRes = await apiGet<Client>(`/clients/${res.data.clientId}`)
    client.value = cRes.ok ? cRes.data : null
  } else {
    client.value = null
  }
  loading.value = false
}

function printOrder() {
  if (!order.value) return
  const w = window.open('', '_blank')
  if (!w) return
  const o = order.value
  const c = client.value
  const html = `<!doctype html>
  <html lang="es">
    <head>
      <meta charset="utf-8" />
      <meta name="viewport" content="width=device-width, initial-scale=1" />
      <title>Orden ${escapeHtml(o.orderNumber || String(o.id))}</title>
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
      <style>
        @media print { .no-print { display:none !important; } body { background: white; } }
        body { padding: 24px; }
      </style>
    </head>
    <body>
      <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <div class="fw-semibold">Orden ${escapeHtml(o.orderNumber || String(o.id))}</div>
        <button class="btn btn-sm btn-dark" onclick="window.print()">Imprimir</button>
      </div>
      <div class="row g-3">
        <div class="col-12 col-md-6">
          <div class="border rounded-3 p-3">
            <div class="text-secondary small">Estado</div>
            <div class="fw-semibold">${escapeHtml(o.status || '-')}</div>
            <div class="text-secondary small mt-2">Prioridad</div>
            <div class="fw-semibold">${escapeHtml(o.priority || '-')}</div>
            <div class="text-secondary small mt-2">Creación</div>
            <div>${escapeHtml(o.createdAt || '-')}</div>
            <div class="text-secondary small mt-2">Actualización</div>
            <div>${escapeHtml(o.updatedAt || '-')}</div>
          </div>
        </div>
        <div class="col-12 col-md-6">
          <div class="border rounded-3 p-3">
            <div class="text-secondary small">Cliente</div>
            <div class="fw-semibold">${escapeHtml(o.clientName || (c ? displayClientName(c) : '-'))}</div>
            <div class="text-secondary small mt-2">Teléfono</div>
            <div>${escapeHtml((c?.phone ?? '') || '-')}</div>
            <div class="text-secondary small mt-2">Identificación</div>
            <div>${escapeHtml((c?.idNumber ?? '') || '-')}</div>
          </div>
        </div>
        <div class="col-12">
          <div class="border rounded-3 p-3">
            <div class="text-secondary small">Dispositivo</div>
            <div class="fw-semibold">${escapeHtml((o.deviceTypeName || '') + ' ' + (o.deviceBrand || '') + ' ' + (o.deviceModel || ''))}</div>
            <div class="text-secondary small mt-2">Serial</div>
            <div>${escapeHtml(o.serialNumber || '-')}</div>
            <div class="text-secondary small mt-2">Problema reportado</div>
            <div>${escapeHtml(o.reportedIssue || '-')}</div>
          </div>
        </div>
      </div>
    </body>
  </html>`
  w.document.open()
  w.document.write(html)
  w.document.close()
}

async function saveStatus() {
  if (!order.value) return
  if (busy.value) return
  busy.value = true
  statusMessage.value = ''
  const res = await apiPatch<{ done: true }, { status: string }>(`/orders/${order.value.id}/status`, {
    status: status.value,
  })
  statusMessage.value = res.ok ? 'Estado actualizado.' : res.error.message
  busy.value = false
  await load()
  await loadHistory()
}

function openStatusModal() {
  showStatusModal.value = true
}

function closeStatusModal() {
  showStatusModal.value = false
}

async function submitStatus(next: string) {
  status.value = next
  showStatusModal.value = false
  await saveStatus()
}

async function remove() {
  if (!order.value) return
  const ok = window.confirm('¿Eliminar esta orden?')
  if (!ok) return
  busy.value = true
  const res = await apiDelete<{ deleted: true }>(`/orders/${order.value.id}`)
  if (!res.ok) {
    message.value = res.error.message
    busy.value = false
    return
  }
  busy.value = false
  await router.push('/orders')
}

onMounted(async () => {
  await loadStatuses()
  await loadAccessories()
  await load()
  await loadHistory()
  await loadReports()
  await loadServicesCatalog()
  await loadOrderServices()
})

function openReportModal() {
  if (!order.value) return
  reportTitleInput.value = ''
  reportDiagnosisInput.value = order.value.diagnosis || ''
  reportProcedureInput.value = order.value.solution || ''
  reportObservationsInput.value = ''
  reportConclusionsInput.value = ''
  reportOpen.value = true
}

async function saveReport() {
  if (!order.value) return
  if (reportBusy.value) return
  reportBusy.value = true
  reportsMessage.value = ''
  const res = await apiPost<{ id: number }, { reportTitle: string; diagnosis?: string; procedureTaken?: string; introduction?: string; conclusion?: string }>(
    `/orders/${order.value.id}/reports`,
    {
      reportTitle: reportTitleInput.value,
      diagnosis: reportDiagnosisInput.value,
      procedureTaken: reportProcedureInput.value,
      introduction: reportObservationsInput.value,
      conclusion: reportConclusionsInput.value,
    },
  )
  reportBusy.value = false
  if (!res.ok) {
    reportsMessage.value = res.error.message
    return
  }
  reportOpen.value = false
  await loadReports()
}

async function removeReport(reportId: number) {
  if (!order.value) return
  const ok = window.confirm('¿Eliminar este informe técnico?')
  if (!ok) return
  reportBusy.value = true
  reportsMessage.value = ''
  const res = await apiDelete<{ deleted: true }>(`/orders/${order.value.id}/reports/${reportId}`)
  reportBusy.value = false
  if (!res.ok) {
    reportsMessage.value = res.error.message
    return
  }
  await loadReports()
}

function escapeHtml(s: string) {
  return (s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;')
    .replace(/\n/g, '<br/>')
}

async function openReport(reportId: number) {
  if (!order.value) return
  reportsMessage.value = ''
  const res = await apiGet<TechnicalReport>(`/orders/${order.value.id}/reports/${reportId}`)
  if (!res.ok) {
    reportsMessage.value = res.error.message
    return
  }
  const r = res.data
  const w = window.open('', '_blank')
  if (!w) {
    reportsMessage.value = 'No se pudo abrir la ventana.'
    return
  }
  const title = `${r.reportTitle}`
  const html = `<!doctype html>
  <html lang="es">
    <head>
      <meta charset="utf-8" />
      <meta name="viewport" content="width=device-width, initial-scale=1" />
      <title>${escapeHtml(title)}</title>
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
      <style>
        @media print { .no-print { display:none !important; } body { background: white; } }
        body { padding: 24px; }
      </style>
    </head>
    <body>
      <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <div class="fw-semibold">${escapeHtml(title)}</div>
        <button class="btn btn-sm btn-dark" onclick="window.print()">Imprimir</button>
      </div>
      <div class="mb-3">
        <div class="text-secondary small">Orden</div>
        <div class="fw-semibold">${escapeHtml(order.value.orderNumber || String(order.value.id))}</div>
        <div class="text-secondary small mt-2">Cliente</div>
        <div>${escapeHtml(order.value.clientName)}</div>
        <div class="text-secondary small mt-2">Equipo</div>
        <div>${escapeHtml((order.value.deviceBrand || '') + ' ' + (order.value.deviceModel || ''))}</div>
      </div>
      <div class="border rounded-3 p-3 mb-3">
        <div class="fw-semibold mb-2">Diagnóstico</div>
        <div>${escapeHtml(r.diagnosis || '-')}</div>
      </div>
      <div class="border rounded-3 p-3 mb-3">
        <div class="fw-semibold mb-2">Procedimiento</div>
        <div>${escapeHtml(r.procedureTaken || '-')}</div>
      </div>
      <div class="border rounded-3 p-3 mb-3">
        <div class="fw-semibold mb-2">Observaciones</div>
        <div>${escapeHtml(r.introduction || '-')}</div>
      </div>
      <div class="border rounded-3 p-3">
        <div class="fw-semibold mb-2">Conclusiones</div>
        <div>${escapeHtml(r.conclusion || '-')}</div>
      </div>
      <div class="text-secondary small mt-3">Creado: ${escapeHtml(r.createdAt)}</div>
    </body>
  </html>`
  w.document.open()
  w.document.write(html)
  w.document.close()
}

function onSelectService() {
  const sid = addServiceId.value ?? 0
  const s = services.value.find((x) => x.id === sid)
  if (!s) {
    addServicePrice.value = ''
    return
  }
  addServicePrice.value = String(s.basePrice ?? 0)
}

async function addService() {
  if (!order.value) return
  const sid = Number(addServiceId.value ?? 0)
  if (!Number.isFinite(sid) || sid <= 0) {
    orderServicesMessage.value = 'Servicio requerido'
    return
  }
  const qty = Number(addServiceQty.value)
  const price = Number(addServicePrice.value)
  if (!Number.isFinite(qty) || qty <= 0) {
    orderServicesMessage.value = 'Cantidad inválida'
    return
  }
  if (!Number.isFinite(price) || price < 0) {
    orderServicesMessage.value = 'Precio inválido'
    return
  }
  orderServicesMessage.value = ''
  const res = await apiPost<{ id: number }, { serviceId: number; quantity: number; servicePrice: number }>(
    `/orders/${order.value.id}/services`,
    { serviceId: sid, quantity: qty, servicePrice: price },
  )
  if (!res.ok) {
    orderServicesMessage.value = res.error.message
    return
  }
  addServiceQty.value = '1'
  await loadOrderServices()
}

async function removeService(itemId: number) {
  if (!order.value) return
  const ok = window.confirm('¿Eliminar este servicio de la orden?')
  if (!ok) return
  orderServicesMessage.value = ''
  const res = await apiDelete<{ deleted: true }>(`/orders/${order.value.id}/services/${itemId}`)
  if (!res.ok) {
    orderServicesMessage.value = res.error.message
    return
  }
  await loadOrderServices()
}
</script>

<template>
  <div class="container-fluid p-3" style="max-width: 1400px">
    <teleport to="body">
      <div v-if="reportOpen" class="position-fixed top-0 start-0 w-100 h-100" style="background: rgba(0,0,0,.5); z-index: 1050;">
        <div class="position-absolute top-50 start-50 translate-middle bg-white rounded-4 p-3 shadow" style="min-width: 360px; max-width: 92vw;">
          <div class="d-flex align-items-start justify-content-between gap-3">
            <div class="fw-semibold">Nuevo Informe Técnico</div>
            <button class="btn-close" type="button" :disabled="reportBusy" @click="reportOpen = false"></button>
          </div>

          <div class="mt-3 row g-2">
            <div class="col-12">
              <label class="form-label">Título</label>
              <input v-model="reportTitleInput" class="form-control" :disabled="reportBusy" />
            </div>
            <div class="col-12">
              <label class="form-label">Diagnóstico</label>
              <textarea v-model="reportDiagnosisInput" class="form-control" rows="3" :disabled="reportBusy"></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Procedimiento</label>
              <textarea v-model="reportProcedureInput" class="form-control" rows="3" :disabled="reportBusy"></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Observaciones</label>
              <textarea v-model="reportObservationsInput" class="form-control" rows="2" :disabled="reportBusy"></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Conclusiones</label>
              <textarea v-model="reportConclusionsInput" class="form-control" rows="2" :disabled="reportBusy"></textarea>
            </div>
          </div>

          <div class="d-flex justify-content-end gap-2 mt-3">
            <button class="btn btn-outline-secondary rounded-pill" type="button" :disabled="reportBusy" @click="reportOpen = false">Cancelar</button>
            <button class="btn btn-dark rounded-pill" type="button" :disabled="reportBusy" @click="saveReport">Guardar</button>
          </div>
        </div>
      </div>
    </teleport>

    <div class="mb-3 d-flex justify-content-between align-items-start flex-wrap gap-3">
      <div>
        <div class="text-muted small">Órdenes</div>
        <div class="d-flex align-items-center gap-2">
          <h3 class="fw-bold mb-0">{{ order?.orderNumber || `WO-${id}` }}</h3>
          <span v-if="status" class="badge rounded-pill px-3 py-2" :style="statusBadgeStyle()">
            {{ orderStatusDisplay.emoji }} {{ orderStatusDisplay.name }}
          </span>
        </div>
        <div class="text-muted small">Detalles completos de la orden de servicio</div>
      </div>

      <div class="d-flex gap-2 flex-wrap align-items-center justify-content-end">
        <div class="position-relative">
          <button class="btn btn-dark rounded-pill px-4" type="button" @click="printOpen = !printOpen">
            <i class="fas fa-print me-2"></i>Imprimir <i class="fas fa-caret-down ms-1"></i>
          </button>
          <div v-if="printOpen" class="dropdown-menu show mt-2 shadow border-0 rounded-4" style="min-width: 220px; right: 0; left: auto;">
            <button class="dropdown-item" type="button" @click="printOpen = false; printOrder()">Orden</button>
            <button class="dropdown-item" type="button" @click="printOpen = false; scrollToSection('reports')">Informe Técnico</button>
          </div>
        </div>

        <button class="btn btn-primary rounded-pill px-4" type="button" @click="scrollToSection('reports')">
          <i class="fas fa-file-lines me-2"></i>Informes
        </button>
        <button v-if="order" class="btn btn-warning rounded-pill px-4" type="button" @click="router.push(`/orders/${order.id}/edit`)">
          <i class="fas fa-pen me-2"></i>Editar
        </button>
        <button class="btn btn-info rounded-pill px-4 text-white" type="button" @click="scrollToSection('parts')">
          <i class="fas fa-screwdriver-wrench me-2"></i>Partes
        </button>
        <button class="btn btn-success rounded-pill px-4" type="button" :disabled="!client?.phone">
          <i class="fab fa-whatsapp me-2"></i>WhatsApp
        </button>
        <button v-if="order" class="btn btn-danger rounded-pill px-4" type="button" :disabled="busy" @click="remove">
          <i class="fas fa-trash me-2"></i>Eliminar
        </button>
        <button class="btn btn-light border-0 rounded-pill px-4 text-muted" type="button" @click="router.push('/orders')">
          <i class="fas fa-arrow-left me-2"></i>Volver
        </button>
      </div>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-3">{{ message }}</div>
    <div v-if="loading" class="text-secondary">Cargando...</div>

    <div v-if="order" class="row g-4">
      <div class="col-lg-8">
        <div class="card border-0 shadow-sm rounded-4 mb-4">
          <div class="card-header bg-white border-0 py-3">
            <div class="fw-bold"><i class="fas fa-circle-info me-2 text-primary no-theme"></i>Estado Actual</div>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-3">
                <div class="text-muted small">ESTADO</div>
                <div class="mt-1">
                  <span class="badge rounded-pill px-3 py-2" :style="statusBadgeStyle()">
                    {{ orderStatusDisplay.emoji }} {{ orderStatusDisplay.name }}
                  </span>
                </div>
              </div>
              <div class="col-md-3">
                <div class="text-muted small">PRIORIDAD</div>
                <div class="mt-1">
                  <span class="badge rounded-pill px-3 py-2 bg-warning text-dark">{{ priorityLabel }}</span>
                </div>
              </div>
              <div class="col-md-3">
                <div class="text-muted small">FECHA DE CREACIÓN</div>
                <div class="fw-semibold mt-1">{{ fmtDateTime(order.createdAt) }}</div>
              </div>
              <div class="col-md-3">
                <div class="text-muted small">ÚLTIMA ACTUALIZACIÓN</div>
                <div class="fw-semibold mt-1">{{ fmtDateTime(order.updatedAt) }}</div>
              </div>
            </div>
            <div class="d-flex justify-content-end mt-3">
              <button class="btn btn-sm btn-dark rounded-pill px-3" type="button" :disabled="busy" @click="openStatusModal">
                Cambiar Estado
              </button>
            </div>
            <div v-if="statusMessage" class="text-muted small mt-2">{{ statusMessage }}</div>
          </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
          <div class="card-header bg-white border-0 py-3">
            <div class="fw-bold"><i class="fas fa-laptop me-2 text-primary no-theme"></i>Información del Dispositivo</div>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6">
                <div class="text-muted small">TIPO DE DISPOSITIVO</div>
                <div class="fw-semibold mt-1">{{ order.deviceTypeName || `ID ${order.deviceTypeId}` }}</div>
              </div>
              <div class="col-md-6">
                <div class="text-muted small">MODELO</div>
                <div class="fw-semibold mt-1">{{ order.deviceModel || '-' }}</div>
              </div>
              <div class="col-md-6">
                <div class="text-muted small">MARCA</div>
                <div class="fw-semibold mt-1">{{ order.deviceBrand || '-' }}</div>
              </div>
              <div class="col-md-6">
                <div class="text-muted small">NÚMERO DE SERIE / IMEI</div>
                <div class="fw-semibold mt-1">{{ order.serialNumber || '-' }}</div>
              </div>
              <div class="col-md-6">
                <div class="text-muted small">CONTRASEÑA/PIN</div>
                <div class="fw-semibold mt-1">{{ order.devicePassword || 'No especificada' }}</div>
              </div>
              <div class="col-md-6">
                <div class="text-muted small">ACCESORIOS</div>
                <div class="fw-semibold mt-1">{{ accessoriesText || 'No especificados' }}</div>
              </div>
            </div>
          </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
          <div class="card-header bg-white border-0 py-3">
            <div class="fw-bold"><i class="fas fa-triangle-exclamation me-2 text-primary no-theme"></i>Detalles del Problema</div>
          </div>
          <div class="card-body">
            <div class="text-muted small">FALLA REPORTADA</div>
            <div class="fw-semibold mt-1">{{ order.reportedIssue || '-' }}</div>
            <div class="text-muted small mt-3">OBSERVACIONES</div>
            <div class="fw-semibold mt-1">{{ order.clientObservations || '-' }}</div>
          </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
          <div class="card-header bg-white border-0 py-3">
            <div class="fw-bold"><i class="fas fa-stethoscope me-2 text-primary no-theme"></i>Diagnóstico Técnico</div>
          </div>
          <div class="card-body">
            <div class="text-muted small">DIAGNÓSTICO</div>
            <div class="fw-semibold mt-1">{{ order.diagnosis || '-' }}</div>
            <div class="text-muted small mt-3">SOLUCIÓN</div>
            <div class="fw-semibold mt-1">{{ order.solution || '-' }}</div>
            <div class="text-muted small mt-3">NOTAS INTERNAS</div>
            <div class="fw-semibold mt-1">{{ order.technicianNotes || '-' }}</div>
          </div>
        </div>

        <div id="parts" class="card border-0 shadow-sm rounded-4 mb-4">
          <div class="card-header bg-white border-0 py-3">
            <div class="fw-bold"><i class="fas fa-screwdriver-wrench me-2 text-primary no-theme"></i>Servicios de la Orden</div>
          </div>
          <div class="card-body">
            <div class="row g-2 align-items-end">
              <div class="col-12 col-md-6">
                <label class="form-label">Servicio</label>
                <select v-model.number="addServiceId" class="form-select" @change="onSelectService">
                  <option :value="null">Selecciona...</option>
                  <option v-for="s in services" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label">Cantidad</label>
                <input v-model="addServiceQty" class="form-control" type="number" min="0" step="0.01" />
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label">Precio</label>
                <input v-model="addServicePrice" class="form-control" type="number" min="0" step="0.01" />
              </div>
              <div class="col-12 col-md-2 d-grid">
                <button class="btn btn-dark rounded-pill" type="button" @click="addService">Agregar</button>
              </div>
            </div>

            <div v-if="orderServicesMessage" class="alert alert-warning border-0 shadow-sm mt-3 mb-0">{{ orderServicesMessage }}</div>

            <div class="table-responsive mt-3">
              <table class="table align-middle">
                <thead class="text-muted">
                  <tr>
                    <th>Servicio</th>
                    <th class="text-end">Qty</th>
                    <th class="text-end">Precio</th>
                    <th class="text-end">Total</th>
                    <th>Creado</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="it in orderServices" :key="it.id">
                    <td class="fw-semibold">{{ it.serviceName || `Servicio #${it.serviceId}` }}</td>
                    <td class="text-end">{{ it.quantity }}</td>
                    <td class="text-end">{{ fmtMoney(it.servicePrice) }}</td>
                    <td class="text-end">{{ fmtMoney(it.totalPrice) }}</td>
                    <td>{{ fmtDateTime(it.createdAt) }}</td>
                    <td class="text-end">
                      <button class="btn btn-sm btn-outline-danger rounded-pill" type="button" @click="removeService(it.id)">Eliminar</button>
                    </td>
                  </tr>
                  <tr v-if="orderServices.length === 0">
                    <td colspan="6" class="text-secondary">Sin servicios</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div id="reports" class="card border-0 shadow-sm rounded-4 mb-4">
          <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="fw-bold"><i class="fas fa-file-lines me-2 text-primary no-theme"></i>Informes Técnicos</div>
            <button class="btn btn-outline-dark rounded-pill" type="button" :disabled="reportBusy" @click="openReportModal">Nuevo</button>
          </div>
          <div class="card-body">
            <div v-if="reportsMessage" class="alert alert-warning border-0 shadow-sm mb-3">{{ reportsMessage }}</div>
            <div class="table-responsive">
              <table class="table align-middle">
                <thead class="text-muted">
                  <tr>
                    <th>ID</th>
                    <th>Título</th>
                    <th>Creado</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="r in reports" :key="r.id">
                    <td>{{ r.id }}</td>
                    <td class="fw-semibold">{{ r.reportTitle }}</td>
                    <td>{{ fmtDateTime(r.createdAt) }}</td>
                    <td class="text-end">
                      <div class="d-flex gap-2 justify-content-end flex-wrap">
                        <button class="btn btn-sm btn-outline-dark rounded-pill" type="button" @click="openReport(r.id)">Ver / Imprimir</button>
                        <button class="btn btn-sm btn-outline-danger rounded-pill" type="button" :disabled="reportBusy" @click="removeReport(r.id)">Eliminar</button>
                      </div>
                    </td>
                  </tr>
                  <tr v-if="reports.length === 0">
                    <td colspan="4" class="text-secondary">Sin informes</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="card border-0 shadow-sm rounded-4 mb-4">
          <div class="card-header bg-white border-0 py-3">
            <div class="fw-bold"><i class="fas fa-user me-2 text-primary no-theme"></i>Información del Cliente</div>
          </div>
          <div class="card-body">
            <div v-if="client" class="d-flex align-items-center gap-3">
              <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                <i class="fas fa-user text-primary"></i>
              </div>
              <div class="flex-grow-1">
                <div class="fw-bold">{{ displayClientName(client) }}</div>
                <div class="text-muted small">{{ (client.clientType || '').toLowerCase() === 'company' ? 'EMPRESA' : 'PERSONA NATURAL' }}</div>
              </div>
              <button class="btn btn-sm btn-outline-dark rounded-pill" type="button" @click="router.push(`/clients/${client.id}`)">Ver</button>
            </div>
            <div v-else class="text-muted small">Sin información del cliente.</div>

            <div class="mt-3">
              <div class="text-muted small">IDENTIFICACIÓN</div>
              <div class="fw-semibold">{{ client?.idNumber || '-' }}</div>
              <div class="text-muted small mt-2">TELÉFONO</div>
              <div class="fw-semibold">{{ client?.phone || '-' }}</div>
              <div class="text-muted small mt-2">EMAIL</div>
              <div class="fw-semibold">{{ client?.email || '-' }}</div>
            </div>
          </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
          <div class="card-header bg-white border-0 py-3">
            <div class="fw-bold"><i class="fas fa-clock-rotate-left me-2 text-primary no-theme"></i>Historial de Estados</div>
          </div>
          <div class="card-body">
            <div v-if="history.length === 0" class="text-muted small">No hay historial de estados disponible.</div>
            <div v-else class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="text-muted">
                  <tr>
                    <th>Estado</th>
                    <th class="text-end">Fecha</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="h in history" :key="h.id">
                    <td>{{ statuses.find((x) => x.slug === h.status)?.name || h.status }}</td>
                    <td class="text-end">{{ fmtDateTime(h.createdAt) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4">
          <div class="card-header bg-white border-0 py-3">
            <div class="fw-bold"><i class="fas fa-wallet me-2 text-primary no-theme"></i>Costos</div>
          </div>
          <div class="card-body">
            <div class="d-flex justify-content-between">
              <div class="text-muted small">Estimado</div>
              <div class="fw-semibold">{{ fmtMoney(order.estimatedCost) }}</div>
            </div>
            <div class="d-flex justify-content-between mt-2">
              <div class="text-muted small">Final</div>
              <div class="fw-semibold">{{ fmtMoney(order.finalCost) }}</div>
            </div>
            <div class="d-flex justify-content-between mt-2">
              <div class="text-muted small">Abono</div>
              <div class="fw-semibold">{{ fmtMoney(order.advancePayment) }}</div>
            </div>
            <div class="d-flex justify-content-between mt-2">
              <div class="text-muted small">Pago</div>
              <div class="fw-semibold">{{ order.paymentMethod || '-' }}</div>
            </div>
            <div v-if="order.paymentReference" class="d-flex justify-content-between mt-2">
              <div class="text-muted small">Referencia</div>
              <div class="fw-semibold">{{ order.paymentReference }}</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <ChangeStatusModal :open="showStatusModal" :statuses="statuses" :current="status" @close="closeStatusModal" @submit="submitStatus" />
  </div>
</template>
