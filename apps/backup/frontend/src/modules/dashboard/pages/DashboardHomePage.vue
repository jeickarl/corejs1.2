<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { apiGet, apiPost } from '../../../api/http'
import { useAuthStore } from '../../../store/auth'

type HealthDto = { service: string; status: 'ok' }

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
type SalesChart = { labels: string[]; current: number[]; previous: number[]; kpi: { avg: number; max: number; total: number } }
type OrdersChart = { labels: string[]; values: number[] }

const router = useRouter()
const auth = useAuthStore()

const health = ref('cargando...')
const summary = ref<DashboardSummary | null>(null)
const revenuePeriod = ref<'day' | 'week' | 'month' | 'year' | 'total'>('month')

const salesDays = ref(7)
const compareToggle = ref(false)
const salesChart = ref<SalesChart | null>(null)

const ordersChart = ref<OrdersChart | null>(null)
const ordersLegend = ref<Array<{ label: string; value: number; color: string }>>([])

const activeTab = ref<'recent' | 'urgent' | 'ready'>('recent')

const fabOpen = ref(false)

const notes = ref('')
const notesStatus = ref<'synced' | 'saving' | 'error'>('synced')
const notesSavedAt = ref<string>('')
const notesErrorMessage = ref<string>('')
const notesTimer = ref<number | null>(null)

const salesCanvas = ref<HTMLCanvasElement | null>(null)
const ordersCanvas = ref<HTMLCanvasElement | null>(null)
let salesChartInstance: any = null
let ordersChartInstance: any = null

function fmtMoney(v: number): string {
  const safe = Number.isFinite(v) ? v : 0
  try {
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(safe)
  } catch {
    return `$ ${Math.round(safe).toLocaleString('es-CO')}`
  }
}

function fmtSignedPct(v: number): string {
  if (!Number.isFinite(v)) return '0.0%'
  return `${Math.abs(v).toFixed(1)}%`
}

function fmtTime(iso: string): string {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  return d.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' })
}

const firstName = computed(() => {
  const name = (auth.me?.name ?? '').trim()
  if (!name) return 'Usuario'
  const parts = name.split(' ').filter(Boolean)
  return parts[0] ?? name
})

const greetingBase = computed(() => {
  const hour = new Date().getHours()
  if (hour < 12) return 'Buenos días'
  if (hour < 18) return 'Buenas tardes'
  return 'Buenas noches'
})

const greetingIcon = computed(() => {
  const hour = new Date().getHours()
  if (hour < 12) return 'fa-coffee'
  if (hour < 18) return 'fa-sun'
  return 'fa-moon'
})

const urgentCount = computed(() => summary.value?.stagnantOrders?.length ?? 0)
const readyCount = computed(() => summary.value?.readyOrders?.length ?? 0)

const notesStatusHtml = computed(() => {
  if (notesStatus.value === 'saving') return `<i class="fas fa-sync fa-spin"></i> Guardando...`
  if (notesStatus.value === 'error') {
    const msg = (notesErrorMessage.value ?? '').trim()
    if (!msg) return `<i class="fas fa-exclamation-circle text-danger"></i> Error`
    const short = msg.length > 42 ? `${msg.slice(0, 42)}...` : msg
    return `<i class="fas fa-exclamation-circle text-danger"></i> Error: ${short}`
  }
  if (notesSavedAt.value) return `<i class="fas fa-check-circle text-success"></i> Guardado ${notesSavedAt.value}`
  return `<i class="far fa-check-circle"></i> Sincronizado`
})

function statusText(s: string): string {
  const m: Record<string, string> = {
    received: 'Recibido',
    pending: 'Pendiente',
    diagnosing: 'Diagnóstico',
    waiting_parts: 'Esperando repuestos',
    repairing: 'Reparando',
    testing: 'Probando',
    completed: 'Completado',
    delivered: 'Entregado',
    cancelled: 'Cancelado',
  }
  return m[s] ?? s
}

function statusColor(s: string): string {
  const m: Record<string, string> = {
    received: '#6610f2',
    pending: '#ffc107',
    diagnosing: '#0dcaf0',
    waiting_parts: '#fd7e14',
    repairing: '#0d6efd',
    testing: '#20c997',
    completed: '#198754',
    delivered: '#6c757d',
    cancelled: '#dc3545',
  }
  return m[s] ?? '#6c757d'
}

function destroyCharts() {
  try {
    salesChartInstance?.destroy?.()
  } catch {
  }
  try {
    ordersChartInstance?.destroy?.()
  } catch {
  }
  salesChartInstance = null
  ordersChartInstance = null
}

function syncOrdersLegend() {
  const oc = ordersChart.value
  if (!oc) {
    ordersLegend.value = []
    return
  }
  ordersLegend.value = oc.labels.map((l, i) => ({
    label: statusText(l),
    value: Number(oc.values[i] ?? 0),
    color: statusColor(l),
  }))
}

