<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { apiDelete, apiGet, apiPatch } from '../../../api/http'
import ChangeStatusModal from '../components/ChangeStatusModal.vue'

type OrderListItem = {
  id: number
  orderNumber: string
  clientName: string
  clientPhone: string
  clientEmail: string
  deviceTypeName: string
  deviceBrand: string
  deviceModel: string
  serialNumber: string
  reportedIssue: string
  status: string
  approvalStatus: string
  priority: string
  createdAt: string
}

type PageDto = { items: OrderListItem[]; page: number; perPage: number; total: number }
type OrderStatus = { slug: string; name: string; emoji: string; color: string; sortOrder: number }
type OrdersChartDto = { labels: string[]; values: number[] }

const router = useRouter()
const search = ref('')
const status = ref('')
const statuses = ref<OrderStatus[]>([])
const ordersChart = ref<OrdersChartDto | null>(null)
const page = ref(1)
const perPage = ref(30)
const total = ref(0)
const items = ref<OrderListItem[]>([])
const message = ref('')

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))
const statusMap = computed(() => new Map(statuses.value.map((s) => [s.slug, s] as const)))

async function load() {
  message.value = ''
  const qs = new URLSearchParams({
    search: search.value,
    status: status.value,
    page: String(page.value),
    perPage: String(perPage.value),
  })
  const res = await apiGet<PageDto>(`/orders?${qs.toString()}`)
  if (!res.ok) {
    message.value = res.error.message
    items.value = []
    total.value = 0
    return
  }
  items.value = res.data.items
  total.value = res.data.total
}

async function loadStatuses() {
  const res = await apiGet<OrderStatus[]>('/orders/statuses')
  statuses.value = res.ok ? res.data : []
}

async function loadOrdersChart() {
  const res = await apiGet<OrdersChartDto>('/dashboard/orders-chart')
  ordersChart.value = res.ok ? res.data : null
}

function effectiveStatus(o: OrderListItem, map: Map<string, OrderStatus>): { text: string; color: string } {
  const ap = (o.approvalStatus ?? 'none').toLowerCase()
  if (ap === 'pending') return { text: '✍️ Esperando Aprobación', color: '#ffc107' }
  if (ap === 'approved') return { text: '✅ Aprobado', color: '#28a745' }
  if (ap === 'rejected') return { text: '❌ Rechazado', color: '#dc3545' }
  const s = map.get(o.status)
  if (!s) return { text: o.status, color: '#6c757d' }
  return { text: `${s.emoji ? s.emoji + ' ' : ''}${s.name}`, color: s.color || '#6c757d' }
}

const statusesSorted = computed(() => [...statuses.value].sort((a, b) => (a.sortOrder ?? 0) - (b.sortOrder ?? 0)))

const chartCounts = computed(() => {
  const labels = ordersChart.value?.labels ?? []
  const values = ordersChart.value?.values ?? []
  const m = new Map<string, number>()
  for (let i = 0; i < labels.length; i++) {
    m.set(String(labels[i] ?? ''), Number(values[i] ?? 0))
  }
  return m
})

const statsTotal = computed(() => {
  let sum = 0
  for (const v of chartCounts.value.values()) sum += Number.isFinite(v) ? v : 0
  return sum
})

const statsCards = computed(() => {
  const chosen = statusesSorted.value.slice(0, 3)
  return chosen.map((s) => ({
    slug: s.slug,
    name: s.name,
    emoji: s.emoji,
    color: s.color,
    count: chartCounts.value.get(s.slug) ?? 0,
  }))
})

