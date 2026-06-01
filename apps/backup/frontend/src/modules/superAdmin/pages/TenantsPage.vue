<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { apiGet, apiPost } from '../../../api/http'
import { useRouter } from 'vue-router'

type TenantDto = { id: number; companyName: string; status: 'active' | 'suspended'; createdAt: string }
type CreateTenantResult = { tenantId: number; adminUserId: number }
type SyncUsersResult = {
  totals: { created: number; exists: number; conflicts: number; fails: number; skipped: number }
}

const tenants = ref<TenantDto[]>([])
const router = useRouter()
const message = ref('')
const busy = ref(false)

const showModal = ref(false)
const companyName = ref('')
const dbHost = ref('localhost')
const dbPort = ref<number | null>(3306)
const dbName = ref('')
const dbUser = ref('')
const dbPass = ref('')
const adminName = ref('')
const adminEmail = ref('')
const adminPassword = ref('')

function openCreate() {
  message.value = ''
  companyName.value = ''
  dbHost.value = 'localhost'
  dbPort.value = 3306
  dbName.value = ''
  dbUser.value = ''
  dbPass.value = ''
  adminName.value = ''
  adminEmail.value = ''
  adminPassword.value = ''
  showModal.value = true
}

function close() {
  showModal.value = false
}

async function load() {
  const res = await apiGet<TenantDto[]>('/super-admin/tenants')
  tenants.value = res.ok ? res.data : []
}

async function create() {
  if (busy.value) return
  message.value = ''
  const nm = companyName.value.trim()
  const host = dbHost.value.trim()
  const name = dbName.value.trim()
  const user = dbUser.value.trim()
  const pass = dbPass.value
  const aName = adminName.value.trim()
  const aEmail = adminEmail.value.trim()
  const aPass = adminPassword.value

  if (!nm || !host || !name || !user || !pass || !aName || !aEmail || !aPass) {
    message.value = 'Completa todos los campos'
    return
  }

  busy.value = true
  const res = await apiPost<CreateTenantResult, Record<string, unknown>>('/super-admin/tenants', {
    companyName: nm,
    dbHost: host,
    dbPort: Number(dbPort.value ?? 3306),
    dbName: name,
    dbUser: user,
    dbPass: pass,
    adminName: aName,
    adminEmail: aEmail,
    adminPassword: aPass,
  })
  busy.value = false

  if (!res.ok) {
    message.value = res.error.message
    return
  }

  close()
  await load()
  router.push(`/super-admin/tenants/${res.data.tenantId}`)
}

async function syncAllUsers() {
  if (busy.value) return
  const ok = window.confirm('¿Sincronizar usuarios desde todas las DBs tenant activas hacia usuarios_master?')
  if (!ok) return
  busy.value = true
  message.value = ''
  const res = await apiPost<SyncUsersResult, Record<string, never>>('/super-admin/tenants/sync-users', {})
  busy.value = false
  if (!res.ok) {
    message.value = res.error.message
    return
  }
  message.value = `Sync completo. created=${res.data.totals.created} exists=${res.data.totals.exists} conflicts=${res.data.totals.conflicts} fails=${res.data.totals.fails} skipped=${res.data.totals.skipped}`
}

onMounted(async () => {
  await load()
})
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-0">{{ message }}</div>

    <div class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-3">
          <h5 class="fw-semibold mb-0">Tenants</h5>
          <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-outline-dark rounded-pill" type="button" :disabled="busy" @click="syncAllUsers">
              Sync usuarios
            </button>
            <button class="btn btn-dark rounded-pill" type="button" :disabled="busy" @click="openCreate">Nuevo tenant</button>
          </div>
        </div>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead>
            <tr>
              <th>ID</th>
              <th>Empresa</th>
              <th>Estado</th>
              <th>Creado</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="t in tenants" :key="t.id">
              <td>{{ t.id }}</td>
              <td>
                <button class="btn btn-link p-0 text-decoration-none" type="button" @click="router.push(`/super-admin/tenants/${t.id}`)">
                  {{ t.companyName }}
                </button>
              </td>
              <td>{{ t.status }}</td>
              <td>{{ t.createdAt }}</td>
            </tr>
            <tr v-if="tenants.length === 0">
              <td colspan="4" class="text-secondary">Sin datos</td>
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
            <h5 class="modal-title">Crear tenant</h5>
            <button type="button" class="btn-close" :disabled="busy" @click="close"></button>
          </div>
          <div class="modal-body">
            <div class="row g-3">
              <div class="col-12">
                <label class="form-label">Empresa</label>
                <input v-model="companyName" class="form-control" />
              </div>

              <div class="col-12">
                <div class="fw-semibold">Base de datos</div>
                <div class="text-secondary small">Debe existir y permitir conexión con estos credenciales</div>
              </div>
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

              <div class="col-12">
                <div class="fw-semibold">Admin (master)</div>
              </div>
              <div class="col-12 col-md-4">
                <label class="form-label">Nombre</label>
                <input v-model="adminName" class="form-control" />
              </div>
              <div class="col-12 col-md-4">
                <label class="form-label">Email</label>
                <input v-model="adminEmail" class="form-control" />
              </div>
              <div class="col-12 col-md-4">
                <label class="form-label">Password</label>
                <input v-model="adminPassword" class="form-control" type="password" />
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-light border rounded-pill px-4 fw-bold text-muted" type="button" :disabled="busy" @click="close">
              Cancelar
            </button>
            <button class="btn btn-dark rounded-pill px-4" type="button" :disabled="busy" @click="create">
              Crear
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
