<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { apiGet } from '../../../api/http'

type BillingRow = {
  id: number
  invoiceNumber: string
  clientName: string
  invoiceDate: string
  totalAmount: number
  paidAmount: number
  pendingAmount: number
  paymentStatus: string
  status: string
}

type BillingTotals = {
  totalAmount: number
  paidAmount: number
  pendingAmount: number
  cancelledAmount: number
}

type BillingPage = {
  items: BillingRow[]
  page: number
  perPage: number
  total: number
  totals: BillingTotals
}

type SupplierRow = {
  supplierId: number
  supplierName: string
  ordersCount: number
  totalAmount: number
  paidAmount: number
  pendingAmount: number
}

type SuppliersReport = { items: SupplierRow[] }

type DeviceCategory = { id: number; name: string }
type DeviceCategoriesList = { items: DeviceCategory[] }

type ServicesReport = {
  stats: {
    totalServices: number
    totalRevenue: number
    averagePrice: number
    mostPopularService: { name: string; usageCount: number } | null
  }
  items: {
    serviceId: number
    name: string
    categoryName: string
    basePrice: number
    usageCount: number
    totalRevenue: number
    averagePrice: number
  }[]
}

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

function monthStartIso() {
  const d = new Date()
  const first = new Date(d.getFullYear(), d.getMonth(), 1)
  return first.toISOString().slice(0, 10)
}

const active = ref<'billing' | 'suppliers' | 'services'>('billing')
const from = ref(monthStartIso())
const to = ref(todayIso())
const status = ref('')
const paymentStatus = ref('')
const serviceId = ref('')
const categoryId = ref('')

const billing = ref<BillingPage | null>(null)
const suppliers = ref<SupplierRow[]>([])
const servicesReport = ref<ServicesReport | null>(null)
const servicesCatalog = ref<{ id: number; name: string }[]>([])
const categories = ref<DeviceCategory[]>([])
const message = ref('')
const loading = ref(false)

const billingTotalPages = computed(() => {
  if (!billing.value) return 1
  return Math.max(1, Math.ceil(billing.value.total / billing.value.perPage))
})

async function loadBilling(page = 1) {
  message.value = ''
  loading.value = true
  const qs = new URLSearchParams({
    from: from.value,
    to: to.value,
    status: status.value,
    paymentStatus: paymentStatus.value,
    page: String(page),
    perPage: '20',
  })
  const res = await apiGet<BillingPage>(`/reports/billing?${qs.toString()}`)
  loading.value = false
  if (!res.ok) {
    message.value = res.error.message
    billing.value = null
    return
  }
  billing.value = res.data
}

async function loadSuppliers() {
  message.value = ''
  loading.value = true
  const qs = new URLSearchParams({ from: from.value, to: to.value })
  const res = await apiGet<SuppliersReport>(`/reports/suppliers?${qs.toString()}`)
  loading.value = false
  if (!res.ok) {
    message.value = res.error.message
    suppliers.value = []
    return
  }
  suppliers.value = res.data.items
}

async function loadServicesReport() {
  message.value = ''
  loading.value = true
  const qs = new URLSearchParams({
    from: from.value,
    to: to.value,
    serviceId: serviceId.value,
    categoryId: categoryId.value,
  })
  const res = await apiGet<ServicesReport>(`/reports/services?${qs.toString()}`)
  loading.value = false
  if (!res.ok) {
    message.value = res.error.message
    servicesReport.value = null
    return
  }
  servicesReport.value = res.data
}

async function reload() {
  if (active.value === 'billing') return await loadBilling(1)
  if (active.value === 'suppliers') return await loadSuppliers()
  return await loadServicesReport()
}

function goBilling(p: number) {
  if (!billing.value) return
  const safe = Math.min(Math.max(1, p), billingTotalPages.value)
  void loadBilling(safe)
}