function ensureCharts() {
  const ChartCtor = (window as any).Chart
  if (!ChartCtor) return

  if (salesCanvas.value && salesChart.value) {
    const ctx = salesCanvas.value.getContext('2d')
    if (ctx) {
      const gradient = ctx.createLinearGradient(0, 0, 0, 300)
      gradient.addColorStop(0, 'rgba(13, 110, 253, 0.25)')
      gradient.addColorStop(1, 'rgba(13, 110, 253, 0.00)')

      const dsPrev = {
        label: 'Periodo Anterior',
        data: salesChart.value.previous,
        borderColor: '#94a3b8',
        borderWidth: 3,
        borderDash: [8, 6],
        pointRadius: 0,
        tension: 0.35,
        fill: false,
        hidden: !compareToggle.value,
      }
      const dsCur = {
        label: 'Periodo Actual',
        data: salesChart.value.current,
        borderColor: '#0d6efd',
        borderWidth: 4,
        pointRadius: 0,
        tension: 0.35,
        fill: true,
        backgroundColor: gradient,
      }

      const data = { labels: salesChart.value.labels, datasets: [dsCur, dsPrev] }
      if (salesChartInstance) {
        salesChartInstance.data = data
        salesChartInstance.update()
      } else {
        salesChartInstance = new ChartCtor(ctx, {
          type: 'line',
          data,
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: true, position: 'bottom' } },
            scales: {
              x: { grid: { display: false } },
              y: { grid: { color: 'rgba(148, 163, 184, 0.25)' }, ticks: { display: true } },
            },
          },
        })
      }
    }
  }

  if (ordersCanvas.value && ordersChart.value) {
    const ctx = ordersCanvas.value.getContext('2d')
    if (ctx) {
      const labels = ordersChart.value.labels.map((s) => statusText(s))
      const colors = ordersChart.value.labels.map((s) => statusColor(s))
      const data = {
        labels,
        datasets: [
          {
            data: ordersChart.value.values,
            backgroundColor: colors,
            borderWidth: 0,
          },
        ],
      }
      if (ordersChartInstance) {
        ordersChartInstance.data = data
        ordersChartInstance.update()
      } else {
        ordersChartInstance = new ChartCtor(ctx, {
          type: 'doughnut',
          data,
          options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            plugins: { legend: { display: false } },
          },
        })
      }
    }
  }
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
  syncOrdersLegend()
}

async function loadNotes() {
  const res = await apiGet<NotesDto>('/dashboard/notes')
  if (!res.ok) {
    notes.value = ''
    notesStatus.value = 'error'
    notesSavedAt.value = ''
    notesErrorMessage.value = res.error.message
    return
  }
  notes.value = res.data.content
  notesStatus.value = 'synced'
  notesSavedAt.value = ''
  notesErrorMessage.value = ''
}

async function saveNotesNow() {
  notesStatus.value = 'saving'
  notesSavedAt.value = ''
  const res = await apiPost<NotesSavedDto, { content: string }>('/dashboard/notes', { content: notes.value })
  if (!res.ok) {
    notesStatus.value = 'error'
    notesErrorMessage.value = res.error.message
    return
  }
  notesStatus.value = 'synced'
  notesSavedAt.value = fmtTime(res.data.timestamp)
  notesErrorMessage.value = ''
}

function onNotesInput() {
  notesStatus.value = 'saving'
  notesSavedAt.value = ''
  notesErrorMessage.value = ''
  if (notesTimer.value) window.clearTimeout(notesTimer.value)
  notesTimer.value = window.setTimeout(() => {
    void saveNotesNow()
  }, 1000)
}

function insertBullet() {
  const el = document.getElementById('quickNotes') as HTMLTextAreaElement | null
  if (!el) return
  const start = el.selectionStart ?? 0
  const end = el.selectionEnd ?? 0
  const text = el.value ?? ''
  const insert = '• '
  el.value = text.substring(0, start) + insert + text.substring(end)
  const pos = start + insert.length
  el.selectionStart = pos
  el.selectionEnd = pos
  notes.value = el.value
  el.focus()
  onNotesInput()
}

function toggleFab() {
  fabOpen.value = !fabOpen.value
}

function closeFabIfClickOutside(e: MouseEvent) {
  if (!fabOpen.value) return
  const container = document.querySelector('.fab-container')
  const t = e.target as Node | null
  if (container && t && !container.contains(t)) fabOpen.value = false
}

function downloadSalesChart() {
  if (!salesCanvas.value) return
  const a = document.createElement('a')
  a.href = salesCanvas.value.toDataURL('image/png')
  a.download = `sales_chart_${salesDays.value}d.png`
  a.click()
}

const calToday = new Date()
const calMonth = ref(calToday.getMonth())
const calYear = ref(calToday.getFullYear())
const calSelected = ref<Date | null>(null)
const calMonthNames = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre']
const calTitle = computed(() => `${calMonthNames[calMonth.value]} ${calYear.value}`)

type Holiday = { date: Date; name: string }

function addDays(date: Date, days: number): Date {
  const r = new Date(date)
  r.setDate(r.getDate() + days)
  return r
}

