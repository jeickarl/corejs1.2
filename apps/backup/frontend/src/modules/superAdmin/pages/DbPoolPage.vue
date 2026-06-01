<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { apiGet, apiPatch, apiPost } from '../../../api/http'

type DbPoolStatus = 'available' | 'reserved' | 'used' | 'error'
type DbPoolStats = { available: number; reserved: number; used: number; error: number }
type DbPoolItem = {
  id: number
  dbHost: string
  dbPort: number
  dbName: string
  dbUser: string
  status: DbPoolStatus
  empresaId: number | null
  empresaNombre: string | null
  reservedAt: string | null
  usedAt: string | null
  createdAt: string
  lastError: string | null
}
type DbPoolList = { stats: DbPoolStats; items: DbPoolItem[] }

const loading = ref(false)
const busy = ref(false)
const message = ref('')
const stats = ref<DbPoolStats>({ available: 0, reserved: 0, used: 0, error: 0 })
const items = ref<DbPoolItem[]>([])

const showModal = ref(false)
const dbHost = ref('localhost')
const dbPort = ref<number | null>(3306)
const dbName = ref('')
const dbUser = ref('')
const dbPass = ref('')


async function load() {
  loading.value = true
  message.value = ''
  const res = await apiGet<DbPoolList>('/super-admin/db-pool')
  loading.value = false
  if (!res.ok) {
    message.value = res.error.message
    items.value = []
    stats.value = { available: 0, reserved: 0, used: 0, error: 0 }
    return
  }
  stats.value = res.data.stats
  items.value = res.data.items
}

function openAdd() {
  message.value = ''
  dbHost.value = 'localhost'
  dbPort.value = 3306
  dbName.value = ''
  dbUser.value = ''
  dbPass.value = ''
  showModal.value = true
}

function close() {
  showModal.value = false
}

async function add() {
  if (busy.value) return
  message.value = ''
  const host = dbHost.value.trim()
  const name = dbName.value.trim()
  const user = dbUser.value.trim()
  const pass = dbPass.value
  if (!host || !name || !user || !pass) {
    message.value = 'Completa todos los campos'
    return
  }
  busy.value = true
  const res = await apiPost<{ id: number }, Record<string, unknown>>('/super-admin/db-pool', {
    dbHost: host,
    dbPort: Number(dbPort.value ?? 3306),
    dbName: name,
    dbUser: user,
    dbPass: pass,
  })
  busy.value = false
  if (!res.ok) {
    message.value = res.error.message
    return
  }
  close()
  await load()
}

async function syncFromEmpresas() {
  if (busy.value) return
  busy.value = true
  message.value = ''
  const res = await apiPost<{ added: number; skipped: number }, Record<string, never>>('/super-admin/db-pool/sync-from-empresas', {})
  busy.value = false
  message.value = res.ok ? `Sincronización completa. Agregadas: ${res.data.added}. Omitidas: ${res.data.skipped}.` : res.error.message
  await load()
}

async function markAvailableItem(id: number) {
  const ok = window.confirm('¿Marcar como disponible?')
  if (!ok) return
  busy.value = true
  message.value = ''
  const res = await apiPatch<{ done: true }, Record<string, never>>(`/super-admin/db-pool/${id}/mark-available`, {})
  busy.value = false
  if (!res.ok) {
    message.value = res.error.message
    return
  }
  await load()
}

