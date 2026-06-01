<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { apiGet, apiPost } from '../../../api/http'

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
  accessoryIds: number[]
  createdAt: string
  updatedAt: string
}

type SerialLookup = { orderId: number; clientId: number; deviceTypeId: number; deviceBrand: string; deviceModel: string }
type Accessory = { id: number; name: string }
type DeviceType = { id: number; name: string; isActive: boolean }
type Brand = { id: number; name: string; isActive: boolean }
type Model = { id: number; name: string; brandId: number | null; deviceTypeId: number | null; isActive: boolean }
type OrderStatus = { slug: string; name: string; emoji: string; color: string; sortOrder: number }
type PaymentMethodRow = { id: number; name: string; isDefault: boolean; isActive: boolean }

const router = useRouter()

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
const initialStatus = ref('pending')
const estimatedCost = ref<number>(0)
const advancePayment = ref<number>(0)
const paymentMethod = ref('')
const paymentReference = ref('')
const technicianNotes = ref('')
const estimatedCompletion = ref<string>('')

const message = ref('')
const saving = ref(false)
const serialHint = ref('')
const accessories = ref<Accessory[]>([])
const accessoryIds = ref<number[]>([])
const newAccessoryName = ref('')
const accessoryMessage = ref('')
const deviceTypes = ref<DeviceType[]>([])
const brandOptions = ref<Brand[]>([])
const modelOptions = ref<Model[]>([])
const statuses = ref<OrderStatus[]>([])
const paymentMethods = ref<PaymentMethodRow[]>([])
const newAccessoryOpen = ref(false)

function fmtMoney(v: number): string {
  const safe = Number.isFinite(v) ? v : 0
  try {
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(safe)
  } catch {
    return `$ ${Math.round(safe).toLocaleString('es-CO')}`
  }
}

const todayBadge = computed(() => {
  const d = new Date()
  return d.toLocaleDateString('es-CO', { day: '2-digit', month: '2-digit', year: 'numeric' })
})

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

async function save() {
  if (saving.value) return
  message.value = ''
  if (!clientId.value) {
    message.value = 'Selecciona un cliente.'
    return
  }
  saving.value = true

  const body: Record<string, unknown> = {
    clientId: clientId.value,
    deviceTypeId: Number(deviceTypeId.value),
    deviceBrand: deviceBrand.value,
    deviceModel: deviceModel.value,
    devicePassword: devicePassword.value,
    serialNumber: serialNumber.value,
    reportedIssue: reportedIssue.value,
    clientObservations: clientObservations.value,
    status: initialStatus.value,
    priority: priority.value,
    estimatedCost: Number(estimatedCost.value || 0),
    advancePayment: Number(advancePayment.value || 0),
    paymentMethod: paymentMethod.value,
    paymentReference: paymentReference.value,
    technicianNotes: technicianNotes.value,
    estimatedCompletion: estimatedCompletion.value ? estimatedCompletion.value : null,
    accessoryIds: accessoryIds.value,
  }

  const res = await apiPost<Order, Record<string, unknown>>('/orders', body)
  if (!res.ok) {
    message.value = res.error.message
    saving.value = false
    return
  }
  await router.push(`/orders/${res.data.id}`)
}

async function lookupSerial() {
  serialHint.value = ''
  const s = serialNumber.value.trim()
  if (!s) return
  const res = await apiGet<SerialLookup>(`/orders/serial-lookup?serial=${encodeURIComponent(s)}`)
  if (!res.ok) return
  deviceTypeId.value = res.data.deviceTypeId || deviceTypeId.value
  if (!deviceBrand.value) deviceBrand.value = res.data.deviceBrand || ''
  if (!deviceModel.value) deviceModel.value = res.data.deviceModel || ''
  serialHint.value = `Coincidencia en orden #${res.data.orderId}`
}

async function loadAccessories() {
  const res = await apiGet<Accessory[]>('/orders/accessories')
  accessories.value = res.ok ? res.data : []
}

async function loadStatuses() {
  const res = await apiGet<OrderStatus[]>('/orders/statuses')
  statuses.value = res.ok ? res.data : []
  if (statuses.value.length && !initialStatus.value.trim()) initialStatus.value = statuses.value[0].slug
  if (statuses.value.length && initialStatus.value === 'pending' && !statuses.value.some((s) => s.slug === 'pending')) {
    initialStatus.value = statuses.value[0].slug
  }
}

