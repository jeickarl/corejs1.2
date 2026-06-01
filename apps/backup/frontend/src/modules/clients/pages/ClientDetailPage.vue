<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiDelete, apiGet } from '../../../api/http'

type Client = {
  id: number
  clientType: string
  firstName: string
  companyName: string
  taxId: string
  legalRepresentative: string
  phone: string
  email: string
  idNumber: string
  address: string
  notes: string
  clientNumber: number | null
  createdAt: string
}

type ClientOrdersStats = { total: number; pending: number; inProcess: number; completed: number }
type OrderListItem = {
  id: number
  orderNumber: string
  deviceTypeName: string
  deviceBrand: string
  deviceModel: string
  serialNumber: string
  status: string
  approvalStatus: string
  priority: string
  createdAt: string
}
type OrdersPage = { items: OrderListItem[]; page: number; perPage: number; total: number }

const route = useRoute()
const router = useRouter()
const id = computed(() => Number(route.params.id))

const loading = ref(false)
const deleting = ref(false)
const message = ref('')
const client = ref<Client | null>(null)
const stats = ref<ClientOrdersStats | null>(null)
const orders = ref<OrderListItem[]>([])
const ordersPage = ref(1)
const ordersPerPage = ref(10)
const ordersTotal = ref(0)

const ordersTotalPages = computed(() => Math.max(1, Math.ceil(ordersTotal.value / ordersPerPage.value)))

