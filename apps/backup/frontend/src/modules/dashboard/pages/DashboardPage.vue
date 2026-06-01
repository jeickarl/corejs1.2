<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { apiGet, apiPost } from '../../../api/http'

type HealthDto = { service: string; status: 'ok' }
type ClientsStatsDto = {
  totalClients: number
  individualClients: number
  companyClients: number
  recentClients: number
}

type DashboardLowStockItem = { name: string; currentStock: number; minStock: number }
type DashboardOrder = {
  id: number
  orderNumber: string
  clientName: string
  phone: string
  deviceBrand: string
  deviceModel: string
  status: string
  createdAt: string
  completedAt: string
  totalAmount: number
  daysOpen: number
  priority: string
  accessories: string
}

type DashboardSummary = {
  totalOrders: number
  pendingOrders: number
  totalClients: number
  revenue: number
  ordersTrendPct: number
  salesTrendPct: number
  lowStockItems: DashboardLowStockItem[]
  recentOrders: DashboardOrder[]
  stagnantOrders: DashboardOrder[]
  readyOrders: DashboardOrder[]
}

type NotesDto = { content: string }
type NotesSavedDto = { done: true; timestamp: string }

type SearchItem = { type: 'order' | 'client' | 'product'; url: string; title: string; subtitle: string; icon: string }

type SalesChart = { labels: string[]; current: number[]; previous: number[]; kpi: { avg: number; max: number; total: number } }
type OrdersChart = { labels: string[]; values: number[] }

type Analytics = {
  topProducts: { productId: number; name: string; quantity: number; revenue: number }[]
  topClients: { clientId: number; name: string; invoicesCount: number; totalAmount: number }[]
  alerts: { lowStockCount: number; waitingApprovalCount: number }
}

const health = ref<string>('cargando...')
const clients = ref<ClientsStatsDto | null>(null)
const summary = ref<DashboardSummary | null>(null)
const revenuePeriod = ref<'day' | 'week' | 'month' | 'year' | 'total'>('month')

const salesDays = ref(14)
const salesChart = ref<SalesChart | null>(null)
const ordersChart = ref<OrdersChart | null>(null)
const analytics = ref<Analytics | null>(null)

const notes = ref('')
const notesMessage = ref('')
const savingNotes = ref(false)

const searchQuery = ref('')
const searchType = ref<'orders' | 'clients' | 'inventory'>('orders')
const searchResults = ref<SearchItem[]>([])
const searchMessage = ref('')

const revenueLabel = computed(() => {
  if (revenuePeriod.value === 'day') return 'Hoy'
  if (revenuePeriod.value === 'week') return 'Semana'
  if (revenuePeriod.value === 'month') return 'Mes'
  if (revenuePeriod.value === 'year') return 'Año'
  return 'Total'
})

function fmtPct(v: number): string {
  if (!Number.isFinite(v)) return '0%'
  const sign = v >= 0 ? '+' : ''
  return `${sign}${v.toFixed(1)}%`
}

function linePoints(values: number[], w = 600, h = 200): string {
  if (!values.length) return ''
  const max = Math.max(1, ...values.map((v) => (Number.isFinite(v) ? v : 0)))
  const step = values.length === 1 ? 0 : w / (values.length - 1)
  return values
    .map((v, i) => {
      const x = i * step
      const y = h - (Math.max(0, Number(v) || 0) / max) * h
      return `${x.toFixed(2)},${y.toFixed(2)}`
    })
    .join(' ')
}

async function loadSummary() {
  const res = await apiGet<DashboardSummary>(`/dashboard/summary?revenuePeriod=${revenuePeriod.value}`)
  summary.value = res.ok ? res.data : null
}

async function loadSalesChart() {
  const res = await apiGet<SalesChart>(`/dashboard/sales-chart?days=${salesDays.value}`)
  salesChart.value = res.ok ? res.data : null
}

async function loadOrdersChart() {
  const res = await apiGet<OrdersChart>('/dashboard/orders-chart')
  ordersChart.value = res.ok ? res.data : null
}

async function loadAnalytics() {
  const today = new Date()
  const to = today.toISOString().slice(0, 10)
  const from = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 30).toISOString().slice(0, 10)
  const qs = new URLSearchParams({ from, to })
  const res = await apiGet<Analytics>(`/dashboard/analytics?${qs.toString()}`)
  analytics.value = res.ok ? res.data : null
}

async function loadNotes() {
  const res = await apiGet<NotesDto>('/dashboard/notes')
  notes.value = res.ok ? res.data.content : ''
}