async function loadPaymentMethods() {
  const res = await apiGet<PaymentMethodRow[]>('/settings/payment-methods?onlyActive=1')
  paymentMethods.value = res.ok ? res.data : []
  const def = paymentMethods.value.find((x) => x.isDefault)?.name || paymentMethods.value.find((x) => x.name === 'Efectivo')?.name || ''
  if (!paymentMethod.value.trim()) paymentMethod.value = def
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

watch(deviceBrand, () => {
  void loadBrands()
})

watch(deviceModel, () => {
  void loadModels()
})

watch(deviceTypeId, () => {
  void loadModels()
})

async function loadDeviceTypes() {
  const res = await apiGet<DeviceType[]>('/settings/device-types?onlyActive=1')
  deviceTypes.value = res.ok ? res.data : []
  if (deviceTypes.value.length && (!deviceTypeId.value || deviceTypeId.value <= 0)) {
    deviceTypeId.value = deviceTypes.value[0].id
  }
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

onMounted(async () => {
  await loadAccessories()
  await loadDeviceTypes()
  await loadStatuses()
  await loadPaymentMethods()
})
</script>

<template>
  <div class="container-fluid p-3" style="max-width: 1400px">
    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-3">{{ message }}</div>

    <div class="card card-modern border-0 shadow-sm overflow-hidden">
      <div class="card-body p-4">
        <div class="mb-4 d-flex justify-content-between align-items-center border-bottom pb-3 flex-wrap gap-3">
          <div>
            <div class="text-muted small">Órdenes / Nueva</div>
            <h4 class="fw-bold text-dark mb-0">
              <i class="fas fa-plus-circle me-2 text-primary no-theme"></i>Nueva Orden de Servicio
            </h4>
            <div class="text-muted small">Registro de ingreso al taller</div>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <span class="badge bg-light text-primary border border-primary border-opacity-25 px-3 py-2 rounded-pill no-theme">
              <i class="fas fa-calendar-alt me-1"></i>{{ todayBadge }}
            </span>
            <button class="btn btn-light border-0 rounded-pill px-4 text-muted" type="button" @click="router.push('/orders')">
              <i class="fas fa-times me-2"></i>Cancelar
            </button>
            <button class="btn btn-dark rounded-pill px-4" type="button" :disabled="saving" @click="save">
              <i class="fas fa-save me-2"></i>Guardar
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
                    <select v-model="initialStatus" class="form-select bg-light border-0 rounded-pill px-3">
                      <option v-for="s in statuses" :key="s.slug" :value="s.slug">{{ s.name }}</option>
                      <option v-if="statuses.length === 0" value="pending">Pendiente</option>
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
                    <input v-model="serialNumber" type="text" class="form-control bg-light border-0 rounded-pill px-3" placeholder="S/N, IMEI o Service Tag" @blur="lookupSerial" />
                    <div v-if="serialHint" class="small text-muted mt-2 ms-2">{{ serialHint }}</div>
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

                  <div class="col-12">
                    <label class="form-label text-muted fw-bold small text-uppercase ms-2">Clave de Acceso (PIN, Patrón o Contraseña)</label>
                    <input v-model="devicePassword" type="text" class="form-control bg-light border-0 rounded-pill px-3" placeholder="Opcional" />
                  </div>
                </div>
              </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4">
              <div class="card-header bg-white border-0 py-3">
                <div class="fw-bold"><i class="fas fa-triangle-exclamation me-2 text-primary no-theme"></i>Detalles del Servicio</div>
              </div>
              <div class="card-body">
                <div class="mb-3">
                  <label class="form-label text-muted fw-bold small text-uppercase ms-2">Falla Reportada <span class="text-danger">*</span></label>
                  <textarea v-model="reportedIssue" class="form-control bg-light border-0 rounded-4 p-3" rows="3" placeholder="¿Qué falla presenta el equipo?"></textarea>
                </div>
                <div class="mb-3">
                  <label class="form-label text-muted fw-bold small text-uppercase ms-2">Observaciones</label>
                  <textarea v-model="clientObservations" class="form-control bg-light border-0 rounded-4 p-3" rows="2" placeholder="Observaciones para el cliente."></textarea>
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
                  <div class="col-12">
                    <label class="form-label text-muted fw-bold small text-uppercase ms-2">Costo Estimado</label>
                    <div class="input-group rounded-pill overflow-hidden border border-light">
                      <span class="input-group-text bg-light border-0 text-muted px-3">$</span>
                      <input v-model.number="estimatedCost" type="number" min="0" class="form-control border-0 bg-light" placeholder="0" />
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
                  <div class="col-12">
                    <label class="form-label text-muted fw-bold small text-uppercase ms-2">Total Abonado</label>
                    <div class="input-group rounded-pill overflow-hidden border border-light">
                      <span class="input-group-text bg-light border-0 text-muted px-3">$</span>
                      <input v-model.number="advancePayment" type="number" min="0" class="form-control border-0 bg-light" placeholder="0" />
                    </div>
                  </div>
                  <div class="col-12">
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
                        <div class="fw-bold">{{ fmtMoney(Math.max(Number(estimatedCost || 0) - Number(advancePayment || 0), 0)) }}</div>
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
              <span class="h6 mb-0 fw-bold text-dark">{{ fmtMoney(Math.max(Number(estimatedCost || 0) - Number(advancePayment || 0), 0)) }}</span>
            </div>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-light border-0 rounded-pill px-4 fw-bold text-muted" type="button" @click="router.push('/orders')">
              <i class="fas fa-times me-2"></i>Descartar
            </button>
            <button class="btn btn-dark rounded-pill px-5 fw-bold shadow-sm" type="button" :disabled="saving" @click="save">
              <i class="fas fa-save me-2"></i>Crear Orden
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