onMounted(load)
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <h5 class="fw-semibold mb-1">DB Pool</h5>
        <div class="text-secondary small">tenant_db_pool</div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-outline-dark rounded-pill" type="button" :disabled="busy || loading" @click="syncFromEmpresas">
          Sincronizar desde empresas
        </button>
        <button class="btn btn-dark rounded-pill" type="button" :disabled="busy || loading" @click="openAdd">
          Agregar base
        </button>
        <button class="btn btn-outline-secondary rounded-pill" type="button" :disabled="busy || loading" @click="load">
          Refrescar
        </button>
      </div>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-0">{{ message }}</div>

    <div class="row g-3">
      <div class="col-6 col-md-3">
        <div class="card shadow-soft border-0 rounded-custom">
          <div class="card-body">
            <div class="text-secondary small">Disponibles</div>
            <div class="fw-semibold fs-4">{{ stats.available }}</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card shadow-soft border-0 rounded-custom">
          <div class="card-body">
            <div class="text-secondary small">Reservadas</div>
            <div class="fw-semibold fs-4">{{ stats.reserved }}</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card shadow-soft border-0 rounded-custom">
          <div class="card-body">
            <div class="text-secondary small">Usadas</div>
            <div class="fw-semibold fs-4">{{ stats.used }}</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card shadow-soft border-0 rounded-custom">
          <div class="card-body">
            <div class="text-secondary small">Error</div>
            <div class="fw-semibold fs-4">{{ stats.error }}</div>
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
                <th>DB</th>
                <th>Usuario</th>
                <th>Status</th>
                <th>Empresa</th>
                <th>Reserved</th>
                <th>Used</th>
                <th>Error</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in items" :key="p.id">
                <td>{{ p.id }}</td>
                <td class="font-monospace small">{{ p.dbHost }}:{{ p.dbPort }} / {{ p.dbName }}</td>
                <td class="font-monospace small">{{ p.dbUser }}</td>
                <td>
                  <span v-if="p.status === 'available'" class="badge bg-success">available</span>
                  <span v-else-if="p.status === 'reserved'" class="badge bg-warning text-dark">reserved</span>
                  <span v-else-if="p.status === 'used'" class="badge bg-primary">used</span>
                  <span v-else class="badge bg-danger">error</span>
                </td>
                <td class="text-truncate" style="max-width: 180px;">
                  <span v-if="p.empresaNombre" class="fw-semibold">{{ p.empresaNombre }}</span>
                  <span v-else class="text-secondary">—</span>
                </td>
                <td class="small text-secondary">{{ p.reservedAt ?? '' }}</td>
                <td class="small text-secondary">{{ p.usedAt ?? '' }}</td>
                <td class="small text-secondary text-truncate" style="max-width: 220px;">{{ p.lastError ?? '' }}</td>
                <td class="text-end">
                  <button
                    class="btn btn-sm btn-outline-dark rounded-pill"
                    type="button"
                    :disabled="busy || p.status === 'used'"
                    @click="markAvailableItem(p.id)"
                  >
                    Marcar disponible
                  </button>
                </td>
              </tr>
              <tr v-if="items.length === 0">
                <td colspan="9" class="text-secondary">Sin datos</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div v-if="showModal" class="modal-backdrop fade show"></div>
    <div v-if="showModal" class="modal fade show d-block" tabindex="-1" role="dialog">
      <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 rounded-custom shadow">
          <div class="modal-header">
            <h5 class="modal-title">Agregar DB al pool</h5>
            <button type="button" class="btn-close" :disabled="busy" @click="close"></button>
          </div>
          <div class="modal-body">
            <div class="row g-3">
              <div class="col-12 col-md-4">
                <label class="form-label">Host</label>
                <input v-model="dbHost" class="form-control" />
              </div>
              <div class="col-12 col-md-2">
                <label class="form-label">Port</label>
                <input v-model.number="dbPort" class="form-control" type="number" min="1" step="1" />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label">DB name</label>
                <input v-model="dbName" class="form-control" />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label">DB user</label>
                <input v-model="dbUser" class="form-control" />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label">DB password</label>
                <input v-model="dbPass" class="form-control" type="password" />
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-light border rounded-pill px-4 fw-bold text-muted" type="button" :disabled="busy" @click="close">
              Cancelar
            </button>
            <button class="btn btn-dark rounded-pill px-4" type="button" :disabled="busy" @click="add">
              Guardar
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