function fmtDate(iso: string): string {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  return d.toLocaleDateString('es-CO', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

function fmtTime(iso: string): string {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  return d.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' })
}

function deviceExtra(o: OrderListItem): string {
  const type = (o.deviceTypeName ?? '').trim()
  const model = (o.deviceModel ?? '').trim()
  const parts = [type, model].filter(Boolean)
  return parts.length ? parts.join(' - ') : 'Sin información extra'
}

function applyFilters() {
  page.value = 1
  void load()
}

function clearFilters() {
  search.value = ''
  status.value = ''
  page.value = 1
  void load()
}

function go(p: number) {
  page.value = Math.min(Math.max(1, p), totalPages.value)
  void load()
}

function open(id: number) {
  void router.push(`/orders/${id}`)
}

function edit(id: number) {
  void router.push(`/orders/${id}/edit`)
}

function reports(id: number) {
  void router.push(`/orders/${id}`)
}

const showStatusModal = ref(false)
const statusModalOrderId = ref<number | null>(null)
const statusModalCurrent = ref('')

function openStatusModal(o: OrderListItem) {
  statusModalOrderId.value = o.id
  statusModalCurrent.value = o.status
  showStatusModal.value = true
}

function closeStatusModal() {
  showStatusModal.value = false
}

async function submitStatus(next: string) {
  const orderId = statusModalOrderId.value
  if (!orderId) return
  const res = await apiPatch<{ done: true }, { status: string }>(`/orders/${orderId}/status`, { status: next })
  message.value = res.ok ? '' : res.error.message
  showStatusModal.value = false
  await load()
  await loadOrdersChart()
}

async function removeOrder(o: OrderListItem) {
  const ok = window.confirm(`¿Eliminar la orden #${o.id}?`)
  if (!ok) return
  const res = await apiDelete<{ done: true }>(`/orders/${o.id}`)
  if (!res.ok) {
    message.value = res.error.message
    return
  }
  message.value = ''
  await load()
  await loadOrdersChart()
}

function digitsOnly(v: string): string {
  return String(v ?? '').replace(/\D/g, '')
}

function openWhatsApp(o: OrderListItem) {
  const phoneDigits = digitsOnly(o.clientPhone)
  if (!phoneDigits) {
    message.value = 'El cliente no tiene teléfono.'
    return
  }
  const waNumber = phoneDigits.length === 10 ? `57${phoneDigits}` : phoneDigits
  const st = effectiveStatus(o, statusMap.value).text
  const text = [
    `🧾 Orden #${o.id}`,
    `👤 Cliente: ${o.clientName || ''}`.trim(),
    `📱 Equipo: ${`${o.deviceBrand ?? ''} ${o.deviceModel ?? ''}`.trim()}`.trim(),
    `⚠️ Problema: ${(o.reportedIssue ?? '').trim() || '-'}`,
    `🏷️ Estado: ${st}`,
  ]
    .filter(Boolean)
    .join('\n')
  const url = `https://wa.me/${encodeURIComponent(waNumber)}?text=${encodeURIComponent(text)}`
  window.open(url, '_blank', 'noopener,noreferrer')
}

function showProblemDetails(o: OrderListItem) {
  const txt = (o.reportedIssue ?? '').trim()
  if (!txt) return
  window.alert(txt)
}

const pageNumbers = computed(() => {
  const start = Math.max(1, page.value - 2)
  const end = Math.min(totalPages.value, page.value + 2)
  const out: number[] = []
  for (let i = start; i <= end; i++) out.push(i)
  return out
})

onMounted(async () => {
  await loadStatuses()
  await loadOrdersChart()
  await load()
})
</script>

<template>
  <div class="d-flex flex-column">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
      <div>
        <h2 class="fw-bold text-dark mb-1">
          <i class="fas fa-clipboard-list me-2 text-primary no-theme"></i>Gestión de Órdenes
        </h2>
        <p class="text-muted mb-0">Administra todas las órdenes de servicio técnico</p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-primary rounded-pill px-4 shadow-sm" type="button" @click="router.push('/orders/new')">
          <i class="fas fa-plus me-2"></i>Nueva Orden
        </button>
      </div>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-3">
      {{ message }}
    </div>

    <div class="row mb-4 g-3">
      <div class="col-md-3">
        <div class="card card-modern h-100">
          <div class="card-body d-flex align-items-center">
            <div class="rounded-circle bg-primary bg-opacity-10 no-theme p-3 me-3">
              <i class="fas fa-clipboard-list fa-2x text-primary no-theme"></i>
            </div>
            <div>
              <h5 class="fw-bold mb-0">{{ statsTotal }}</h5>
              <small class="text-muted">Total Órdenes</small>
              <span class="ms-1 align-middle" title="Total calculado desde el conteo por estado.">
                <i class="fas fa-info-circle text-muted"></i>
              </span>
            </div>
          </div>
        </div>
      </div>

      <div v-for="c in statsCards" :key="c.slug" class="col-md-3">
        <div class="card card-modern h-100">
          <div class="card-body d-flex align-items-center">
            <div
              class="rounded-circle d-flex align-items-center justify-content-center me-3"
              :style="{ backgroundColor: (c.color || '#6c757d') + '20', width: '56px', height: '56px' }"
            >
              <span :style="{ color: c.color || '#6c757d', fontSize: '1.8rem', lineHeight: '1' }">{{ c.emoji }}</span>
            </div>
            <div>
              <h5 class="fw-bold mb-0" :style="{ color: c.color || '#6c757d' }">{{ c.count }}</h5>
              <small class="text-muted">{{ c.name }}</small>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card card-modern mb-4">
      <div class="card-body py-3">
        <form class="row g-3 align-items-center" @submit.prevent="applyFilters">
          <div class="col-md-5">
            <div class="input-group">
              <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3">
                <i class="fas fa-search"></i>
              </span>
              <input
                v-model="search"
                type="text"
                class="form-control bg-light border-start-0 rounded-end-pill px-3"
                placeholder="Buscar..."
              />
            </div>
          </div>
          <div class="col-md-3">
            <select v-model="status" class="form-select bg-light border-0 text-muted rounded-pill">
              <option value="">Todos los estados</option>
              <option v-for="s in statusesSorted" :key="s.slug" :value="s.slug">
                {{ (s.emoji ? s.emoji + ' ' : '') + s.name }}
              </option>
            </select>
          </div>
          <div class="col-md-4">
            <div class="d-flex gap-2 justify-content-end">
              <button class="btn btn-primary rounded-pill px-3" type="submit">
                <i class="fas fa-filter me-1"></i>Filtrar
              </button>
              <button
                v-if="search.trim() || status.trim()"
                class="btn btn-outline-secondary rounded-pill px-3"
                type="button"
                @click="clearFilters"
              >
                <i class="fas fa-times me-1"></i>Limpiar
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <div class="card card-modern overflow-hidden">
      <div class="card-body p-0">
        <div v-if="items.length === 0" class="text-center py-5">
          <div class="rounded-circle bg-light p-4 d-inline-block mb-3">
            <i class="fas fa-inbox fa-3x text-muted"></i>
          </div>
          <h5 class="text-muted mb-2">No se encontraron órdenes</h5>
          <p class="text-muted mb-3">No hay órdenes que coincidan con los criterios de búsqueda.</p>
          <button class="btn btn-dark rounded-pill px-4" type="button" @click="router.push('/orders/new')">Crear Primera Orden</button>
        </div>

        <div v-else>
          <div class="table-responsive d-none d-lg-block">
            <table class="table table-hover align-middle mb-0">
              <thead class="bg-light text-muted">
                <tr>
                  <th class="ps-4">ID</th>
                  <th>Cliente</th>
                  <th>Dispositivo</th>
                  <th>Problema</th>
                  <th>Estado</th>
                  <th>Fecha</th>
                  <th class="text-end pe-4">Acciones</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="o in items" :key="o.id">
                  <td class="ps-4">
                    <span class="fw-bold text-dark">#{{ o.id }}</span>
                  </td>
                  <td>
                    <div class="fw-bold text-dark">{{ o.clientName }}</div>
                    <small v-if="o.clientPhone" class="text-muted">
                      <i class="fas fa-phone me-1"></i>{{ o.clientPhone }}
                    </small>
                  </td>
                  <td>
                    <div class="fw-bold text-dark">{{ o.deviceBrand || 'Marca no especificada' }}</div>
                    <small class="text-muted">{{ deviceExtra(o) }}</small>
                  </td>
                  <td>
                    <div class="d-flex align-items-center">
                      <span
                        class="text-truncate d-inline-block text-muted"
                        style="max-width: 180px"
                        :title="o.reportedIssue || ''"
                      >
                        {{ o.reportedIssue || 'Sin descripción' }}
                      </span>
                      <button
                        v-if="(o.reportedIssue || '').length > 50"
                        class="btn btn-sm btn-link text-info p-0 ms-2"
                        type="button"
                        title="Ver descripción completa"
                        @click="showProblemDetails(o)"
                      >
                        <i class="fas fa-eye"></i>
                      </button>
                    </div>
                  </td>
                  <td>
                    <button
                      class="btn btn-link p-0 align-middle text-decoration-none"
                      type="button"
                      title="Cambiar estado"
                      @click="openStatusModal(o)"
                    >
                      <span class="badge" :style="{ backgroundColor: effectiveStatus(o, statusMap).color, color: 'white' }">
                        {{ effectiveStatus(o, statusMap).text }}
                      </span>
                    </button>
                  </td>
                  <td>
                    <div class="text-dark">{{ fmtDate(o.createdAt) }}</div>
                    <small class="text-muted">{{ fmtTime(o.createdAt) }}</small>
                  </td>
                  <td class="text-end pe-4">
                    <div class="d-flex justify-content-end gap-2">
                      <button class="btn btn-sm btn-light text-primary no-theme shadow-sm" type="button" title="Ver detalles" @click="open(o.id)">
                        <i class="fas fa-eye"></i>
                      </button>
                      <button class="btn btn-sm btn-light text-secondary shadow-sm" type="button" title="Editar" @click="edit(o.id)">
                        <i class="fas fa-edit"></i>
                      </button>
                      <button class="btn btn-sm btn-light text-primary no-theme shadow-sm" type="button" title="Informes Técnicos" @click="reports(o.id)">
                        <i class="fas fa-clipboard"></i>
                      </button>
                      <button class="btn btn-sm btn-light text-dark shadow-sm" type="button" title="Imprimir" disabled>
                        <i class="fas fa-print"></i>
                      </button>
                      <button class="btn btn-sm btn-light text-dark shadow-sm" type="button" title="Etiqueta" disabled>
                        <i class="fas fa-tag"></i>
                      </button>
                      <button class="btn btn-sm btn-light text-danger shadow-sm" type="button" title="PDF" disabled>
                        <i class="fas fa-file-pdf"></i>
                      </button>
                      <button class="btn btn-sm btn-light text-success shadow-sm" type="button" title="Enviar por WhatsApp" @click="openWhatsApp(o)">
                        <i class="fab fa-whatsapp"></i>
                      </button>
                      <button class="btn btn-sm btn-light text-danger shadow-sm" type="button" title="Eliminar" @click="removeOrder(o)">
                        <i class="fas fa-trash"></i>
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="d-block d-lg-none p-3 bg-light">
            <div class="row g-3">
              <div v-for="o in items" :key="o.id" class="col-12">
                <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden">
                  <div class="card-header bg-white border-bottom-0 pt-3 pb-2 d-flex justify-content-between align-items-center">
                    <span class="fs-5 fw-bold text-dark">#{{ o.id }}</span>
                    <div>
                      <button
                        class="btn btn-link p-0 align-middle text-decoration-none"
                        type="button"
                        title="Cambiar estado"
                        @click="openStatusModal(o)"
                      >
                        <span class="badge" :style="{ backgroundColor: effectiveStatus(o, statusMap).color, color: 'white' }">
                          {{ effectiveStatus(o, statusMap).text }}
                        </span>
                      </button>
                    </div>
                  </div>
                  <div class="card-body py-2">
                    <div class="mb-3">
                      <h6 class="fw-bold mb-1 text-dark">
                        <i class="fas fa-user text-primary no-theme me-2"></i>{{ o.clientName }}
                      </h6>
                      <a
                        v-if="o.clientPhone"
                        :href="`tel:${o.clientPhone}`"
                        class="text-muted text-decoration-none ms-4 d-inline-block small"
                      >
                        <i class="fas fa-phone me-1"></i>{{ o.clientPhone }}
                      </a>
                    </div>
                    <div class="mb-3">
                      <h6 class="fw-bold mb-1 text-dark">
                        <i class="fas fa-mobile-alt text-primary no-theme me-2"></i>{{ o.deviceBrand || 'Marca no especificada' }}
                      </h6>
                      <span class="text-muted ms-4 d-inline-block small">{{ deviceExtra(o) }}</span>
                    </div>
                    <div class="bg-light p-3 rounded-3 mb-2 small text-muted border border-light">
                      <div class="fw-bold text-dark mb-1">Problema reportado:</div>
                      {{ o.reportedIssue || 'Sin descripción' }}
                    </div>
                  </div>
                  <div class="card-footer bg-white border-top-0 pb-3 pt-0">
                    <div class="d-flex justify-content-between align-items-center text-muted small mb-3">
                      <span><i class="fas fa-calendar-alt me-1"></i>{{ fmtDate(o.createdAt) }}</span>
                      <span><i class="fas fa-clock me-1"></i>{{ fmtTime(o.createdAt) }}</span>
                    </div>
                    <div class="d-flex flex-wrap gap-2 pb-1 justify-content-center justify-content-sm-start">
                      <button
                        class="btn btn-sm btn-light text-primary no-theme shadow-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                        style="width: 36px; height: 36px"
                        type="button"
                        title="Ver"
                        @click="open(o.id)"
                      >
                        <i class="fas fa-eye"></i>
                      </button>
                      <button
                        class="btn btn-sm btn-light text-warning shadow-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                        style="width: 36px; height: 36px"
                        type="button"
                        title="Editar"
                        @click="edit(o.id)"
                      >
                        <i class="fas fa-edit"></i>
                      </button>
                      <button
                        class="btn btn-sm btn-light text-primary no-theme shadow-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                        style="width: 36px; height: 36px"
                        type="button"
                        title="Informes"
                        @click="reports(o.id)"
                      >
                        <i class="fas fa-clipboard"></i>
                      </button>
                      <button
                        class="btn btn-sm btn-light text-dark shadow-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                        style="width: 36px; height: 36px"
                        type="button"
                        title="Imprimir"
                        disabled
                      >
                        <i class="fas fa-print"></i>
                      </button>
                      <button
                        class="btn btn-sm btn-light text-dark shadow-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                        style="width: 36px; height: 36px"
                        type="button"
                        title="Etiqueta"
                        disabled
                      >
                        <i class="fas fa-tag"></i>
                      </button>
                      <button
                        class="btn btn-sm btn-light text-danger shadow-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                        style="width: 36px; height: 36px"
                        type="button"
                        title="PDF"
                        disabled
                      >
                        <i class="fas fa-file-pdf"></i>
                      </button>
                      <button
                        class="btn btn-sm btn-light text-success shadow-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                        style="width: 36px; height: 36px"
                        type="button"
                        title="WhatsApp"
                        @click="openWhatsApp(o)"
                      >
                        <i class="fab fa-whatsapp"></i>
                      </button>
                      <button
                        class="btn btn-sm btn-light text-danger shadow-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                        style="width: 36px; height: 36px"
                        type="button"
                        title="Eliminar"
                        @click="removeOrder(o)"
                      >
                        <i class="fas fa-trash"></i>
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div v-if="totalPages > 1" class="card-footer bg-white border-top border-light py-3">
            <nav aria-label="Paginación de órdenes">
              <ul class="pagination justify-content-center mb-0">
                <li v-if="page > 1" class="page-item">
                  <button class="page-link border-0 text-muted" type="button" @click="go(page - 1)">
                    <i class="fas fa-chevron-left me-1"></i> Anterior
                  </button>
                </li>

                <li v-for="n in pageNumbers" :key="n" class="page-item">
                  <button
                    class="page-link border-0 rounded-circle mx-1 d-flex align-items-center justify-content-center"
                    :class="n === page ? 'bg-primary text-white shadow-sm' : 'text-muted'"
                    style="width: 35px; height: 35px"
                    type="button"
                    @click="go(n)"
                  >
                    {{ n }}
                  </button>
                </li>

                <li v-if="page < totalPages" class="page-item">
                  <button class="page-link border-0 text-muted" type="button" @click="go(page + 1)">
                    Siguiente <i class="fas fa-chevron-right ms-1"></i>
                  </button>
                </li>
              </ul>
            </nav>
          </div>
        </div>
      </div>
    </div>

    <ChangeStatusModal
      :open="showStatusModal"
      :statuses="statusesSorted"
      :current="statusModalCurrent"
      @close="closeStatusModal"
      @submit="submitStatus"
    />
  </div>
</template>