function fmtDate(iso: string): string {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  return d.toLocaleDateString('es-CO', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

function displayName(c: Client): string {
  return (c.companyName ?? '').trim() || (c.firstName ?? '').trim() || `Cliente #${c.id}`
}

function typeLabel(c: Client): string {
  return c.clientType === 'company' ? 'Empresa' : 'Persona Natural'
}

function statusBadgeClass(st: string): string {
  const s = (st ?? '').toLowerCase()
  if (s === 'completed' || s === 'delivered') return 'bg-success bg-opacity-10 text-success'
  if (s === 'pending' || s === 'received' || s === 'esperando_aprobacion') return 'bg-warning bg-opacity-10 text-warning'
  if (s === 'cancelled') return 'bg-danger bg-opacity-10 text-danger'
  return 'bg-info bg-opacity-10 text-info'
}

async function load() {
  loading.value = true
  message.value = ''
  const res = await apiGet<Client>(`/clients/${id.value}`)
  if (!res.ok) {
    message.value = res.error.message
    client.value = null
    loading.value = false
    return
  }
  client.value = res.data
  await loadStats()
  await loadOrders()
  loading.value = false
}

async function loadStats() {
  if (!client.value) return
  const res = await apiGet<ClientOrdersStats>(`/orders/client-stats?clientId=${encodeURIComponent(String(client.value.id))}`)
  stats.value = res.ok ? res.data : null
}

async function loadOrders() {
  if (!client.value) return
  const qs = new URLSearchParams({
    clientId: String(client.value.id),
    page: String(ordersPage.value),
    perPage: String(ordersPerPage.value),
  })
  const res = await apiGet<OrdersPage>(`/orders?${qs.toString()}`)
  if (!res.ok) {
    orders.value = []
    ordersTotal.value = 0
    return
  }
  orders.value = res.data.items
  ordersTotal.value = res.data.total
}

function goOrders(p: number) {
  ordersPage.value = Math.min(Math.max(1, p), ordersTotalPages.value)
  void loadOrders()
}

const ordersPageNumbers = computed(() => {
  const start = Math.max(1, ordersPage.value - 2)
  const end = Math.min(ordersTotalPages.value, ordersPage.value + 2)
  const out: number[] = []
  for (let i = start; i <= end; i++) out.push(i)
  return out
})

async function remove() {
  if (deleting.value) return
  if (!client.value) return
  const ok = window.confirm('¿Eliminar este cliente?')
  if (!ok) return
  deleting.value = true
  message.value = ''
  const res = await apiDelete<{ deleted: true }>(`/clients/${client.value.id}`)
  if (!res.ok) {
    message.value = res.error.message
    deleting.value = false
    return
  }
  await router.push('/clients')
}

onMounted(load)
</script>

<template>
  <div class="d-flex flex-column">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
      <div>
        <nav aria-label="breadcrumb">
          <ol class="breadcrumb mb-2">
            <li class="breadcrumb-item">
              <a class="text-decoration-none" href="#" @click.prevent="router.push('/clients')">Clientes</a>
            </li>
            <li class="breadcrumb-item active" aria-current="page">Detalle</li>
          </ol>
        </nav>
        <h2 class="fw-bold text-dark mb-1">
          <i class="fas fa-user-circle me-2 text-primary no-theme"></i>Detalle del Cliente
        </h2>
        <p class="text-muted mb-0">Información completa e historial</p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-light border rounded-pill px-4 fw-bold text-muted" type="button" @click="router.push('/clients')">
          Volver
        </button>
        <button v-if="client" class="btn btn-outline-dark rounded-pill px-4 fw-bold" type="button" @click="router.push(`/clients/${client.id}/edit`)">
          Editar
        </button>
        <button v-if="client" class="btn btn-danger rounded-pill px-4 fw-bold" type="button" :disabled="deleting" @click="remove">
          Eliminar
        </button>
      </div>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-3">
      {{ message }}
    </div>

    <div v-if="loading" class="text-secondary">Cargando...</div>

    <div v-if="client" class="row mb-4 g-3">
      <div class="col-md-3">
        <div class="card card-modern h-100">
          <div class="card-body d-flex align-items-center">
            <div class="rounded-circle bg-primary bg-opacity-10 no-theme p-3 me-3">
              <i class="fas fa-clipboard-list fa-2x text-primary no-theme"></i>
            </div>
            <div>
              <h5 class="fw-bold mb-0">{{ stats?.total ?? 0 }}</h5>
              <small class="text-muted">Total Órdenes</small>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card card-modern h-100">
          <div class="card-body d-flex align-items-center">
            <div class="rounded-circle bg-warning bg-opacity-10 p-3 me-3">
              <i class="fas fa-clock fa-2x text-warning"></i>
            </div>
            <div>
              <h5 class="fw-bold mb-0">{{ stats?.pending ?? 0 }}</h5>
              <small class="text-muted">Pendientes</small>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card card-modern h-100">
          <div class="card-body d-flex align-items-center">
            <div class="rounded-circle bg-info bg-opacity-10 no-theme p-3 me-3">
              <i class="fas fa-tools fa-2x text-info no-theme"></i>
            </div>
            <div>
              <h5 class="fw-bold mb-0">{{ stats?.inProcess ?? 0 }}</h5>
              <small class="text-muted">En Proceso</small>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card card-modern h-100">
          <div class="card-body d-flex align-items-center">
            <div class="rounded-circle bg-success bg-opacity-10 p-3 me-3">
              <i class="fas fa-check-circle fa-2x text-success"></i>
            </div>
            <div>
              <h5 class="fw-bold mb-0">{{ stats?.completed ?? 0 }}</h5>
              <small class="text-muted">Completadas</small>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div v-if="client" class="row g-4">
      <div class="col-lg-4">
        <div class="card border-0 shadow-sm rounded-4 h-100">
          <div class="card-header bg-white py-3 border-bottom border-light">
            <h5 class="mb-0 fw-bold"><i class="fas fa-info-circle me-2 text-primary no-theme"></i>Información del Cliente</h5>
          </div>
          <div class="card-body">
            <div class="text-center mb-4">
              <div
                class="avatar-circle bg-light text-primary no-theme rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3"
                style="width: 80px; height: 80px; font-size: 2rem"
              >
                <i :class="client.clientType === 'company' ? 'fas fa-building' : 'fas fa-user'"></i>
              </div>
              <h5 class="fw-bold text-dark">
                {{ displayName(client) }}
              </h5>
              <span
                class="badge rounded-pill"
                :class="client.clientType === 'company' ? 'bg-info bg-opacity-10 text-info' : 'bg-success bg-opacity-10 text-success'"
              >
                {{ typeLabel(client) }}
              </span>
            </div>

            <div class="list-group list-group-flush">
              <div v-if="client.clientType === 'company' && client.legalRepresentative" class="list-group-item px-0 border-light">
                <small class="text-muted d-block mb-1">Representante Legal</small>
                <div class="fw-medium"><i class="fas fa-user-tie me-2 text-muted"></i>{{ client.legalRepresentative }}</div>
              </div>
              <div v-if="client.clientType === 'company'" class="list-group-item px-0 border-light">
                <small class="text-muted d-block mb-1">NIT/RUC</small>
                <div class="fw-medium"><i class="fas fa-fingerprint me-2 text-muted"></i>{{ client.taxId || '-' }}</div>
              </div>
              <div class="list-group-item px-0 border-light">
                <small class="text-muted d-block mb-1">Teléfono</small>
                <div class="fw-medium"><i class="fas fa-phone me-2 text-muted"></i>{{ client.phone || '-' }}</div>
              </div>
              <div v-if="client.email" class="list-group-item px-0 border-light">
                <small class="text-muted d-block mb-1">Email</small>
                <div class="fw-medium"><i class="fas fa-envelope me-2 text-muted"></i>{{ client.email }}</div>
              </div>
              <div v-if="client.idNumber" class="list-group-item px-0 border-light">
                <small class="text-muted d-block mb-1">Identificación</small>
                <div class="fw-medium"><i class="fas fa-id-card me-2 text-muted"></i>{{ client.idNumber }}</div>
              </div>
              <div v-if="client.address" class="list-group-item px-0 border-light">
                <small class="text-muted d-block mb-1">Dirección</small>
                <div class="fw-medium"><i class="fas fa-map-marker-alt me-2 text-muted"></i>{{ client.address }}</div>
              </div>
              <div class="list-group-item px-0 border-light">
                <small class="text-muted d-block mb-1">Fecha Registro</small>
                <div class="fw-medium"><i class="fas fa-calendar me-2 text-muted"></i>{{ fmtDate(client.createdAt) }}</div>
              </div>
              <div v-if="client.notes" class="list-group-item px-0 border-light">
                <small class="text-muted d-block mb-1">Notas</small>
                <div class="fw-medium">{{ client.notes }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-8">
        <div class="card border-0 shadow-sm rounded-4">
          <div class="card-header bg-white py-3 border-bottom border-light d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0 fw-bold"><i class="fas fa-history me-2 text-primary no-theme"></i>Historial de Órdenes</h5>
            <button class="btn btn-primary rounded-pill px-3" type="button" @click="router.push('/orders/new')">
              <i class="fas fa-plus me-1"></i>Nueva Orden
            </button>
          </div>
          <div class="card-body p-0">
            <div v-if="orders.length === 0" class="text-center py-5">
              <div class="rounded-circle bg-light p-4 d-inline-block mb-3">
                <i class="fas fa-clipboard-list fa-3x text-muted"></i>
              </div>
              <h5 class="text-muted mb-2">Sin órdenes</h5>
              <p class="text-muted mb-0">Este cliente aún no tiene órdenes registradas.</p>
            </div>

            <div v-else>
              <div class="table-responsive d-none d-lg-block">
                <table class="table table-hover align-middle mb-0">
                  <thead class="bg-light text-muted">
                    <tr>
                      <th class="ps-4">Orden</th>
                      <th>Equipo</th>
                      <th>Estado</th>
                      <th>Fecha</th>
                      <th class="text-end pe-4">Acciones</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="o in orders" :key="o.id">
                      <td class="ps-4 fw-bold text-primary">#{{ o.orderNumber || o.id }}</td>
                      <td>
                        <div class="fw-semibold">{{ `${o.deviceBrand ?? ''} ${o.deviceModel ?? ''}`.trim() || '-' }}</div>
                        <div class="small text-muted">{{ o.serialNumber || '-' }}</div>
                      </td>
                      <td>
                        <span class="badge rounded-pill" :class="statusBadgeClass(o.status)">{{ o.status || '-' }}</span>
                      </td>
                      <td>{{ fmtDate(o.createdAt) }}</td>
                      <td class="text-end pe-4">
                        <button class="btn btn-sm btn-light text-primary shadow-sm rounded-circle" style="width: 36px; height: 36px" type="button" @click="router.push(`/orders/${o.id}`)">
                          <i class="fas fa-eye"></i>
                        </button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div class="d-lg-none p-3">
                <div class="row g-3">
                  <div v-for="o in orders" :key="o.id" class="col-12">
                    <div class="card border-0 shadow-sm rounded-4">
                      <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                          <div>
                            <div class="fw-bold text-primary">#{{ o.orderNumber || o.id }}</div>
                            <div class="small text-muted">{{ `${o.deviceBrand ?? ''} ${o.deviceModel ?? ''}`.trim() || '-' }}</div>
                          </div>
                          <span class="badge rounded-pill" :class="statusBadgeClass(o.status)">{{ o.status || '-' }}</span>
                        </div>
                        <div class="border-top my-3"></div>
                        <div class="small text-muted"><i class="fas fa-barcode me-2"></i>{{ o.serialNumber || '-' }}</div>
                        <div class="small text-muted"><i class="fas fa-calendar me-2"></i>{{ fmtDate(o.createdAt) }}</div>
                        <div class="d-flex justify-content-end mt-3">
                          <button class="btn btn-sm btn-outline-primary rounded-pill px-3" type="button" @click="router.push(`/orders/${o.id}`)">
                            <i class="fas fa-eye me-1"></i>Ver
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div v-if="ordersTotalPages > 1" class="card-footer bg-white border-top border-light py-3">
            <nav aria-label="Paginación de órdenes del cliente">
              <ul class="pagination justify-content-center mb-0">
                <li v-if="ordersPage > 1" class="page-item">
                  <button class="page-link border-0 text-muted" type="button" @click="goOrders(ordersPage - 1)">
                    <i class="fas fa-chevron-left me-1"></i> Anterior
                  </button>
                </li>
                <li v-for="n in ordersPageNumbers" :key="n" class="page-item">
                  <button
                    class="page-link border-0 rounded-circle mx-1 d-flex align-items-center justify-content-center"
                    :class="n === ordersPage ? 'bg-primary text-white shadow-sm' : 'text-muted'"
                    style="width: 35px; height: 35px"
                    type="button"
                    @click="goOrders(n)"
                  >
                    {{ n }}
                  </button>
                </li>
                <li v-if="ordersPage < ordersTotalPages" class="page-item">
                  <button class="page-link border-0 text-muted" type="button" @click="goOrders(ordersPage + 1)">
                    Siguiente <i class="fas fa-chevron-right ms-1"></i>
                  </button>
                </li>
              </ul>
            </nav>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
