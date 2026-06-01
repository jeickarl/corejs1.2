<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { apiDelete, apiGet } from '../../../api/http'

type ClientDto = {
  id: number
  clientType: string
  firstName: string
  companyName: string
  phone: string
  email: string
  idNumber: string
  createdAt: string
}

type PageDto = { items: ClientDto[]; page: number; perPage: number; total: number }
type StatsDto = { totalClients: number; individualClients: number; companyClients: number; recentClients: number }

const search = ref('')
const page = ref(1)
const perPage = ref(10)
const total = ref(0)
const items = ref<ClientDto[]>([])
const stats = ref<StatsDto | null>(null)
const message = ref('')
const router = useRouter()

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))

function fmtDate(iso: string): string {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  return d.toLocaleDateString('es-CO', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

function displayName(c: ClientDto): string {
  return (c.companyName ?? '').trim() || (c.firstName ?? '').trim() || `Cliente #${c.id}`
}

async function load() {
  message.value = ''
  const qs = new URLSearchParams({
    search: search.value,
    page: String(page.value),
    perPage: String(perPage.value),
  })
  const res = await apiGet<PageDto>(`/clients?${qs.toString()}`)
  if (!res.ok) {
    message.value = res.error.message
    items.value = []
    total.value = 0
    return
  }
  items.value = res.data.items
  total.value = res.data.total
}

async function loadStats() {
  const res = await apiGet<StatsDto>('/clients/stats')
  stats.value = res.ok ? res.data : null
}

function go(p: number) {
  page.value = Math.min(Math.max(1, p), totalPages.value)
  void load()
}

function doSearch() {
  page.value = 1
  void load()
}

function clearSearch() {
  search.value = ''
  doSearch()
}

function open(id: number) {
  void router.push(`/clients/${id}`)
}

function edit(id: number) {
  void router.push(`/clients/${id}/edit`)
}

async function removeClient(c: ClientDto) {
  const ok = window.confirm('¿Eliminar este cliente?')
  if (!ok) return
  const res = await apiDelete<{ deleted: true }>(`/clients/${c.id}`)
  if (!res.ok) {
    message.value = res.error.message
    return
  }
  await loadStats()
  await load()
}

const pageNumbers = computed(() => {
  const start = Math.max(1, page.value - 2)
  const end = Math.min(totalPages.value, page.value + 2)
  const out: number[] = []
  for (let i = start; i <= end; i++) out.push(i)
  return out
})

onMounted(async () => {
  await loadStats()
  await load()
})
</script>

<template>
  <div class="d-flex flex-column">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
      <div>
        <h2 class="fw-bold text-dark mb-1">
          <i class="fas fa-users me-2 text-primary no-theme"></i>Gestión de Clientes
        </h2>
        <p class="text-muted mb-0">Administra la información de tus clientes</p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-primary rounded-pill px-4 shadow-sm" type="button" @click="router.push('/clients/new')">
          <i class="fas fa-user-plus me-2"></i>Nuevo Cliente
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
              <i class="fas fa-users fa-2x text-primary no-theme"></i>
            </div>
            <div>
              <h5 class="fw-bold mb-0">{{ stats?.totalClients ?? total }}</h5>
              <small class="text-muted">Total Clientes</small>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card card-modern h-100">
          <div class="card-body d-flex align-items-center">
            <div class="rounded-circle bg-success bg-opacity-10 p-3 me-3">
              <i class="fas fa-user fa-2x text-success"></i>
            </div>
            <div>
              <h5 class="fw-bold mb-0">{{ stats?.individualClients ?? 0 }}</h5>
              <small class="text-muted">Personas Naturales</small>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card card-modern h-100">
          <div class="card-body d-flex align-items-center">
            <div class="rounded-circle bg-info bg-opacity-10 no-theme p-3 me-3">
              <i class="fas fa-building fa-2x text-info no-theme"></i>
            </div>
            <div>
              <h5 class="fw-bold mb-0">{{ stats?.companyClients ?? 0 }}</h5>
              <small class="text-muted">Empresas</small>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card card-modern h-100">
          <div class="card-body d-flex align-items-center">
            <div class="rounded-circle bg-warning bg-opacity-10 p-3 me-3">
              <i class="fas fa-user-plus fa-2x text-warning"></i>
            </div>
            <div>
              <h5 class="fw-bold mb-0">{{ stats?.recentClients ?? 0 }}</h5>
              <small class="text-muted">Nuevos (30 días)</small>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card shadow-sm rounded-4 border-0 overflow-hidden">
      <div class="card-header bg-white border-0 pt-3 pb-2">
        <form class="row g-2 align-items-center" @submit.prevent="doSearch">
          <div class="col-md-8">
            <div class="input-group">
              <span class="input-group-text bg-light border-end-0 rounded-start-pill px-3">
                <i class="fas fa-search text-muted"></i>
              </span>
              <input v-model="search" class="form-control bg-light border-start-0 rounded-end-pill px-3" placeholder="Buscar por nombre, empresa, email..." />
            </div>
          </div>
          <div class="col-md-4">
            <div class="d-flex gap-2 justify-content-end">
              <button type="submit" class="btn btn-primary rounded-pill px-3">
                <i class="fas fa-search me-1"></i>Buscar
              </button>
              <button v-if="search.trim()" class="btn btn-outline-secondary rounded-pill px-3" type="button" @click="clearSearch">
                <i class="fas fa-times me-1"></i>Limpiar
              </button>
            </div>
          </div>
        </form>
      </div>

      <div class="card-body p-0">
        <div v-if="items.length === 0" class="text-center py-5">
          <div class="rounded-circle bg-light p-4 d-inline-block mb-3">
            <i class="fas fa-user-slash fa-3x text-muted"></i>
          </div>
          <h5 class="text-muted mb-2">No se encontraron clientes</h5>
          <p class="text-muted mb-3">No hay clientes que coincidan con los criterios de búsqueda.</p>
          <button class="btn btn-primary rounded-pill px-4" type="button" @click="router.push('/clients/new')">Agregar Primer Cliente</button>
        </div>

        <div v-else>
          <div class="table-responsive d-none d-lg-block bg-white rounded-bottom-4 shadow-sm border-0 w-100">
            <table class="table table-hover align-middle mb-0 text-nowrap">
              <thead class="bg-light text-muted">
                <tr>
                  <th class="ps-4">ID</th>
                  <th>Identificación</th>
                  <th>Tipo</th>
                  <th>Contacto</th>
                  <th>Cliente</th>
                  <th>Fecha Registro</th>
                  <th class="text-end pe-4">Acciones</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="c in items" :key="c.id">
                  <td class="ps-4">
                    <span class="fw-bold text-primary">{{ c.id }}</span>
                  </td>
                  <td>
                    <span v-if="(c.idNumber ?? '').trim()" class="font-monospace text-dark bg-light px-2 py-1 rounded small">{{ c.idNumber }}</span>
                    <span v-else class="text-muted small">No especificado</span>
                  </td>
                  <td>
                    <span
                      class="badge rounded-pill"
                      :class="c.clientType === 'company' ? 'bg-info bg-opacity-10 text-info' : 'bg-success bg-opacity-10 text-success'"
                    >
                      <i :class="c.clientType === 'company' ? 'fas fa-building me-1' : 'fas fa-user me-1'"></i>
                      {{ c.clientType === 'company' ? 'Empresa' : 'Persona Natural' }}
                    </span>
                  </td>
                  <td>
                    <div class="d-flex flex-column">
                      <span class="fw-medium"><i class="fas fa-phone me-1 text-muted"></i>{{ c.phone || '-' }}</span>
                      <span class="small text-muted"><i class="fas fa-envelope me-1"></i>{{ c.email || '-' }}</span>
                    </div>
                  </td>
                  <td>
                    <div class="d-flex align-items-center">
                      <div
                        class="rounded-circle bg-light d-flex align-items-center justify-content-center me-2"
                        style="width: 40px; height: 40px"
                      >
                        <i :class="c.clientType === 'company' ? 'fas fa-building text-info' : 'fas fa-user text-success'"></i>
                      </div>
                      <div class="d-flex flex-column">
                        <span class="fw-bold">{{ displayName(c) }}</span>
                        <span class="small text-muted">Cliente</span>
                      </div>
                    </div>
                  </td>
                  <td>{{ fmtDate(c.createdAt) }}</td>
                  <td class="text-end pe-4">
                    <div class="d-flex justify-content-end gap-2">
                      <button class="btn btn-sm btn-light text-primary shadow-sm rounded-circle d-flex align-items-center justify-content-center" style="width: 36px; height: 36px" type="button" title="Ver" @click="open(c.id)">
                        <i class="fas fa-eye"></i>
                      </button>
                      <button class="btn btn-sm btn-light text-secondary shadow-sm rounded-circle d-flex align-items-center justify-content-center" style="width: 36px; height: 36px" type="button" title="Editar" @click="edit(c.id)">
                        <i class="fas fa-pen"></i>
                      </button>
                      <button class="btn btn-sm btn-light text-danger shadow-sm rounded-circle d-flex align-items-center justify-content-center" style="width: 36px; height: 36px" type="button" title="Eliminar" @click="removeClient(c)">
                        <i class="fas fa-trash"></i>
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="d-lg-none p-3">
            <div class="row g-3">
              <div v-for="c in items" :key="c.id" class="col-12">
                <div class="card border-0 shadow-sm rounded-4">
                  <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                      <div class="d-flex align-items-center">
                        <div
                          class="rounded-circle bg-light d-flex align-items-center justify-content-center me-3"
                          style="width: 48px; height: 48px"
                        >
                          <i :class="c.clientType === 'company' ? 'fas fa-building text-info' : 'fas fa-user text-success'"></i>
                        </div>
                        <div>
                          <div class="fw-bold">{{ displayName(c) }}</div>
                          <div class="text-muted small">ID: {{ c.id }}</div>
                        </div>
                      </div>
                      <span
                        class="badge rounded-pill"
                        :class="c.clientType === 'company' ? 'bg-info bg-opacity-10 text-info' : 'bg-success bg-opacity-10 text-success'"
                      >
                        {{ c.clientType === 'company' ? 'Empresa' : 'Persona' }}
                      </span>
                    </div>
                    <div class="border-top my-3"></div>
                    <div class="d-flex flex-column gap-1">
                      <div class="small text-muted"><i class="fas fa-phone me-2"></i>{{ c.phone || '-' }}</div>
                      <div class="small text-muted"><i class="fas fa-envelope me-2"></i>{{ c.email || '-' }}</div>
                      <div class="small text-muted"><i class="fas fa-id-card me-2"></i>{{ c.idNumber || 'No especificado' }}</div>
                      <div class="small text-muted"><i class="fas fa-calendar me-2"></i>{{ fmtDate(c.createdAt) }}</div>
                    </div>
                    <div class="d-flex gap-2 justify-content-end mt-3">
                      <button class="btn btn-sm btn-outline-primary rounded-pill px-3" type="button" @click="open(c.id)">
                        <i class="fas fa-eye me-1"></i>Ver
                      </button>
                      <button class="btn btn-sm btn-outline-secondary rounded-pill px-3" type="button" @click="edit(c.id)">
                        <i class="fas fa-pen me-1"></i>Editar
                      </button>
                      <button class="btn btn-sm btn-outline-danger rounded-pill px-3" type="button" @click="removeClient(c)">
                        <i class="fas fa-trash me-1"></i>Eliminar
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div v-if="totalPages > 1" class="card-footer bg-white border-top border-light py-3">
        <nav aria-label="Paginación de clientes">
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
</template>