function getEasterDate(year: number): Date {
  const a = year % 19
  const b = Math.floor(year / 100)
  const c = year % 100
  const d = Math.floor(b / 4)
  const e = b % 4
  const f = Math.floor((b + 8) / 25)
  const g = Math.floor((b - f + 1) / 3)
  const h = (19 * a + b - d - g + 15) % 30
  const i = Math.floor(c / 4)
  const k = c % 4
  const l = (32 + 2 * e + 2 * i - h - k) % 7
  const m = Math.floor((a + 11 * h + 22 * l) / 451)
  const month = Math.floor((h + l - 7 * m + 114) / 31) - 1
  const day = ((h + l - 7 * m + 114) % 31) + 1
  return new Date(year, month, day)
}

function moveToNextMonday(date: Date): Date {
  const day = date.getDay()
  if (day === 1) return date
  let add = 0
  if (day === 0) add = 1
  else add = 8 - day
  return addDays(date, add)
}

function colombiaHolidays(year: number): Holiday[] {
  const holidays: Holiday[] = []
  const fixed = [
    { d: 1, m: 0, n: 'Año Nuevo' },
    { d: 1, m: 4, n: 'Día del Trabajo' },
    { d: 20, m: 6, n: 'Independencia de Colombia' },
    { d: 7, m: 7, n: 'Batalla de Boyacá' },
    { d: 8, m: 11, n: 'Inmaculada Concepción' },
    { d: 25, m: 11, n: 'Navidad' },
  ]
  fixed.forEach((h) => holidays.push({ date: new Date(year, h.m, h.d), name: h.n }))

  const emiliani = [
    { d: 6, m: 0, n: 'Reyes Magos' },
    { d: 19, m: 2, n: 'Día de San José' },
    { d: 29, m: 5, n: 'San Pedro y San Pablo' },
    { d: 15, m: 7, n: 'Asunción de la Virgen' },
    { d: 12, m: 9, n: 'Día de la Raza' },
    { d: 1, m: 10, n: 'Todos los Santos' },
    { d: 11, m: 10, n: 'Independencia de Cartagena' },
  ]
  emiliani.forEach((h) => holidays.push({ date: moveToNextMonday(new Date(year, h.m, h.d)), name: h.n }))

  const easter = getEasterDate(year)
  holidays.push({ date: addDays(easter, -3), name: 'Jueves Santo' })
  holidays.push({ date: addDays(easter, -2), name: 'Viernes Santo' })
  holidays.push({ date: moveToNextMonday(addDays(easter, 39)), name: 'Ascensión del Señor' })
  holidays.push({ date: moveToNextMonday(addDays(easter, 60)), name: 'Corpus Christi' })
  holidays.push({ date: moveToNextMonday(addDays(easter, 68)), name: 'Sagrado Corazón' })

  return holidays
}

const yearHolidays = computed(() => colombiaHolidays(calYear.value))

const calendarCells = computed(() => {
  const firstDay = new Date(calYear.value, calMonth.value, 1)
  const lastDay = new Date(calYear.value, calMonth.value + 1, 0)
  const startingDay = firstDay.getDay()
  const totalDays = lastDay.getDate()

  const cells: Array<{ key: string; label: string; classes: string; title: string; clickable: boolean; day: number }> = []
  for (let i = 0; i < startingDay; i++) {
    cells.push({ key: `e-${i}`, label: '', classes: 'calendar-day empty', title: '', clickable: false, day: 0 })
  }
  for (let d = 1; d <= totalDays; d++) {
    let classes = 'calendar-day'
    let title = ''
    const h = yearHolidays.value.find((x) => x.date.getDate() === d && x.date.getMonth() === calMonth.value && x.date.getFullYear() === calYear.value)
    if (h) {
      classes += ' holiday'
      title = h.name
    }
    if (d === calToday.getDate() && calMonth.value === calToday.getMonth() && calYear.value === calToday.getFullYear()) classes += ' today'
    if (calSelected.value && d === calSelected.value.getDate() && calMonth.value === calSelected.value.getMonth() && calYear.value === calSelected.value.getFullYear())
      classes += ' selected'
    cells.push({ key: `d-${d}`, label: String(d), classes, title, clickable: true, day: d })
  }
  return cells
})

function changeMonth(offset: number) {
  const d = new Date(calYear.value, calMonth.value + offset, 1)
  calYear.value = d.getFullYear()
  calMonth.value = d.getMonth()
}

function selectDate(day: number) {
  calSelected.value = new Date(calYear.value, calMonth.value, day)
}

async function loadAll() {
  await auth.ensureLoaded()
  const res = await apiGet<HealthDto>('/health')
  health.value = res.ok ? `${res.data.service}: ${res.data.status}` : res.error.message
  await loadSummary()
  await loadSalesChart()
  await loadOrdersChart()
  await loadNotes()
  await nextTick()
  ensureCharts()
}

onMounted(() => {
  void loadAll()
  document.addEventListener('click', closeFabIfClickOutside)
})

onBeforeUnmount(() => {
  destroyCharts()
  document.removeEventListener('click', closeFabIfClickOutside)
  if (notesTimer.value) window.clearTimeout(notesTimer.value)
})

watch(revenuePeriod, () => {
  void loadSummary()
})

watch(salesDays, async () => {
  destroyCharts()
  await loadSalesChart()
  await nextTick()
  ensureCharts()
})