onMounted(() => {
  void loadBilling(1)
  void (async () => {
    const servicesQs = new URLSearchParams({ onlyActive: '1', page: '1', perPage: '200' })
    const sres = await apiGet<{ items: { id: number; name: string }[] }>(`/services?${servicesQs.toString()}`)
    servicesCatalog.value = sres.ok ? sres.data.items : []
    const cres = await apiGet<DeviceCategoriesList>(`/services/categories?onlyActive=1`)
    categories.value = cres.ok ? cres.data.items : []
  })()
})
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
      <div>
        <h5 class="fw-semibold mb-1">Reportes</h5>
        <div class="text-secondary small">Facturación y compras</div>
      </div>
      <div class="d-flex gap-2 align-items-center flex-wrap justify-content-end">
        <input v-model="from" type="date" class="form-control" />
        <input v-model="to" type="date" class="form-control" />
        <button class="btn btn-dark rounded-pill" type="button" :disabled="loading" @click="reload">Aplicar</button>
      </div>
    </div>

    <ul class="nav nav-pills">
      <li class="nav-item">
        <button class="nav-link" type="button" :class="{ active: active === 'billing' }" @click="active = 'billing'; reload()">
          Facturación
        </button>
      </li>
      <li class="nav-item">
        <button
          class="nav-link"
          type="button"
          :class="{ active: active === 'suppliers' }"
          @click="active = 'suppliers'; reload()"
        >
          Proveedores
        </button>
      </li>
      <li class="nav-item">
        <button
          class="nav-link"
          type="button"
          :class="{ active: active === 'services' }"
          @click="active = 'services'; reload()"
        >
          Servicios
        </button>
      </li>
    </ul>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-0">
      {{ message }}
    </div>

    <div v-if="active === 'billing'" class="d-flex flex-column gap-3">
      <div class="card shadow-soft border-0 rounded-custom">
        <div class="card-body">
          <div class="d-flex flex-column flex-md-row gap-2 align-items-center justify-content-between">
            <div class="d-flex gap-2 flex-wrap align-items-center">
              <select v-model="status" class="form-select" style="max-width: 220px">
                <option value="">Estado (todos)</option>
                <option value="draft">draft</option>
                <option value="sent">sent</option>
                <option value="paid">paid</option>
                <option value="cancelled">cancelled</option>
              </select>
              <select v-model="paymentStatus" class="form-select" style="max-width: 220px">
                <option value="">Pago (todos)</option>
                <option value="pending">pending</option>
                <option value="partial">partial</option>
                <option value="paid">paid</option>
              </select>
              <button class="btn btn-outline-secondary rounded-pill" type="button" :disabled="loading" @click="loadBilling(1)">
                Filtrar
              </button>
            </div>
            <div v-if="billing" class="text-secondary small">Total: {{ billing.total }}</div>
          </div>
        </div>
      </div>

      <div v-if="billing" class="row g-3">
        <div class="col-12 col-md-3">
          <div class="card shadow-soft border-0 rounded-custom">
            <div class="card-body">
              <div class="text-secondary small">Total facturado</div>
              <div class="fw-semibold">{{ billing.totals.totalAmount }}</div>
            </div>
          </div>
        </div>
        <div class="col-12 col-md-3">
          <div class="card shadow-soft border-0 rounded-custom">
            <div class="card-body">
              <div class="text-secondary small">Pagado</div>
              <div class="fw-semibold">{{ billing.totals.paidAmount }}</div>
            </div>
          </div>
        </div>
        <div class="col-12 col-md-3">
          <div class="card shadow-soft border-0 rounded-custom">
            <div class="card-body">
              <div class="text-secondary small">Pendiente</div>
              <div class="fw-semibold">{{ billing.totals.pendingAmount }}</div>
            </div>
          </div>
        </div>
        <div class="col-12 col-md-3">
          <div class="card shadow-soft border-0 rounded-custom">
            <div class="card-body">
              <div class="text-secondary small">Anulado</div>
              <div class="fw-semibold">{{ billing.totals.cancelledAmount }}</div>
            </div>
          </div>
        </div>
      </div>

      <div class="card shadow-soft border-0 rounded-custom">
        <div class="card-body">
          <div v-if="loading" class="text-secondary small">Cargando...</div>
          <div v-else class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Factura</th>
                  <th>Cliente</th>
                  <th>Fecha</th>
                  <th class="text-end">Total</th>
                  <th class="text-end">Pagado</th>
                  <th class="text-end">Pendiente</th>
                  <th>Pago</th>
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="i in billing?.items ?? []" :key="i.id">
                  <td>{{ i.id }}</td>
                  <td class="fw-semibold">{{ i.invoiceNumber }}</td>
                  <td>{{ i.clientName }}</td>
                  <td>{{ i.invoiceDate }}</td>
                  <td class="text-end">{{ i.totalAmount }}</td>
                  <td class="text-end">{{ i.paidAmount }}</td>
                  <td class="text-end">{{ i.pendingAmount }}</td>
                  <td>{{ i.paymentStatus }}</td>
                  <td>{{ i.status }}</td>
                </tr>
                <tr v-if="(billing?.items?.length ?? 0) === 0">
                  <td colspan="9" class="text-secondary">Sin datos</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div v-if="billing" class="d-flex justify-content-between align-items-center mt-3">
            <div class="text-secondary small">Página {{ billing.page }} / {{ billingTotalPages }}</div>
            <div class="d-flex gap-2 align-items-center">
              <button
                class="btn btn-sm btn-outline-secondary rounded-pill"
                type="button"
                :disabled="billing.page <= 1 || loading"
                @click="goBilling(billing.page - 1)"
              >
                Anterior
              </button>
              <button
                class="btn btn-sm btn-outline-secondary rounded-pill"
                type="button"
                :disabled="billing.page >= billingTotalPages || loading"
                @click="goBilling(billing.page + 1)"
              >
                Siguiente
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div v-else-if="active === 'suppliers'" class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div v-if="loading" class="text-secondary small">Cargando...</div>
        <div v-else class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>Proveedor</th>
                <th class="text-end">Órdenes</th>
                <th class="text-end">Total</th>
                <th class="text-end">Pagado</th>
                <th class="text-end">Pendiente</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in suppliers" :key="s.supplierId">
                <td class="fw-semibold">{{ s.supplierName || `Proveedor #${s.supplierId}` }}</td>
                <td class="text-end">{{ s.ordersCount }}</td>
                <td class="text-end">{{ s.totalAmount }}</td>
                <td class="text-end">{{ s.paidAmount }}</td>
                <td class="text-end">{{ s.pendingAmount }}</td>
              </tr>
              <tr v-if="suppliers.length === 0">
                <td colspan="5" class="text-secondary">Sin datos</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div v-else class="d-flex flex-column gap-3">
      <div class="card shadow-soft border-0 rounded-custom">
        <div class="card-body">
          <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
              <label class="form-label">Servicio</label>
              <select v-model="serviceId" class="form-select">
                <option value="">Todos</option>
                <option v-for="s in servicesCatalog" :key="s.id" :value="String(s.id)">{{ s.name }}</option>
              </select>
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Categoría</label>
              <select v-model="categoryId" class="form-select">
                <option value="">Todas</option>
                <option v-for="c in categories" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
              </select>
            </div>
            <div class="col-12 col-md-4 d-grid">
              <button class="btn btn-dark rounded-pill" type="button" :disabled="loading" @click="loadServicesReport">
                Generar
              </button>
            </div>
          </div>
        </div>
      </div>

      <div v-if="servicesReport" class="row g-3">
        <div class="col-12 col-md-3">
          <div class="card shadow-soft border-0 rounded-custom">
            <div class="card-body">
              <div class="text-secondary small">Servicios usados</div>
              <div class="fw-semibold">{{ servicesReport.stats.totalServices }}</div>
            </div>
          </div>
        </div>
        <div class="col-12 col-md-3">
          <div class="card shadow-soft border-0 rounded-custom">
            <div class="card-body">
              <div class="text-secondary small">Ingresos</div>
              <div class="fw-semibold">{{ servicesReport.stats.totalRevenue }}</div>
            </div>
          </div>
        </div>
        <div class="col-12 col-md-3">
          <div class="card shadow-soft border-0 rounded-custom">
            <div class="card-body">
              <div class="text-secondary small">Precio promedio</div>
              <div class="fw-semibold">{{ servicesReport.stats.averagePrice }}</div>
            </div>
          </div>
        </div>
        <div class="col-12 col-md-3">
          <div class="card shadow-soft border-0 rounded-custom">
            <div class="card-body">
              <div class="text-secondary small">Más popular</div>
              <div class="fw-semibold">
                {{ servicesReport.stats.mostPopularService?.name || '-' }}
              </div>
              <div class="text-secondary small">
                {{ servicesReport.stats.mostPopularService ? `Usos: ${servicesReport.stats.mostPopularService.usageCount}` : '' }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="card shadow-soft border-0 rounded-custom">
        <div class="card-body">
          <div v-if="loading" class="text-secondary small">Cargando...</div>
          <div v-else class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>Servicio</th>
                  <th>Categoría</th>
                  <th class="text-end">Base</th>
                  <th class="text-end">Usos</th>
                  <th class="text-end">Ingreso</th>
                  <th class="text-end">Promedio</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in servicesReport?.items ?? []" :key="r.serviceId">
                  <td class="fw-semibold">{{ r.name || `Servicio #${r.serviceId}` }}</td>
                  <td>{{ r.categoryName }}</td>
                  <td class="text-end">{{ r.basePrice }}</td>
                  <td class="text-end">{{ r.usageCount }}</td>
                  <td class="text-end">{{ r.totalRevenue }}</td>
                  <td class="text-end">{{ r.averagePrice }}</td>
                </tr>
                <tr v-if="(servicesReport?.items?.length ?? 0) === 0">
                  <td colspan="6" class="text-secondary">Sin datos</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