async function saveNotes() {
  if (savingNotes.value) return
  savingNotes.value = true
  notesMessage.value = ''
  const res = await apiPost<NotesSavedDto, { content: string }>('/dashboard/notes', { content: notes.value })
  notesMessage.value = res.ok ? `Guardado: ${res.data.timestamp}` : res.error.message
  savingNotes.value = false
}

async function runSearch() {
  searchMessage.value = ''
  searchResults.value = []
  const q = searchQuery.value.trim()
  if (q.length < 2) return
  const qs = new URLSearchParams({ query: q, type: searchType.value })
  const res = await apiGet<SearchItem[]>(`/dashboard/search?${qs.toString()}`)
  if (!res.ok) {
    searchMessage.value = res.error.message
    return
  }
  searchResults.value = res.data
}

onMounted(async () => {
  const res = await apiGet<HealthDto>('/health')
  health.value = res.ok ? `${res.data.service}: ${res.data.status}` : res.error.message

  const resClients = await apiGet<ClientsStatsDto>('/clients/stats')
  clients.value = resClients.ok ? resClients.data : null

  await loadSummary()
  await loadSalesChart()
  await loadOrdersChart()
  await loadAnalytics()
  await loadNotes()
})

watch(revenuePeriod, () => {
  void loadSummary()
})

