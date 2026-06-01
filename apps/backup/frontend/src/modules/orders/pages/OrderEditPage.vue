<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiGet, apiPatch, apiPost } from '../../../api/http'

type ClientLite = { id: number; firstName: string; companyName: string; phone: string; email: string; idNumber: string; createdAt: string }
type ClientsPage = { items: ClientLite[]; page: number; perPage: number; total: number }

type Order = {
  id: number
  orderNumber: string
  clientId: number
  clientName: string
  deviceTypeId: number
  deviceBrand: string
  deviceModel: string
  devicePassword: string
  serialNumber: string
  reportedIssue: string
  clientObservations: string
  status: string
  approvalStatus: string
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

type Accessory = { id: number; name: string }
type DeviceType = { id: number; name: string; isActive: boolean }
type Brand = { id: number; name: string; isActive: boolean }
type Model = { id: number; name: string; brandId: number | null; deviceTypeId: number | null; isActive: boolean }
type PaymentMethodRow = { id: number; name: string; isDefault: boolean; isActive: boolean }
type OrderStatus = { slug: string; name: string; emoji: string; color: string; sortOrder: number }

const route = useRoute()
const router = useRouter()
const id = computed(() => Number(route.params.id))

const loading = ref(false)
const saving = ref(false)
const message = ref('')

const orderNumber = ref('')
const status = ref('')
const originalStatus = ref('')
const createdAt = ref('')
const updatedAt = ref('')

const clientSearch = ref('')
const clientOptions = ref<ClientLite[]>([])
const clientId = ref<number | null>(null)
const selectedClient = ref<ClientLite | null>(null)
const clientLabel = computed(() => {
  const c = selectedClient.value
  if (!c) return ''
  return c.companyName || c.firstName || `Cliente #${c.id}`
})

const deviceTypeId = ref<number>(0)
const deviceBrand = ref('')
const deviceModel = ref('')
const devicePassword = ref('')
const serialNumber = ref('')
const reportedIssue = ref('')
const clientObservations = ref('')
const priority = ref<'low' | 'medium' | 'high' | 'urgent'>('medium')
const estimatedCost = ref<number>(0)
const finalCost = ref<number>(0)
const advancePayment = ref<number>(0)
const paymentMethod = ref('')
const paymentReference = ref('')
const technicianNotes = ref('')
const diagnosis = ref('')
const solution = ref('')
const estimatedCompletion = ref<string>('')

const accessories = ref<Accessory[]>([])
const accessoryIds = ref<number[]>([])
const newAccessoryName = ref('')
const accessoryMessage = ref('')
const newAccessoryOpen = ref(false)

const deviceTypes = ref<DeviceType[]>([])
const brandOptions = ref<Brand[]>([])
const modelOptions = ref<Model[]>([])
const paymentMethods = ref<PaymentMethodRow[]>([])
const statuses = ref<OrderStatus[]>([])

const pendingBalance = computed(() => {
  const bal = Number(estimatedCost.value || 0) - Number(advancePayment.value || 0)
  return bal > 0 ? bal : 0
})

function fmtMoney(v: number): string {
  const safe = Number.isFinite(v) ? v : 0
  try {
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(safe)
  } catch {
    return `$ ${Math.round(safe).toLocaleString('es-CO')}`
  }
}

async function loadClients() {
  const q = clientSearch.value.trim()
  if (!q) {
    clientOptions.value = []
    return
  }
  const qs = new URLSearchParams({ search: q, page: '1', perPage: '10' })
  const res = await apiGet<ClientsPage>(`/clients?${qs.toString()}`)
  clientOptions.value = res.ok ? res.data.items : []
}

watch(clientSearch, () => {
  void loadClients()
})

function pickClient(c: ClientLite) {
  selectedClient.value = c
  clientId.value = c.id
  clientSearch.value = c.companyName || c.firstName || `Cliente #${c.id}`
  clientOptions.value = []
}

async function loadSelectedClientById(nextClientId: number) {
  const res = await apiGet<ClientLite>(`/clients/${nextClientId}`)
  if (!res.ok) {
    selectedClient.value = null
    return
  }
  selectedClient.value = res.data
  clientSearch.value = res.data.companyName || res.data.firstName || `Cliente #${res.data.id}`
}

async function loadAccessories() {
  const res = await apiGet<Accessory[]>('/orders/accessories')
  accessories.value = res.ok ? res.data : []
}

async function loadDeviceTypes() {
  const res = await apiGet<DeviceType[]>('/settings/device-types?onlyActive=1')
  deviceTypes.value = res.ok ? res.data : []
  if (deviceTypes.value.length && (!deviceTypeId.value || deviceTypeId.value <= 0)) {
    deviceTypeId.value = deviceTypes.value[0].id
  }
}

async function loadPaymentMethods() {
  const res = await apiGet<PaymentMethodRow[]>('/settings/payment-methods?onlyActive=1')
  paymentMethods.value = res.ok ? res.data : []
  const def = paymentMethods.value.find((x) => x.isDefault)?.name || paymentMethods.value.find((x) => x.name === 'Efectivo')?.name || ''
  if (!paymentMethod.value.trim()) paymentMethod.value = def
}

async function loadStatuses() {
  const res = await apiGet<OrderStatus[]>('/orders/statuses')
  statuses.value = res.ok ? res.data : []
}

async function loadBrands() {
  const q = deviceBrand.value.trim()
  if (!q) {
    brandOptions.value = []
    return
  }
  const res = await apiGet<Brand[]>(`/settings/brands?onlyActive=1&search=${encodeURIComponent(q)}`)
  brandOptions.value = res.ok ? res.data : []
}

async function loadModels() {
  const q = deviceModel.value.trim()
  if (!q) {
    modelOptions.value = []
    return
  }
  const brandName = deviceBrand.value.trim().toLowerCase()
  const brand = brandName ? brandOptions.value.find((b) => b.name.toLowerCase() === brandName) : undefined
  const qs = new URLSearchParams({ onlyActive: '1', search: q })
  if (brand?.id) qs.set('brandId', String(brand.id))
  if (deviceTypeId.value && deviceTypeId.value > 0) qs.set('deviceTypeId', String(deviceTypeId.value))
  const res = await apiGet<Model[]>(`/settings/models?${qs.toString()}`)
  modelOptions.value = res.ok ? res.data : []
}

async function addAccessory() {
  accessoryMessage.value = ''
  const name = newAccessoryName.value.trim()
  if (!name) return
  const res = await apiPost<Accessory, { name: string }>('/orders/accessories', { name })
  if (!res.ok) {
    accessoryMessage.value = res.error.message
    return
  }
  const exists = accessories.value.some((a) => a.id === res.data.id)
  if (!exists) accessories.value = [res.data, ...accessories.value]
  if (!accessoryIds.value.includes(res.data.id)) accessoryIds.value = [...accessoryIds.value, res.data.id]
  newAccessoryName.value = ''
}

async function load() {
  loading.value = true
  message.value = ''
  const res = await apiGet<Order>(`/orders/${id.value}`)
  if (!res.ok) {
    message.value = res.error.message
    loading.value = false
    return
  }
  const o = res.data
  orderNumber.value = o.orderNumber || ''
  status.value = o.status || ''
  originalStatus.value = o.status || ''
  createdAt.value = o.createdAt || ''
  updatedAt.value = o.updatedAt || ''

  clientId.value = o.clientId
  deviceTypeId.value = o.deviceTypeId || deviceTypeId.value
  deviceBrand.value = o.deviceBrand || ''
  deviceModel.value = o.deviceModel || ''
  devicePassword.value = o.devicePassword || ''
  serialNumber.value = o.serialNumber || ''
  reportedIssue.value = o.reportedIssue || ''
  clientObservations.value = o.clientObservations || ''
  priority.value = (o.priority as 'low' | 'medium' | 'high' | 'urgent') || 'medium'
  estimatedCost.value = Number(o.estimatedCost || 0)
  finalCost.value = Number(o.finalCost || 0)
  advancePayment.value = Number(o.advancePayment || 0)
  paymentMethod.value = o.paymentMethod || ''
  paymentReference.value = o.paymentReference || ''
  technicianNotes.value = o.technicianNotes || ''
  diagnosis.value = o.diagnosis || ''
  solution.value = o.solution || ''
  accessoryIds.value = Array.isArray(o.accessoryIds) ? o.accessoryIds : []
  estimatedCompletion.value = o.estimatedCompletion ? String(o.estimatedCompletion) : ''

  if (o.clientId) await loadSelectedClientById(o.clientId)
  loading.value = false
}

async function save() {
  if (saving.value) return
  message.value = ''
  if (!clientId.value) {
    message.value = 'Selecciona un cliente.'
    return
  }
  saving.value = true

  const body: Record<string, unknown> = {
    clientId: Number(clientId.value),
    deviceTypeId: Number(deviceTypeId.value),
    deviceBrand: deviceBrand.value,
    deviceModel: deviceModel.value,
    devicePassword: devicePassword.value,
    serialNumber: serialNumber.value,
    reportedIssue: reportedIssue.value,
    clientObservations: clientObservations.value,
    priority: priority.value,
    estimatedCost: Number(estimatedCost.value || 0),
    finalCost: Number(finalCost.value || 0),
    advancePayment: Number(advancePayment.value || 0),
    paymentMethod: paymentMethod.value,
    paymentReference: paymentReference.value,
    technicianNotes: technicianNotes.value,
    diagnosis: diagnosis.value,
    solution: solution.value,
    estimatedCompletion: estimatedCompletion.value ? estimatedCompletion.value : null,
    accessoryIds: accessoryIds.value,
  }

  const res = await apiPatch<Order, Record<string, unknown>>(`/orders/${id.value}`, body)
  if (!res.ok) {
    message.value = res.error.message
    saving.value = false
    return
  }
  if (status.value.trim() && status.value.trim() !== originalStatus.value.trim()) {
    const stRes = await apiPatch<{ done: true }, { status: string }>(`/orders/${id.value}/status`, { status: status.value.trim() })
    if (!stRes.ok) {
      message.value = stRes.error.message
      saving.value = false
      return
    }
  }
  await router.push(`/orders/${res.data.id}`)
}

watch(id, () => {
  void load()
})

watch(deviceBrand, () => {
  void loadBrands()
})

watch(deviceModel, () => {
  void loadModels()
})

watch(deviceTypeId, () => {
  void loadModels()
})

onMounted(async () => {
  await loadAccessories()
  await loadDeviceTypes()
  await loadPaymentMethods()
  await loadStatuses()
  await load()
})
</script>

<template>
  <div class="container-fluid p-3" style="max-width: 1400px">
    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-3">
      {{ message }}
    </div>

    <div v-if="loading" class="text-secondary">Cargando...</div>

    <div v-if="!loading" class="card card-modern border-0 shadow-sm overflow-hidden">
      <div class="card-body p-4">
        <div class="mb-4 d-flex justify-content-between align-items-center border-bottom pb-3 flex-wrap gap-3">
          <div>
            <div class="text-muted small">Órdenes / Editar</div>
            <h4 class="fw-bold text-dark mb-0">
              <i class="fas fa-pen-to-square me-2 text-primary no-theme"></i>Editar {{ orderNumber || `WO-${id}` }}
            </h4>
            <div class="text-muted small">Actualizar información de la orden de servicio</div>
          </div>
          <div class="d-flex gap-2 flex-wrap align-items-center justify-content-end">
            <button class="btn btn-outline-dark rounded-pill px-4" type="button" @click="router.push(`/orders/${id}`)">
              <i class="fas fa-eye me-2"></i>Ver Orden
            </button>
            <button class="btn btn-dark rounded-pill px-4" type="button" :disabled="saving" @click="save">
              <i class="fas fa-save me-2"></i>Guardar Cambios
            </button>
            <button class="btn btn-light border-0 rounded-pill px-4 text-muted" type="button" @click="router.push(`/orders/${id}`)">
              <i class="fas fa-times me-2"></i>Cancelar
            </button>
          </div>
        </div>

        <div class="row g-4">
          <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
              <div class="card-header bg-white border-0 py-3">
                <div class="fw-bold"><i class="fas fa-flag me-2 text-primary no-theme"></i>Estado y Prioridad</div>
              </div>
              <div class="card-body">
                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label text-muted fw-bold small text-uppercase ms-2">Estado</label>
                    <select v-model="status" class="form-select bg-light border-0 rounded-pill px-3">
                      <option v-for="s in statuses" :key="s.slug" :value="s.slug">{{ s.name }}</option>
                      <option v-if="statuses.length === 0" value="pending">Pendiente</option>
                      <option v-if="statuses.length === 0" value="completed">Completado</option>
                      <option v-if="statuses.length === 0" value="delivered">Entregado</option>
                    </select>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label text-muted fw-bold small text-uppercase ms-2">Prioridad</label>
                    <select v-model="priority" class="form-select bg-light border-0 rounded-pill px-3">
                      <option value="low">Baja</option>
                      <option value="medium">Media</option>
                      <option value="high">Alta</option>
                      <option value="urgent">Urgente</option>
                    </select>
                  </div>
                </div>
              </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4 mb-4">
              <div class="card-header bg-white border-0 py-3">
                <div class="fw-bold"><i class="fas fa-laptop me-2 text-primary no-theme"></i>Información del Dispositivo</div>
              </div>
              <div class="card-body">
                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label text-muted fw-bold small text-uppercase ms-2">Tipo de Dispositivo <span class="text-danger">*</span></label>
                    <select v-model.number="deviceTypeId" class="form-select bg-light border-0 rounded-pill px-3">
                      <option v-for="t in deviceTypes" :key="t.id" :value="t.id">{{ t.name }}</option>
                      <option v-if="deviceTypes.length === 0" :value="deviceTypeId || 1">General</option>
                      <option v-if="deviceTypes.length && deviceTypeId && !deviceTypes.some((t) => t.id === deviceTypeId)" :value="deviceTypeId">
                        ID {{ deviceTypeId }}
                      </option>
                    </select>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label text-muted fw-bold small text-uppercase ms-2">N° de Serie / IMEI / TAG <span class="text-danger">*</span></label>
                    <input v-model="serialNumber" type="text" class="form-control bg-light border-0 rounded-pill px-3" placeholder="S/N, IMEI o Service Tag" />
                  </div>

                  <div class="col-md-6">
                    <label class="form-label text-muted fw-bold small text-uppercase ms-2">Marca</label>
                    <div class="position-relative">
                      <input v-model="deviceBrand" type="text" class="form-control bg-light border-0 rounded-pill px-3" placeholder="Marca" autocomplete="off" />
                      <div v-if="brandOptions.length" class="dropdown-menu w-100 shadow-lg border-0 rounded-4 mt-2 show">
                        <button v-for="b in brandOptions.slice(0, 8)" :key="b.id" class="dropdown-item py-2" type="button" @click="deviceBrand = b.name; brandOptions = []">
                          {{ b.name }}
                        </button>
                      </div>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label text-muted fw-bold small text-uppercase ms-2">Modelo</label>
                    <div class="position-relative">
                      <input v-model="deviceModel" type="text" class="form-control bg-light border-0 rounded-pill px-3" placeholder="Modelo" autocomplete="off" />
                      <div v-if="modelOptions.length" class="dropdown-menu w-100 shadow-lg border-0 rounded-4 mt-2 show">
                        <button v-for="m in modelOptions.slice(0, 8)" :key="m.id" class="dropdown-item py-2" type="button" @click="deviceModel = m.name; modelOptions = []">
                          {{ m.name }}
                        </button>
                      </div>
                    </div>
                  </div>

                  <div class="col-md-12">
                    <label class="form-label text-muted fw-bold small text-uppercase ms-2">Clave de Acceso (PIN, Patrón o Contraseña)</label>
                    <input v-model="devicePassword" type="text" class="form-control bg-light border-0 rounded-pill px-3" placeholder="Opcional" />
                  </div>
                </div>
              </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4 mb-4">
              <div class="card-header bg-white border-0 py-3">
                <div class="fw-bold"><i class="fas fa-triangle-exclamation me-2 text-primary no-theme"></i>Detalles del Servicio</div>
              </div>
              <div class="card-body">
                <div class="mb-3">
                  <label class="form-label text-muted fw-bold small text-uppercase ms-2">Falla Reportada <span class="text-danger">*</span></label>
                  <textarea v-model="reportedIssue" class="form-control bg-light border-0 rounded-4 p-3" rows="3" placeholder="¿Qué falla presenta el equipo?"></textarea>
                </div>
                <div class="mb-0">
                  <label class="form-label text-muted fw-bold small text-uppercase ms-2">Observaciones del Cliente</label>
                  <textarea v-model="clientObservations" class="form-control bg-light border-0 rounded-4 p-3" rows="2" placeholder="Observaciones para el cliente."></textarea>
                </div>
              </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4">
              <div class="card-header bg-white border-0 py-3">
                <div class="fw-bold"><i class="fas fa-stethoscope me-2 text-primary no-theme"></i>Diagnóstico Técnico</div>
              </div>
              <div class="card-body">
                <div class="mb-3">
                  <label class="form-label text-muted fw-bold small text-uppercase ms-2">Diagnóstico</label>
                  <textarea v-model="diagnosis" class="form-control bg-light border-0 rounded-4 p-3" rows="3" placeholder="Diagnóstico técnico..."></textarea>
                </div>
                <div class="mb-3">
                  <label class="form-label text-muted fw-bold small text-uppercase ms-2">Solución Realizada</label>
                  <textarea v-model="solution" class="form-control bg-light border-0 rounded-4 p-3" rows="3" placeholder="Solución..."></textarea>
                </div>
                <div class="mb-0">
                  <label class="form-label text-muted fw-bold small text-uppercase ms-2">Notas Internas</label>
                  <textarea v-model="technicianNotes" class="form-control bg-light border-0 rounded-4 p-3" rows="2" placeholder="Notas solo visibles para técnicos"></textarea>
                </div>
              </div>
            </div>
          </div>

          <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
              <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                <div class="fw-bold"><i class="fas fa-user me-2 text-primary no-theme"></i>Cliente</div>
                <button class="btn btn-sm btn-outline-primary rounded-pill" type="button" @click="router.push('/clients/new')">
                  <i class="fas fa-plus me-1"></i>Nuevo Cliente
                </button>
              </div>
              <div class="card-body">
                <div class="position-relative mb-3">
                  <div class="input-group shadow-sm rounded-pill overflow-hidden border border-light">
                    <span class="input-group-text bg-light border-0 text-muted px-3"><i class="fas fa-search"></i></span>
                    <input v-model="clientSearch" type="text" class="form-control border-0 bg-light py-2" placeholder="Buscar cliente..." autocomplete="off" />
                  </div>
                  <div v-if="clientOptions.length" class="dropdown-menu w-100 shadow-lg border-0 rounded-4 mt-2 show" style="max-height: 250px; overflow-y: auto">
                    <button v-for="c in clientOptions" :key="c.id" class="dropdown-item py-2" type="button" @click="pickClient(c)">
                      <div class="fw-semibold text-dark">{{ c.companyName || c.firstName || `Cliente #${c.id}` }}</div>
                      <div class="small text-muted">{{ c.phone }} · {{ c.email }}</div>
                    </button>
                  </div>
                </div>

                <div v-if="selectedClient" class="border rounded-4 p-3 bg-light">
                  <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-white d-flex align-items-center justify-content-center" style="width: 44px; height: 44px">
                      <i class="fas fa-user text-primary"></i>
                    </div>
                    <div class="flex-grow-1">
                      <div class="fw-bold">{{ clientLabel }}</div>
                      <div class="text-muted small">{{ selectedClient.idNumber || '-' }}</div>
                      <div class="text-muted small">{{ selectedClient.phone || '-' }}</div>
                    </div>
                    <button class="btn btn-sm btn-outline-dark rounded-pill" type="button" @click="router.push(`/clients/${selectedClient.id}`)">
                      Ver
                    </button>
                  </div>
                </div>
                <div v-else class="text-muted small">Selecciona un cliente para ver sus datos aquí.</div>
              </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4 mb-4">
              <div class="card-header bg-white border-0 py-3">
                <div class="fw-bold"><i class="fas fa-dollar-sign me-2 text-primary no-theme"></i>Costos y Tiempos</div>
              </div>
              <div class="card-body">
                <div class="row g-3">
                  <div class="col-6">
                    <label class="form-label text-muted fw-bold small text-uppercase ms-2">Costo Estimado</label>
                    <div class="input-group rounded-pill overflow-hidden border border-light">
                      <span class="input-group-text bg-light border-0 text-muted px-3">$</span>
                      <input v-model.number="estimatedCost" type="number" min="0" class="form-control border-0 bg-light" placeholder="0" />
                    </div>
                  </div>
                  <div class="col-6">
                    <label class="form-label text-muted fw-bold small text-uppercase ms-2">Costo Final</label>
                    <div class="input-group rounded-pill overflow-hidden border border-light">
                      <span class="input-group-text bg-light border-0 text-muted px-3">$</span>
                      <input v-model.number="finalCost" type="number" min="0" class="form-control border-0 bg-light" placeholder="0" />
                    </div>
                  </div>
                  <div class="col-12">
                    <label class="form-label text-muted fw-bold small text-uppercase ms-2">Fecha Estimada de Entrega</label>
                    <input v-model="estimatedCompletion" type="date" class="form-control bg-light border-0 rounded-pill px-3" />
                  </div>
                </div>
              </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4 mb-4">
              <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                <div class="fw-bold"><i class="fas fa-box-open me-2 text-primary no-theme"></i>Accesorios</div>
                <button class="btn btn-sm btn-dark no-theme rounded-pill" type="button" @click="newAccessoryOpen = true">
                  <i class="fas fa-plus"></i>
                </button>
              </div>
              <div class="card-body">
                <div class="row g-2">
                  <div v-for="a in accessories" :key="a.id" class="col-6">
                    <div class="form-check">
                      <input :id="`acc-${a.id}`" v-model="accessoryIds" class="form-check-input" type="checkbox" :value="a.id" />
                      <label class="form-check-label small text-truncate w-100" :for="`acc-${a.id}`" :title="a.name">{{ a.name }}</label>
                    </div>
                  </div>
                  <div v-if="accessories.length === 0" class="text-center py-3 text-muted small">Sin accesorios configurados</div>
                </div>
              </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4">
              <div class="card-header bg-white border-0 py-3">
                <div class="fw-bold"><i class="fas fa-hand-holding-dollar me-2 text-primary no-theme"></i>Abono / Anticipo</div>
              </div>
              <div class="card-body">
                <div class="row g-3">
                  <div class="col-6">
                    <label class="form-label text-muted fw-bold small text-uppercase ms-2">Total Abonado</label>
                    <div class="input-group rounded-pill overflow-hidden border border-light">
                      <span class="input-group-text bg-light border-0 text-muted px-3">$</span>
                      <input v-model.number="advancePayment" type="number" min="0" class="form-control border-0 bg-light" placeholder="0" />
                    </div>
                  </div>
                  <div class="col-6">
                    <label class="form-label text-muted fw-bold small text-uppercase ms-2">Método de Pago</label>
                    <select v-model="paymentMethod" class="form-select bg-light border-0 rounded-pill px-3">
                      <option value="">Seleccionar...</option>
                      <option v-for="pm in paymentMethods" :key="pm.id" :value="pm.name">{{ pm.name }}</option>
                    </select>
                  </div>
                  <div class="col-12">
                    <label class="form-label text-muted fw-bold small text-uppercase ms-2">N° de Referencia / Comprobante</label>
                    <input v-model="paymentReference" type="text" class="form-control bg-light border-0 rounded-pill px-3" placeholder="Ej: 123456789" />
                  </div>
                  <div class="col-12">
                    <div class="rounded-4 p-3" style="background: #e7f7ff;">
                      <div class="d-flex justify-content-between align-items-center">
                        <div class="text-muted small fw-bold">Saldo Pendiente</div>
                        <div class="fw-bold">{{ fmtMoney(pendingBalance) }}</div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="d-none d-lg-block" style="height: 90px"></div>
    <div class="fixed-bottom border-top py-3 shadow-lg d-none d-lg-block" style="background: white">
      <div class="container-fluid px-4" style="max-width: 1400px">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
          <div class="d-flex align-items-center gap-4 flex-wrap">
            <div class="d-flex flex-column border-end pe-4">
              <span class="text-uppercase text-muted small fw-bold" style="font-size: 0.65rem; letter-spacing: 1px">Abono</span>
              <span class="h5 mb-0 fw-bold text-success">{{ fmtMoney(advancePayment) }}</span>
            </div>
            <div class="d-flex flex-column border-end pe-4">
              <span class="text-uppercase text-muted small fw-bold" style="font-size: 0.65rem; letter-spacing: 1px">Estimado</span>
              <span class="h6 mb-0 fw-bold text-dark">{{ fmtMoney(estimatedCost) }}</span>
            </div>
            <div class="d-flex flex-column">
              <span class="text-uppercase text-muted small fw-bold" style="font-size: 0.65rem; letter-spacing: 1px">Saldo</span>
              <span class="h6 mb-0 fw-bold text-dark">{{ fmtMoney(pendingBalance) }}</span>
            </div>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-light border-0 rounded-pill px-4 fw-bold text-muted" type="button" @click="router.push(`/orders/${id}`)">
              <i class="fas fa-times me-2"></i>Cancelar
            </button>
            <button class="btn btn-dark rounded-pill px-5 fw-bold shadow-sm" type="button" :disabled="saving" @click="save">
              <i class="fas fa-save me-2"></i>Guardar Cambios
            </button>
          </div>
        </div>
      </div>
    </div>

    <teleport to="body">
      <div v-if="newAccessoryOpen" class="position-fixed top-0 start-0 w-100 h-100" style="background: rgba(0,0,0,.5); z-index: 1050;">
        <div class="position-absolute top-50 start-50 translate-middle bg-white rounded-4 shadow p-3" style="min-width: 320px; max-width: 92vw;">
          <div class="d-flex align-items-center justify-content-between gap-3 mb-2">
            <h5 class="fw-bold mb-0">Nuevo Accesorio</h5>
            <button class="btn btn-sm btn-outline-secondary rounded-pill" type="button" @click="newAccessoryOpen = false">Cerrar</button>
          </div>
          <div class="mb-2">
            <label class="form-label small text-muted text-uppercase fw-bold">Nombre del Accesorio</label>
            <input v-model="newAccessoryName" type="text" class="form-control rounded-pill" placeholder="Ej. Cargador, Funda..." />
          </div>
          <div v-if="accessoryMessage" class="text-danger small mb-2">{{ accessoryMessage }}</div>
          <div class="d-grid">
            <button class="btn btn-dark rounded-pill" type="button" @click="addAccessory">
              <i class="fas fa-plus me-2"></i>Agregar
            </button>
          </div>
        </div>
      </div>
    </teleport>
  </div>
</template>