watch(compareToggle, async () => {
  if (!salesChartInstance) {
    await nextTick()
    ensureCharts()
    return
  }
  const ds = salesChartInstance.data?.datasets?.[1]
  if (ds) {
    ds.hidden = !compareToggle.value
    salesChartInstance.update()
  }
})

watch(ordersChart, async () => {
  destroyCharts()
  await nextTick()
  ensureCharts()
})

function goOrder(id: number) {
  void router.push(`/orders/${id}`)
}
</script>

<template>
  <div>
    <div class="d-flex flex-column flex-md-row justify-content-start align-items-center mb-4 gap-3">
      <div>
        <h2 class="fw-bold text-dark mb-1">
          <i class="fas text-warning me-2" :class="greetingIcon"></i>{{ greetingBase }}, {{ firstName }}
        </h2>
        <p class="text-muted mb-0">Resumen inteligente de tu negocio.</p>
      </div>
    </div>

    <div v-if="summary" class="row g-3 mb-4">
      <div class="col-12 col-md-4 mb-3 mb-md-0">
        <div class="card border-0 shadow-sm card-hover-scale h-100 bg-gradient-primary-soft">
          <div class="card-body p-3 p-xl-4">
            <div class="d-flex justify-content-between align-items-start mb-3">
              <div>
                <p class="text-primary no-theme fw-bold text-uppercase small ls-1 mb-1" style="font-size: 0.75rem;">Órdenes Totales</p>
                <h2 class="fw-bold text-dark mb-0">{{ summary.totalOrders }}</h2>
              </div>
              <div class="icon-box bg-white shadow-sm text-primary no-theme rounded-circle d-flex align-items-center justify-content-center" style="width: 45px; height: 45px;">
                <i class="fas fa-tools fa-lg"></i>
              </div>
            </div>
            <div class="d-flex align-items-center mt-auto">
              <span v-if="summary.ordersTrendPct >= 0" class="trend-badge trend-up me-2 small"><i class="fas fa-arrow-up"></i> {{ fmtSignedPct(summary.ordersTrendPct) }}</span>
              <span v-else class="trend-badge trend-down me-2 small"><i class="fas fa-arrow-down"></i> {{ fmtSignedPct(summary.ordersTrendPct) }}</span>
              <small class="text-muted d-none d-sm-inline" style="font-size: 0.7rem;">vs ant.</small>
            </div>
          </div>
        </div>
      </div>

      <div class="col-12 col-md-4 mb-3 mb-md-0">
        <div class="card border-0 shadow-sm card-hover-scale h-100 bg-gradient-success-soft">
          <div class="card-body p-3 p-xl-4">
            <div class="d-flex justify-content-between align-items-start mb-3">
              <div class="pe-2">
                <div class="d-flex align-items-center mb-1 flex-wrap gap-1">
                  <p class="text-success fw-bold text-uppercase small ls-1 mb-0 me-2" style="font-size: 0.75rem;">Ingresos</p>
                  <select v-model="revenuePeriod" class="form-select border-0 bg-transparent text-success fw-bold p-0 text-decoration-underline shadow-none" style="font-size: 0.75rem; width: auto; min-width: 60px; max-width: 120px; cursor: pointer; display: inline-block;">
                    <option value="day">Hoy</option>
                    <option value="week">Semana</option>
                    <option value="month">Mensual</option>
                    <option value="year">Año</option>
                    <option value="total">Total</option>
                  </select>
                </div>
                <h3 class="fw-bold text-dark mb-0" style="font-size: 1.5rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 160px;">
                  {{ fmtMoney(summary.revenue) }}
                </h3>
              </div>
              <div class="icon-box bg-white shadow-sm text-success rounded-circle d-flex align-items-center justify-content-center" style="width: 45px; height: 45px; flex-shrink: 0;">
                <i class="fas fa-dollar-sign fa-lg"></i>
              </div>
            </div>
            <div class="d-flex align-items-center mt-auto">
              <span v-if="summary.salesTrendPct >= 0" class="trend-badge trend-up me-2 small"><i class="fas fa-arrow-up"></i> {{ fmtSignedPct(summary.salesTrendPct) }}</span>
              <span v-else class="trend-badge trend-down me-2 small"><i class="fas fa-arrow-down"></i> {{ fmtSignedPct(summary.salesTrendPct) }}</span>
              <small class="text-muted d-none d-sm-inline" style="font-size: 0.7rem;">vs ant.</small>
            </div>
          </div>
        </div>
      </div>

      <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm card-hover-scale h-100 bg-gradient-warning-soft">
          <div class="card-body p-3 p-xl-4">
            <div class="d-flex justify-content-between align-items-start mb-3">
              <div>
                <p class="text-warning fw-bold text-uppercase small ls-1 mb-1" style="font-size: 0.75rem;">En Taller</p>
                <h2 class="fw-bold text-dark mb-0">{{ summary.pendingOrders }}</h2>
              </div>
              <div class="icon-box bg-white shadow-sm text-warning rounded-circle d-flex align-items-center justify-content-center" style="width: 45px; height: 45px;">
                <i class="fas fa-clock fa-lg"></i>
              </div>
            </div>
            <div class="d-flex align-items-center text-muted mt-auto" style="font-size: 0.8rem;">
              <span class="text-dark fw-bold me-1">{{ urgentCount }}</span>
              <span>urgentes</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div id="dashboard-main-row" class="row g-4 mb-4 align-items-start">
      <div class="col-12 col-md-8 col-left">
        <div class="card-modern mb-4">
          <div class="card-header border-0 d-flex flex-column flex-xl-row justify-content-between align-items-center gap-3 py-3">
            <div class="d-flex align-items-center gap-2">
              <div class="bg-primary bg-opacity-10 no-theme p-2 rounded">
                <i class="fas fa-chart-line text-primary"></i>
              </div>
              <h5 class="fw-bold mb-0 text-dark">Rendimiento Financiero</h5>
            </div>

            <div class="d-flex align-items-center flex-wrap gap-3 justify-content-center justify-content-xl-end w-100 w-xl-auto">
              <div class="d-flex gap-3 gap-md-4 text-muted small border-end pe-3">
                <div class="text-end">
                  <span class="d-block text-uppercase fw-bold text-muted" style="font-size: 0.65rem; letter-spacing: 0.5px;">Total</span>
                  <span class="fw-bold text-primary">{{ salesChart ? fmtMoney(salesChart.kpi.total) : '' }}</span>
                </div>
                <div class="text-end d-none d-sm-block">
                  <span class="d-block text-uppercase fw-bold text-muted" style="font-size: 0.65rem; letter-spacing: 0.5px;">Promedio</span>
                  <span class="fw-bold text-dark">{{ salesChart ? fmtMoney(salesChart.kpi.avg) : '' }}</span>
                </div>
                <div class="text-end d-none d-sm-block">
                  <span class="d-block text-uppercase fw-bold text-muted" style="font-size: 0.65rem; letter-spacing: 0.5px;">Mejor Día</span>
                  <span class="fw-bold text-success">{{ salesChart ? fmtMoney(salesChart.kpi.max) : '' }}</span>
                </div>
              </div>

              <div class="d-flex align-items-center gap-3 bg-light rounded-pill px-3 py-1 border">
                <select v-model.number="salesDays" class="form-select form-select-sm border-0 bg-transparent fw-bold text-secondary focus-ring-none py-1 ps-0 pe-4" style="width: auto; cursor: pointer; font-size: 0.85rem;">
                  <option :value="7">Últimos 7 Días</option>
                  <option :value="15">Últimos 15 Días</option>
                  <option :value="30">Últimos 30 Días</option>
                  <option :value="90">Últimos 3 Meses</option>
                </select>

                <div class="vr h-50 my-auto text-muted opacity-25"></div>

                <div class="form-check form-switch mb-0 d-flex align-items-center gap-2 ps-0">
                  <label class="form-check-label small text-muted fw-bold pt-1" style="font-size: 0.8rem; cursor: pointer; white-space: nowrap;" @click="compareToggle = !compareToggle">
                    Comparar
                  </label>
                  <input v-model="compareToggle" class="form-check-input m-0" type="checkbox" style="cursor: pointer; width: 32px; height: 18px;">
                </div>
              </div>

              <button class="btn btn-sm btn-light text-muted hover-primary" type="button" title="Descargar Gráfico" @click="downloadSalesChart">
                <i class="fas fa-download"></i>
              </button>
            </div>
          </div>
          <div class="card-body">
            <div style="height: 300px;">
              <canvas ref="salesCanvas" height="300"></canvas>
            </div>
          </div>
        </div>

        <div class="card-modern">
          <div class="card-header border-0 d-flex justify-content-between align-items-center">
            <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-chart-pie text-primary no-theme me-2"></i>Estado de Órdenes</h5>
          </div>
          <div class="card-body p-4">
            <div class="row align-items-center">
              <div class="col-12 col-md-5 mb-4 mb-md-0" style="min-height: 250px;">
                <div style="height: 250px;">
                  <canvas ref="ordersCanvas"></canvas>
                </div>
              </div>
              <div class="col-12 col-md-7">
                <div class="d-flex flex-wrap gap-2 justify-content-center justify-content-md-start">
                  <div v-for="it in ordersLegend" :key="it.label" class="d-flex align-items-center p-2 rounded border bg-light" style="min-width: 140px;">
                    <span class="rounded-circle me-2" :style="{ width: '12px', height: '12px', backgroundColor: it.color }"></span>
                    <div class="d-flex flex-column">
                      <span class="fw-bold text-dark small">{{ it.label }}</span>
                      <span class="text-muted small">{{ it.value }} órdenes</span>
                    </div>
                  </div>
                  <div v-if="ordersLegend.length === 0" class="text-muted small">Sin datos</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="card-modern mt-4 w-100 position-relative" style="overflow: hidden;">
          <div class="card-header border-0 pb-2 pt-3 shadow-none bg-transparent px-0 w-100">
            <ul class="nav nav-pills custom-pills flex-nowrap overflow-auto px-3 w-100" role="tablist" style="scrollbar-width: none;">
              <li class="nav-item text-nowrap" role="presentation">
                <button class="nav-link" :class="{ active: activeTab === 'recent' }" type="button" @click="activeTab = 'recent'">Recientes</button>
              </li>
              <li class="nav-item text-nowrap" role="presentation">
                <button class="nav-link" :class="{ active: activeTab === 'urgent' }" type="button" @click="activeTab = 'urgent'">
                  Atención Urgente
                  <span v-if="urgentCount > 0" class="badge bg-danger rounded-pill ms-1">{{ urgentCount }}</span>
                </button>
              </li>
              <li class="nav-item text-nowrap" role="presentation">
                <button class="nav-link" :class="{ active: activeTab === 'ready' }" type="button" @click="activeTab = 'ready'">
                  Listos para Entregar
                  <span v-if="readyCount > 0" class="badge bg-white text-primary rounded-pill ms-1 shadow-sm">{{ readyCount }}</span>
                </button>
              </li>
            </ul>
          </div>

          <div class="card-body p-3 p-md-4 bg-light rounded-bottom-4">
            <div v-if="activeTab === 'recent'" class="w-100 overflow-hidden">
              <div class="table-responsive bg-white rounded-4 shadow-sm border border-light w-100 d-none d-lg-block">
                <table class="table table-hover align-middle mb-0 text-nowrap">
                  <thead class="small text-uppercase bg-light">
                    <tr>
                      <th class="ps-4">ID</th>
                      <th>Cliente</th>
                      <th>Dispositivo</th>
                      <th>Estado</th>
                      <th class="text-end pe-4">Acción</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="o in (summary?.recentOrders ?? [])" :key="o.id">
                      <td class="ps-4 fw-bold">#{{ o.id }}</td>
                      <td>{{ o.clientName }}</td>
                      <td class="text-muted">
                        <div class="fw-bold text-dark">{{ `${o.deviceBrand} ${o.deviceModel}`.trim() }}</div>
                        <small v-if="o.accessories" class="text-primary"><i class="fas fa-plug fa-xs me-1"></i>{{ o.accessories }}</small>
                        <small v-else class="text-muted"><i class="fas fa-plug fa-xs me-1"></i>Sin accesorios</small>
                      </td>
                      <td><span class="badge bg-light text-dark border">{{ statusText(o.status) }}</span></td>
                      <td class="text-end pe-4">
                        <button class="btn btn-sm btn-light rounded-circle" type="button" @click="goOrder(o.id)"><i class="fas fa-eye"></i></button>
                      </td>
                    </tr>
                    <tr v-if="(summary?.recentOrders ?? []).length === 0">
                      <td colspan="5" class="text-center py-4 text-muted">Sin datos</td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div class="d-block d-lg-none">
                <div class="row g-3">
                  <div v-for="o in (summary?.recentOrders ?? [])" :key="o.id" class="col-12">
                    <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                      <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                          <span class="fw-bold fs-6">{{ o.orderNumber || `#${o.id}` }}</span>
                          <span class="badge bg-light text-dark border">{{ statusText(o.status) }}</span>
                        </div>
                        <div class="fw-bold text-dark mb-1">{{ o.clientName }}</div>
                        <div class="text-muted small mb-2">
                          <i class="fas fa-mobile-alt me-1"></i> {{ `${o.deviceBrand} ${o.deviceModel}`.trim() }}
                        </div>
                        <div v-if="o.accessories" class="small text-primary mb-3"><i class="fas fa-plug fa-xs me-1"></i>{{ o.accessories }}</div>
                        <div v-else class="small text-muted mb-3"><i class="fas fa-plug fa-xs me-1"></i>Sin accesorios</div>
                        <button class="btn btn-sm btn-outline-primary w-100 shadow-sm custom-btn-responsive rounded-pill" type="button" @click="goOrder(o.id)">Ver Detalles</button>
                      </div>
                    </div>
                  </div>
                  <div v-if="(summary?.recentOrders ?? []).length === 0" class="col-12">
                    <div class="text-center py-4 text-muted bg-white rounded-4 shadow-sm">Sin datos</div>
                  </div>
                </div>
              </div>
            </div>

            <div v-else-if="activeTab === 'urgent'" class="w-100 overflow-hidden">
              <div class="table-responsive bg-white rounded-4 shadow-sm border border-light w-100 d-none d-lg-block">
                <table class="table table-hover align-middle mb-0 text-nowrap">
                  <thead class="small text-uppercase bg-light">
                    <tr>
                      <th class="ps-4">ID</th>
                      <th>Cliente</th>
                      <th>Dispositivo</th>
                      <th>Estado</th>
                      <th>Tiempo</th>
                      <th class="text-end pe-4">Acción</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-if="(summary?.stagnantOrders ?? []).length === 0">
                      <td colspan="6" class="text-center py-4 text-muted">¡Todo al día! No hay órdenes estancadas.</td>
                    </tr>
                    <tr v-for="o in (summary?.stagnantOrders ?? [])" :key="o.id">
                      <td class="ps-4 fw-bold">{{ o.orderNumber || `#${o.id}` }}</td>
                      <td>{{ o.clientName }}</td>
                      <td class="text-dark">
                        <div class="fw-bold">{{ o.deviceModel }}</div>
                        <small v-if="o.accessories" class="text-primary"><i class="fas fa-plug fa-xs me-1"></i>{{ o.accessories }}</small>
                        <small v-else class="text-muted"><i class="fas fa-plug fa-xs me-1"></i>Sin accesorios</small>
                      </td>
                      <td><span class="badge bg-warning text-dark">{{ statusText(o.status) }}</span></td>
                      <td class="fw-bold">
                        <span v-if="['high', 'urgent'].includes(o.priority)" class="badge bg-danger"><i class="fas fa-fire me-1"></i>URGENTE</span>
                        <span v-else class="text-danger"><i class="far fa-clock me-1"></i> {{ o.daysOpen }} días</span>
                      </td>
                      <td class="text-end pe-4">
                        <button class="btn btn-sm btn-outline-danger rounded-pill" type="button" @click="goOrder(o.id)">Gestionar</button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div class="d-block d-lg-none">
                <div v-if="(summary?.stagnantOrders ?? []).length === 0" class="text-center py-4 text-muted bg-white rounded-4 shadow-sm">
                  ¡Todo al día! No hay órdenes estancadas.
                </div>
                <div v-else class="row g-3">
                  <div v-for="o in (summary?.stagnantOrders ?? [])" :key="o.id" class="col-12">
                    <div class="card border-0 shadow-sm rounded-4 h-100 bg-white">
                      <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                          <span class="fw-bold fs-6">{{ o.orderNumber || `#${o.id}` }}</span>
                          <span class="badge bg-warning text-dark">{{ statusText(o.status) }}</span>
                        </div>
                        <div class="fw-bold text-dark mb-1">{{ o.clientName }}</div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                          <div class="text-muted small">
                            <i class="fas fa-mobile-alt me-1"></i> {{ o.deviceModel }}
                          </div>
                          <div class="fw-bold">
                            <span v-if="['high', 'urgent'].includes(o.priority)" class="badge bg-danger"><i class="fas fa-fire me-1"></i>URGENTE</span>
                            <span v-else class="text-danger small"><i class="far fa-clock me-1"></i> {{ o.daysOpen }} días</span>
                          </div>
                        </div>
                        <button class="btn btn-sm btn-outline-danger w-100 shadow-sm rounded-pill mt-2" type="button" @click="goOrder(o.id)">Gestionar Orden</button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div v-else class="w-100 overflow-hidden">
              <div class="table-responsive bg-white rounded-4 shadow-sm border border-light w-100 d-none d-lg-block">
                <table class="table table-hover align-middle mb-0 text-nowrap">
                  <thead class="small text-uppercase bg-light">
                    <tr>
                      <th class="ps-4">Cliente / Teléfono</th>
                      <th>Dispositivo</th>
                      <th>Terminado</th>
                      <th class="text-end">Por Cobrar</th>
                      <th class="text-end pe-4">Acción</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-if="(summary?.readyOrders ?? []).length === 0">
                      <td colspan="5" class="text-center py-4 text-muted">No hay equipos pendientes de entrega.</td>
                    </tr>
                    <tr v-for="o in (summary?.readyOrders ?? [])" :key="o.id">
                      <td class="ps-4">
                        <div class="fw-bold">{{ o.clientName }}</div>
                        <small class="text-muted"><i class="fas fa-phone-alt me-1"></i>{{ o.phone }}</small>
                      </td>
                      <td class="text-dark">
                        <div class="fw-bold">{{ `${o.deviceBrand} ${o.deviceModel}`.trim() }}</div>
                        <small v-if="o.accessories" class="text-primary"><i class="fas fa-plug fa-xs me-1"></i>{{ o.accessories }}</small>
                        <small v-else class="text-muted"><i class="fas fa-plug fa-xs me-1"></i>Sin accesorios</small>
                      </td>
                      <td class="text-muted small">
                        <i class="far fa-check-circle text-success me-1"></i>
                        {{ o.completedAt ? new Date(o.completedAt).toLocaleString('es-CO', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '' }}
                      </td>
                      <td class="text-end fw-bold text-success">{{ fmtMoney(o.totalAmount) }}</td>
                      <td class="text-end pe-4">
                        <button class="btn btn-sm btn-success rounded-pill px-3 shadow-sm" type="button" @click="goOrder(o.id)">
                          <i class="fas fa-check me-1"></i> Entregar
                        </button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div class="d-block d-lg-none">
                <div v-if="(summary?.readyOrders ?? []).length === 0" class="text-center py-4 text-muted bg-white rounded-4 shadow-sm">
                  No hay equipos pendientes de entrega.
                </div>
                <div v-else class="row g-3">
                  <div v-for="o in (summary?.readyOrders ?? [])" :key="o.id" class="col-12">
                    <div class="card border-0 shadow-sm rounded-4 h-100 bg-white border-success" style="border-left: 4px solid #198754 !important;">
                      <div class="card-body p-3">
                        <div class="fw-bold text-dark mb-1">{{ o.clientName }}</div>
                        <div class="text-muted small mb-2"><i class="fas fa-phone-alt me-1"></i>{{ o.phone }}</div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                          <span class="text-dark small"><i class="fas fa-mobile-alt me-1"></i> {{ `${o.deviceBrand} ${o.deviceModel}`.trim() }}</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                          <span class="text-muted small"><i class="far fa-check-circle text-success me-1"></i>{{ o.completedAt ? new Date(o.completedAt).toLocaleString('es-CO', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '' }}</span>
                          <span class="fw-bold text-success">{{ fmtMoney(o.totalAmount) }}</span>
                        </div>
                        <button class="btn btn-sm btn-success w-100 shadow-sm rounded-pill" type="button" @click="goOrder(o.id)">
                          <i class="fas fa-check me-1"></i> Entregar
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-12 col-md-4 col-right" style="position: sticky; top: 110px; z-index: 2;">
        <div class="d-flex flex-column gap-4 h-100">
          <div class="flex-grow-1 card-modern overflow-hidden d-flex flex-column" style="min-height: 400px;">
            <div class="card-header border-0 d-flex justify-content-between align-items-center bg-warning bg-opacity-10">
              <div class="d-flex align-items-center gap-2">
                <h6 class="fw-bold mb-0 text-dark"><i class="fas fa-sticky-note text-warning me-2"></i>Notas Personales</h6>
                <button class="btn btn-sm btn-link text-dark p-0 opacity-50 hover-opacity-100" type="button" title="Insertar lista" style="transition: opacity 0.2s;" @click="insertBullet">
                  <i class="fas fa-list-ul"></i>
                </button>
              </div>
              <small class="text-muted" :title="notesErrorMessage" v-html="notesStatusHtml"></small>
            </div>
            <div class="card-body p-0 d-flex flex-column flex-grow-1">
              <div class="notepad-container p-3 flex-grow-1">
                <textarea
                  id="quickNotes"
                  v-model="notes"
                  class="form-control border-0 h-100 p-0 notepad-textarea"
                  placeholder="Escribe tus notas aquí..."
                  style="resize: none;"
                  @input="onNotesInput"
                ></textarea>
              </div>
            </div>
          </div>

          <div class="card-modern">
            <div class="calendar-widget">
              <div class="calendar-header">
                <button type="button" title="Mes Anterior" @click="changeMonth(-1)"><i class="fas fa-chevron-left"></i></button>
                <div class="calendar-title">{{ calTitle }}</div>
                <button type="button" title="Mes Siguiente" @click="changeMonth(1)"><i class="fas fa-chevron-right"></i></button>
              </div>
              <div class="calendar-days-header">
                <div>Do</div><div>Lu</div><div>Ma</div><div>Mi</div><div>Ju</div><div>Vi</div><div>Sa</div>
              </div>
              <div class="calendar-grid calendar-fade-in">
                <div
                  v-for="c in calendarCells"
                  :key="c.key"
                  :class="c.classes"
                  :title="c.title"
                  @click="c.clickable && selectDate(c.day)"
                >
                  {{ c.label }}
                </div>
              </div>
            </div>
          </div>

          <div class="card-modern h-100">
            <div class="card-header border-0">
              <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-exclamation-triangle text-danger me-2"></i>Stock Bajo</h5>
            </div>
            <div class="card-body p-0">
              <div v-if="(summary?.lowStockItems ?? []).length === 0" class="text-center py-5 text-muted">
                <i class="fas fa-check-circle fa-3x text-success mb-3 opacity-50"></i>
                <p>Inventario saludable</p>
              </div>
              <div v-else>
                <ul class="list-group list-group-flush">
                  <li v-for="p in (summary?.lowStockItems ?? [])" :key="p.name" class="list-group-item px-4 py-3 d-flex justify-content-between align-items-center border-light">
                    <div>
                      <h6 class="mb-0 fw-bold text-dark">{{ p.name }}</h6>
                      <small class="text-muted">Mínimo: {{ p.minStock }}</small>
                    </div>
                    <span class="badge bg-danger rounded-pill px-3 py-2">Quedan: {{ p.currentStock }}</span>
                  </li>
                </ul>
                <div class="p-3 text-center border-top">
                  <RouterLink to="/inventory/products" class="btn btn-sm btn-light text-primary no-theme fw-bold w-100">Ver reporte completo</RouterLink>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="fab-container">
      <div class="fab-menu" :class="{ active: fabOpen }" id="fabMenu">
        <RouterLink to="/orders/new" class="fab-item">
          <span>Nueva Orden</span>
          <div class="icon-box bg-primary text-white rounded-circle" style="width: 35px; height: 35px;"><i class="fas fa-plus"></i></div>
        </RouterLink>
        <RouterLink to="/sales/new" class="fab-item">
          <span>Crear Venta</span>
          <div class="icon-box bg-success text-white rounded-circle" style="width: 35px; height: 35px;"><i class="fas fa-file-invoice-dollar"></i></div>
        </RouterLink>
        <RouterLink to="/clients/new" class="fab-item">
          <span>Nuevo Cliente</span>
          <div class="icon-box bg-info text-white rounded-circle" style="width: 35px; height: 35px;"><i class="fas fa-user-plus"></i></div>
        </RouterLink>
      </div>
      <div class="fab-button" role="button" tabindex="0" @click="toggleFab">
        <i class="fas" :class="fabOpen ? 'fa-times' : 'fa-plus'"></i>
      </div>
    </div>
  </div>
</template>