watch(salesDays, () => {
  void loadSalesChart()
})
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <h5 class="fw-semibold mb-2">Dashboard</h5>
        <div class="text-secondary">API: {{ health }}</div>
      </div>
    </div>

    <div class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
          <div>
            <h6 class="fw-semibold mb-1">Resumen</h6>
            <div class="text-secondary small">KPIs y alertas del negocio</div>
          </div>
          <div class="d-flex gap-2 align-items-center flex-wrap">
            <div class="text-secondary small">Ingresos:</div>
            <select v-model="revenuePeriod" class="form-select form-select-sm" style="max-width: 180px;">
              <option value="day">Hoy</option>
              <option value="week">Semana</option>
              <option value="month">Mes</option>
              <option value="year">Año</option>
              <option value="total">Total</option>
            </select>
          </div>
        </div>

        <div v-if="summary" class="row g-3 mt-1">
          <div class="col-md-3">
            <div class="border rounded-4 p-3 bg-white">
              <div class="text-secondary small">Órdenes totales</div>
              <div class="fs-5 fw-semibold">{{ summary.totalOrders }}</div>
              <div class="small text-secondary">Tendencia 7d: {{ fmtPct(summary.ordersTrendPct) }}</div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="border rounded-4 p-3 bg-white">
              <div class="text-secondary small">En taller</div>
              <div class="fs-5 fw-semibold">{{ summary.pendingOrders }}</div>
              <div class="small text-secondary">Pendientes / en proceso</div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="border rounded-4 p-3 bg-white">
              <div class="text-secondary small">Ingresos ({{ revenueLabel }})</div>
              <div class="fs-5 fw-semibold">{{ summary.revenue }}</div>
              <div class="small text-secondary">Tendencia 7d: {{ fmtPct(summary.salesTrendPct) }}</div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="border rounded-4 p-3 bg-white">
              <div class="text-secondary small">Stock bajo</div>
              <div class="fs-5 fw-semibold">{{ summary.lowStockItems.length }}</div>
              <div class="small text-secondary">Top 5 items</div>
            </div>
          </div>
        </div>
        <div v-else class="text-secondary small mt-3">Sin datos</div>
      </div>
    </div>

    <div class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
          <h6 class="fw-semibold mb-0">Gráficos</h6>
          <div class="d-flex align-items-center gap-2">
            <div class="text-secondary small">Ventas:</div>
            <select v-model.number="salesDays" class="form-select form-select-sm" style="max-width: 160px;">
              <option :value="7">7 días</option>
              <option :value="14">14 días</option>
              <option :value="30">30 días</option>
            </select>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-12 col-lg-8">
            <div class="border rounded-4 p-3 bg-white h-100">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="fw-semibold">Ventas</div>
                <div v-if="salesChart" class="text-secondary small">Total: {{ salesChart.kpi.total }}</div>
              </div>
              <div v-if="salesChart" class="w-100">
                <svg viewBox="0 0 600 200" class="w-100" style="max-height: 240px;">
                  <polyline :points="linePoints(salesChart.previous)" fill="none" stroke="#adb5bd" stroke-width="3" stroke-dasharray="8 6" />
                  <polyline :points="linePoints(salesChart.current)" fill="none" stroke="#0d6efd" stroke-width="3" />
                </svg>
                <div class="d-flex justify-content-between mt-2 text-secondary small">
                  <div>{{ salesChart.labels[0] ?? '' }}</div>
                  <div>{{ salesChart.labels[salesChart.labels.length - 1] ?? '' }}</div>
                </div>
              </div>
              <div v-else class="text-secondary small">Sin datos</div>
            </div>
          </div>
          <div class="col-12 col-lg-4">
            <div class="border rounded-4 p-3 bg-white h-100">
              <div class="fw-semibold mb-2">KPIs</div>
              <div v-if="salesChart" class="d-flex flex-column gap-2">
                <div class="d-flex justify-content-between">
                  <div class="text-secondary small">Promedio</div>
                  <div class="fw-semibold">{{ salesChart.kpi.avg }}</div>
                </div>
                <div class="d-flex justify-content-between">
                  <div class="text-secondary small">Máximo</div>
                  <div class="fw-semibold">{{ salesChart.kpi.max }}</div>
                </div>
                <div class="d-flex justify-content-between">
                  <div class="text-secondary small">Total</div>
                  <div class="fw-semibold">{{ salesChart.kpi.total }}</div>
                </div>
              </div>
              <div v-else class="text-secondary small">Sin datos</div>
            </div>
          </div>

          <div class="col-12">
            <div class="border rounded-4 p-3 bg-white">
              <div class="fw-semibold mb-3">Órdenes por estado</div>
              <div v-if="ordersChart && ordersChart.labels.length" class="d-flex flex-column gap-2">
                <div v-for="(label, i) in ordersChart.labels" :key="label" class="d-flex gap-3 align-items-center">
                  <div style="width: 220px;" class="text-truncate">{{ label }}</div>
                  <div class="flex-grow-1">
                    <div class="progress" style="height: 10px;">
                      <div
                        class="progress-bar"
                        role="progressbar"
                        :style="{
                          width:
                            ((ordersChart.values[i] ?? 0) /
                              Math.max(1, ...ordersChart.values.map((v) => (Number.isFinite(v) ? v : 0)))) *
                              100 +
                            '%',
                        }"
                      ></div>
                    </div>
                  </div>
                  <div style="width: 60px;" class="text-end fw-semibold">{{ ordersChart.values[i] ?? 0 }}</div>
                </div>
              </div>
              <div v-else class="text-secondary small">Sin datos</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <h6 class="fw-semibold mb-3">Top (últimos 30 días)</h6>
        <div v-if="analytics" class="row g-3">
          <div class="col-12 col-lg-4">
            <div class="border rounded-4 p-3 bg-white h-100">
              <div class="fw-semibold mb-2">Alertas</div>
              <div class="d-flex flex-column gap-2">
                <div class="d-flex justify-content-between">
                  <div class="text-secondary small">Stock bajo</div>
                  <div class="fw-semibold">{{ analytics.alerts.lowStockCount }}</div>
                </div>
                <div class="d-flex justify-content-between">
                  <div class="text-secondary small">Esperando aprobación</div>
                  <div class="fw-semibold">{{ analytics.alerts.waitingApprovalCount }}</div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-12 col-lg-4">
            <div class="border rounded-4 p-3 bg-white h-100">
              <div class="fw-semibold mb-2">Top productos</div>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead>
                    <tr>
                      <th>Producto</th>
                      <th class="text-end">Qty</th>
                      <th class="text-end">Total</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="p in analytics.topProducts" :key="p.productId">
                      <td class="text-truncate" style="max-width: 180px;">{{ p.name || `#${p.productId}` }}</td>
                      <td class="text-end">{{ p.quantity }}</td>
                      <td class="text-end">{{ p.revenue }}</td>
                    </tr>
                    <tr v-if="analytics.topProducts.length === 0">
                      <td colspan="3" class="text-secondary">Sin datos</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <div class="col-12 col-lg-4">
            <div class="border rounded-4 p-3 bg-white h-100">
              <div class="fw-semibold mb-2">Top clientes</div>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead>
                    <tr>
                      <th>Cliente</th>
                      <th class="text-end">Fact.</th>
                      <th class="text-end">Total</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="c in analytics.topClients" :key="c.clientId">
                      <td class="text-truncate" style="max-width: 180px;">{{ c.name || `#${c.clientId}` }}</td>
                      <td class="text-end">{{ c.invoicesCount }}</td>
                      <td class="text-end">{{ c.totalAmount }}</td>
                    </tr>
                    <tr v-if="analytics.topClients.length === 0">
                      <td colspan="3" class="text-secondary">Sin datos</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
        <div v-else class="text-secondary small">Sin datos</div>
      </div>
    </div>

    <div class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <h6 class="fw-semibold mb-3">Clientes</h6>
        <div v-if="clients" class="row g-3">
          <div class="col-md-3">
            <div class="border rounded-4 p-3 bg-white">
              <div class="text-secondary small">Total</div>
              <div class="fs-5 fw-semibold">{{ clients.totalClients }}</div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="border rounded-4 p-3 bg-white">
              <div class="text-secondary small">Individual</div>
              <div class="fs-5 fw-semibold">{{ clients.individualClients }}</div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="border rounded-4 p-3 bg-white">
              <div class="text-secondary small">Empresa</div>
              <div class="fs-5 fw-semibold">{{ clients.companyClients }}</div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="border rounded-4 p-3 bg-white">
              <div class="text-secondary small">Últimos 30 días</div>
              <div class="fs-5 fw-semibold">{{ clients.recentClients }}</div>
            </div>
          </div>
        </div>
        <div v-else class="text-secondary small">Sin datos</div>
      </div>
    </div>

    <div class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <h6 class="fw-semibold mb-3">Búsqueda Global</h6>
        <div class="d-flex gap-2 flex-wrap align-items-center">
          <select v-model="searchType" class="form-select" style="max-width: 200px;">
            <option value="orders">Órdenes</option>
            <option value="clients">Clientes</option>
            <option value="inventory">Inventario</option>
          </select>
          <input v-model="searchQuery" class="form-control" style="max-width: 420px;" placeholder="Buscar..." />
          <button class="btn btn-dark rounded-pill" type="button" @click="runSearch">Buscar</button>
        </div>
        <div v-if="searchMessage" class="alert alert-warning border-0 shadow-sm mt-3 mb-0">
          {{ searchMessage }}
        </div>
        <div v-if="searchResults.length" class="list-group mt-3">
          <a v-for="r in searchResults" :key="r.url" class="list-group-item list-group-item-action" :href="r.url">
            <div class="fw-semibold">{{ r.title }}</div>
            <div class="small text-secondary">{{ r.subtitle }}</div>
          </a>
        </div>
        <div v-else class="text-secondary small mt-3">Escribe mínimo 2 letras.</div>
      </div>
    </div>

    <div class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
          <h6 class="fw-semibold mb-0">Notas Personales</h6>
          <button class="btn btn-sm btn-outline-dark rounded-pill" type="button" :disabled="savingNotes" @click="saveNotes">
            Guardar
          </button>
        </div>
        <textarea v-model="notes" class="form-control" rows="5" placeholder="Escribe tus notas..."></textarea>
        <div v-if="notesMessage" class="text-secondary small mt-2">{{ notesMessage }}</div>
      </div>
    </div>

    <div v-if="summary" class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <h6 class="fw-semibold mb-3">Órdenes Recientes</h6>
        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>ID</th>
                <th>Orden</th>
                <th>Cliente</th>
                <th>Equipo</th>
                <th>Estado</th>
                <th>Accesorios</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="o in summary.recentOrders" :key="o.id">
                <td>{{ o.id }}</td>
                <td>{{ o.orderNumber }}</td>
                <td>{{ o.clientName }}</td>
                <td>{{ o.deviceBrand }} {{ o.deviceModel }}</td>
                <td>{{ o.status }}</td>
                <td class="text-secondary small">{{ o.accessories }}</td>
              </tr>
              <tr v-if="summary.recentOrders.length === 0">
                <td colspan="6" class="text-secondary">Sin datos</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div v-if="summary" class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <h6 class="fw-semibold mb-3">Atención Urgente (+3 días)</h6>
        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>ID</th>
                <th>Orden</th>
                <th>Cliente</th>
                <th>Equipo</th>
                <th>Días</th>
                <th>Prioridad</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="o in summary.stagnantOrders" :key="o.id">
                <td>{{ o.id }}</td>
                <td>{{ o.orderNumber }}</td>
                <td>{{ o.clientName }}</td>
                <td>{{ o.deviceModel }}</td>
                <td>{{ o.daysOpen }}</td>
                <td>{{ o.priority }}</td>
              </tr>
              <tr v-if="summary.stagnantOrders.length === 0">
                <td colspan="6" class="text-secondary">Sin datos</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div v-if="summary" class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <h6 class="fw-semibold mb-3">Listos para Entregar</h6>
        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>ID</th>
                <th>Orden</th>
                <th>Cliente</th>
                <th>Teléfono</th>
                <th>Monto</th>
                <th>Accesorios</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="o in summary.readyOrders" :key="o.id">
                <td>{{ o.id }}</td>
                <td>{{ o.orderNumber }}</td>
                <td>{{ o.clientName }}</td>
                <td>{{ o.phone }}</td>
                <td>{{ o.totalAmount }}</td>
                <td class="text-secondary small">{{ o.accessories }}</td>
              </tr>
              <tr v-if="summary.readyOrders.length === 0">
                <td colspan="6" class="text-secondary">Sin datos</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>
