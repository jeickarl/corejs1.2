<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { apiDelete, apiGet, apiPatch, apiPost } from '../../../api/http'

type TenantDetailDto = {
  id: number
  companyName: string
  status: 'active' | 'suspended'
  createdAt: string
  dbHost?: string
  dbPort?: number
  dbName?: string
  dbUser?: string
  licenseCount?: number
  lastLicense?: string | null
}

type MasterUserDto = { id: number; email: string; name: string; role: string; active: boolean }
type SyncUsersResult = {
  totals: { created: number; exists: number; conflicts: number; fails: number; skipped: number }
}
type RepairSchemaResult = {
  tenantId: number
  companyName: string
  status: string
  ok: number
  fail: number
  fails: { step: string; error: string }[]
}

const route = useRoute()
const id = computed(() => Number(route.params.id))

const tenant = ref<TenantDetailDto | null>(null)
const users = ref<MasterUserDto[]>([])
const busy = ref(false)
const editMode = ref(false)
const saveMessage = ref('')
const testMessage = ref('')
const licenseMessage = ref('')
const userMessage = ref('')
const syncMessage = ref('')
const repairMessage = ref('')

const formCompanyName = ref('')
const formStatus = ref<'active' | 'suspended' | 'provisioning'>('active')
const formDbHost = ref('localhost')
const formDbPort = ref(3306)
const formDbName = ref('')
const formDbUser = ref('')
const formDbPass = ref('')

const availableLicenses = ref<string[]>([])
const selectedLicense = ref('')

const promptOpen = ref(false)
const promptTitle = ref('')
const promptLabel = ref('')
const promptType = ref<'text' | 'password'>('text')
const promptValue = ref('')
const promptAction = ref<'resetPassword' | 'changeEmail' | null>(null)
const promptUser = ref<MasterUserDto | null>(null)

const confirmOpen = ref(false)
const confirmTitle = ref('')
const confirmBody = ref('')
const confirmAction = ref<'deleteUser' | 'deleteTenant' | null>(null)
const confirmUser = ref<MasterUserDto | null>(null)

async function load() {
  const res = await apiGet<TenantDetailDto>(`/super-admin/tenants/${id.value}`)
  tenant.value = res.ok ? res.data : null

  const resUsers = await apiGet<MasterUserDto[]>(`/super-admin/tenants/${id.value}/users`)
  users.value = resUsers.ok ? resUsers.data : []
  userMessage.value = ''

  if (tenant.value) {
    formCompanyName.value = tenant.value.companyName
    formStatus.value = (tenant.value.status as 'active' | 'suspended') ?? 'active'
    formDbHost.value = tenant.value.dbHost ?? 'localhost'
    formDbPort.value = tenant.value.dbPort ?? 3306
    formDbName.value = tenant.value.dbName ?? ''
    formDbUser.value = tenant.value.dbUser ?? ''
    formDbPass.value = ''
  }

  if ((tenant.value?.licenseCount ?? 0) === 0) {
    const resLic = await apiGet<string[]>('/super-admin/licenses/available')
    availableLicenses.value = resLic.ok ? resLic.data : []
  } else {
    availableLicenses.value = []
  }
}

async function setStatus(status: 'active' | 'suspended') {
  busy.value = true
  try {
    await apiPost<{ done: true }, { status: 'active' | 'suspended' }>(
      `/super-admin/tenants/${id.value}/status`,
      { status },
    )
    await load()
  } finally {
    busy.value = false
  }
}

async function saveTenant() {
  saveMessage.value = ''
  busy.value = true
  try {
    const res = await apiPatch<{ done: true }, Record<string, unknown>>(
      `/super-admin/tenants/${id.value}`,
      {
        companyName: formCompanyName.value,
        status: formStatus.value,
        dbHost: formDbHost.value,
        dbPort: Number(formDbPort.value),
        dbName: formDbName.value,
        dbUser: formDbUser.value,
        dbPass: formDbPass.value ? formDbPass.value : null,
      },
    )
    saveMessage.value = res.ok ? 'Empresa actualizada.' : res.error.message
    await load()
    editMode.value = false
  } finally {
    busy.value = false
  }
}

async function testDb() {
  testMessage.value = ''
  busy.value = true
  try {
    const res = await apiPatch<{ ok: true }, Record<string, unknown>>(
      `/super-admin/tenants/${id.value}/test-db`,
      {
        dbHost: formDbHost.value,
        dbPort: Number(formDbPort.value),
        dbName: formDbName.value,
        dbUser: formDbUser.value,
        dbPass: formDbPass.value ? formDbPass.value : null,
      },
    )
    testMessage.value = res.ok ? 'Conexión OK.' : res.error.message
  } finally {
    busy.value = false
  }
}

async function generateLicense() {
  licenseMessage.value = ''
  busy.value = true
  try {
    const res = await apiPost<{ code: string }, Record<string, never>>(
      `/super-admin/licenses/tenant/${id.value}/generate-assign`,
      {},
    )
    licenseMessage.value = res.ok ? `Licencia generada: ${res.data.code}` : res.error.message
    await load()
  } finally {
    busy.value = false
  }
}

async function assignLicense() {
  licenseMessage.value = ''
  if (!selectedLicense.value) return
  busy.value = true
  try {
    const res = await apiPost<{ done: true }, { code: string }>(
      `/super-admin/licenses/tenant/${id.value}/assign`,
      { code: selectedLicense.value },
    )
    licenseMessage.value = res.ok ? 'Licencia asignada.' : res.error.message
    await load()
  } finally {
    busy.value = false
  }
}

async function syncUsers() {
  const ok = window.confirm('¿Sincronizar usuarios desde la DB tenant hacia usuarios_master?')
  if (!ok) return
  syncMessage.value = ''
  busy.value = true
  try {
    const res = await apiPost<SyncUsersResult, Record<string, never>>(`/super-admin/tenants/${id.value}/sync-users`, {})
    syncMessage.value = res.ok
      ? `Sync completo. created=${res.data.totals.created} exists=${res.data.totals.exists} conflicts=${res.data.totals.conflicts} fails=${res.data.totals.fails} skipped=${res.data.totals.skipped}`
      : res.error.message
    await load()
  } finally {
    busy.value = false
  }
}

async function repairSchema() {
  const ok = window.confirm('¿Ejecutar reparación de esquema en la DB de este tenant?')
  if (!ok) return
  repairMessage.value = ''
  busy.value = true
  try {
    const res = await apiPost<RepairSchemaResult, Record<string, never>>(`/super-admin/repair-schema/tenant/${id.value}`, {})
    if (!res.ok) {
      repairMessage.value = res.error.message
      return
    }
    const f = res.data.fail
    repairMessage.value = `Repair completo. ok=${res.data.ok} fail=${f}${f ? ' (ver consola)' : ''}`
    if (f) {
      console.error('RepairSchema fails', res.data.fails)
    }
    await load()
  } finally {
    busy.value = false
  }
}

onMounted(load)

function openResetPassword(u: MasterUserDto) {
  promptTitle.value = `Reset clave: ${u.email}`
  promptLabel.value = 'Nueva clave'
  promptType.value = 'password'
  promptValue.value = ''
  promptAction.value = 'resetPassword'
  promptUser.value = u
  promptOpen.value = true
}

function openChangeEmail(u: MasterUserDto) {
  promptTitle.value = `Cambiar email: ${u.email}`
  promptLabel.value = 'Nuevo email'
  promptType.value = 'text'
  promptValue.value = u.email
  promptAction.value = 'changeEmail'
  promptUser.value = u
  promptOpen.value = true
}

function closePrompt() {
  promptOpen.value = false
  promptTitle.value = ''
  promptLabel.value = ''
  promptType.value = 'text'
  promptValue.value = ''
  promptAction.value = null
  promptUser.value = null
}

async function submitPrompt() {
  if (!promptUser.value || !promptAction.value) return
  const value = promptValue.value.trim()
  if (!value) return

  userMessage.value = ''
  busy.value = true
  try {
    if (promptAction.value === 'resetPassword') {
      const res = await apiPatch<{ done: true }, { newPassword: string }>(
        `/super-admin/tenants/${id.value}/users/${promptUser.value.id}/password`,
        { newPassword: value },
      )
      userMessage.value = res.ok ? 'Clave actualizada.' : res.error.message
    } else if (promptAction.value === 'changeEmail') {
      const res = await apiPatch<{ done: true }, { newEmail: string }>(
        `/super-admin/tenants/${id.value}/users/${promptUser.value.id}/email`,
        { newEmail: value },
      )
      userMessage.value = res.ok ? 'Email actualizado.' : res.error.message
      if (res.ok) await load()
    }
  } finally {
    busy.value = false
    closePrompt()
  }
}

function openDeleteUser(u: MasterUserDto) {
  confirmTitle.value = 'Eliminar usuario'
  confirmBody.value = `¿Eliminar usuario ${u.email}?`
  confirmAction.value = 'deleteUser'
  confirmUser.value = u
  confirmOpen.value = true
}

function openDeleteTenant() {
  confirmTitle.value = 'Eliminar empresa'
  confirmBody.value = `¿Eliminar empresa "${tenant.value?.companyName}"?`
  confirmAction.value = 'deleteTenant'
  confirmUser.value = null
  confirmOpen.value = true
}

function closeConfirm() {
  confirmOpen.value = false
  confirmTitle.value = ''
  confirmBody.value = ''
  confirmAction.value = null
  confirmUser.value = null
}

async function confirmProceed() {
  if (!confirmAction.value) return

  userMessage.value = ''
  busy.value = true
  try {
    if (confirmAction.value === 'deleteUser') {
      if (!confirmUser.value) return
      const res = await apiDelete<{ deleted: true }>(`/super-admin/tenants/${id.value}/users/${confirmUser.value.id}`)
      userMessage.value = res.ok ? 'Usuario eliminado.' : res.error.message
      if (res.ok) await load()
    } else if (confirmAction.value === 'deleteTenant') {
      saveMessage.value = ''
      const res = await apiDelete<{ deleted: true }>(`/super-admin/tenants/${id.value}`)
      saveMessage.value = res.ok ? 'Empresa eliminada.' : res.error.message
    }
  } finally {
    busy.value = false
    closeConfirm()
  }
}
</script>

<template>
  <div v-if="tenant" class="d-flex flex-column gap-3">
    <teleport to="body">
      <div
        v-if="promptOpen"
        class="position-fixed top-0 start-0 w-100 h-100"
        style="background: rgba(0,0,0,.5); z-index: 1050;"
      >
        <div
          class="position-absolute top-50 start-50 translate-middle bg-white rounded-4 p-3 shadow"
          style="min-width: 320px; max-width: 92vw;"
        >
          <div class="d-flex align-items-start justify-content-between gap-3">
            <div class="fw-semibold">{{ promptTitle }}</div>
            <button class="btn-close" type="button" :disabled="busy" @click="closePrompt"></button>
          </div>
          <div class="mt-3">
            <label class="form-label">{{ promptLabel }}</label>
            <input v-model="promptValue" class="form-control" :type="promptType" :disabled="busy" />
          </div>
          <div class="d-flex justify-content-end gap-2 mt-3">
            <button class="btn btn-outline-secondary rounded-pill" type="button" :disabled="busy" @click="closePrompt">
              Cancelar
            </button>
            <button
              class="btn btn-dark rounded-pill"
              type="button"
              :disabled="busy || !promptValue.trim()"
              @click="submitPrompt"
            >
              Guardar
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="confirmOpen"
        class="position-fixed top-0 start-0 w-100 h-100"
        style="background: rgba(0,0,0,.5); z-index: 1050;"
      >
        <div
          class="position-absolute top-50 start-50 translate-middle bg-white rounded-4 p-3 shadow"
          style="min-width: 320px; max-width: 92vw;"
        >
          <div class="d-flex align-items-start justify-content-between gap-3">
            <div class="fw-semibold">{{ confirmTitle }}</div>
            <button class="btn-close" type="button" :disabled="busy" @click="closeConfirm"></button>
          </div>
          <div class="mt-3">{{ confirmBody }}</div>
          <div class="d-flex justify-content-end gap-2 mt-3">
            <button class="btn btn-outline-secondary rounded-pill" type="button" :disabled="busy" @click="closeConfirm">
              Cancelar
            </button>
            <button class="btn btn-danger rounded-pill" type="button" :disabled="busy" @click="confirmProceed">
              Eliminar
            </button>
          </div>
        </div>
      </div>
    </teleport>

    <div class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between">
          <div>
            <h5 class="fw-semibold mb-1">{{ tenant.companyName }}</h5>
            <div class="text-secondary small">ID {{ tenant.id }} · {{ tenant.createdAt }}</div>
          </div>
          <div class="d-flex gap-2">
            <button
              class="btn btn-outline-dark rounded-pill"
              type="button"
              :disabled="busy"
              @click="editMode = !editMode"
            >
              Editar
            </button>
            <button class="btn btn-outline-danger rounded-pill" type="button" :disabled="busy" @click="openDeleteTenant">
              Eliminar
            </button>
            <button
              v-if="tenant.status !== 'active'"
              class="btn btn-outline-success rounded-pill"
              type="button"
              :disabled="busy"
              @click="setStatus('active')"
            >
              Activar
            </button>
            <button
              v-else
              class="btn btn-outline-warning rounded-pill"
              type="button"
              :disabled="busy"
              @click="setStatus('suspended')"
            >
              Suspender
            </button>
          </div>
        </div>

        <div v-if="saveMessage" class="alert alert-info border-0 shadow-sm mt-3 mb-0">
          {{ saveMessage }}
        </div>

        <div v-if="editMode" class="mt-3 border rounded-4 p-3 bg-white">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Nombre</label>
              <input v-model="formCompanyName" class="form-control" type="text" />
            </div>
            <div class="col-md-6">
              <label class="form-label">Estado</label>
              <select v-model="formStatus" class="form-select">
                <option value="active">active</option>
                <option value="suspended">suspended</option>
                <option value="provisioning">provisioning</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">DB Host</label>
              <input v-model="formDbHost" class="form-control" type="text" />
            </div>
            <div class="col-md-2">
              <label class="form-label">DB Port</label>
              <input v-model.number="formDbPort" class="form-control" type="number" />
            </div>
            <div class="col-md-4">
              <label class="form-label">DB Name</label>
              <input v-model="formDbName" class="form-control" type="text" />
            </div>
            <div class="col-md-6">
              <label class="form-label">DB User</label>
              <input v-model="formDbUser" class="form-control" type="text" />
            </div>
            <div class="col-md-6">
              <label class="form-label">DB Pass (opcional)</label>
              <input v-model="formDbPass" class="form-control" type="password" />
            </div>
          </div>
          <div class="d-flex gap-2 mt-3">
            <button class="btn btn-dark rounded-pill" type="button" :disabled="busy" @click="saveTenant">
              Guardar
            </button>
            <button
              class="btn btn-outline-primary rounded-pill"
              type="button"
              :disabled="busy"
              @click="testDb"
            >
              Probar conexión
            </button>
          </div>
          <div v-if="testMessage" class="alert alert-secondary border-0 shadow-sm mt-3 mb-0">
            {{ testMessage }}
          </div>
        </div>

        <div class="mt-3 row g-3">
          <div class="col-md-6">
            <div class="border rounded-4 p-3 bg-white">
              <div class="text-secondary small mb-2">DB</div>
              <div class="small font-monospace">
                {{ tenant.dbHost ?? '—' }}:{{ tenant.dbPort ?? '—' }}
              </div>
              <div class="font-monospace">{{ tenant.dbName ?? '—' }}</div>
              <div class="small text-secondary font-monospace">{{ tenant.dbUser ?? '—' }}</div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="border rounded-4 p-3 bg-white">
              <div class="text-secondary small mb-2">Licencias</div>
              <div class="fw-semibold">{{ tenant.licenseCount ?? 0 }}</div>
              <div class="small text-secondary">
                Última: <code>{{ tenant.lastLicense ?? '—' }}</code>
              </div>
              <div v-if="(tenant.licenseCount ?? 0) === 0" class="d-flex flex-wrap gap-2 mt-3">
                <button class="btn btn-sm btn-dark rounded-pill" type="button" :disabled="busy" @click="generateLicense">
                  Generar licencia
                </button>
                <select v-if="availableLicenses.length" v-model="selectedLicense" class="form-select form-select-sm" style="max-width: 220px;">
                  <option value="">Seleccionar</option>
                  <option v-for="c in availableLicenses" :key="c" :value="c">{{ c }}</option>
                </select>
                <button
                  v-if="availableLicenses.length"
                  class="btn btn-sm btn-outline-primary rounded-pill"
                  type="button"
                  :disabled="busy || !selectedLicense"
                  @click="assignLicense"
                >
                  Asignar
                </button>
              </div>
              <div v-if="licenseMessage" class="alert alert-info border-0 shadow-sm mt-3 mb-0">
                {{ licenseMessage }}
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <h6 class="fw-semibold mb-3">Usuarios (master)</h6>
        <div v-if="userMessage" class="alert alert-info border-0 shadow-sm mb-3">
          {{ userMessage }}
        </div>
        <div v-if="syncMessage" class="alert alert-secondary border-0 shadow-sm mb-3">
          {{ syncMessage }}
        </div>
        <div v-if="repairMessage" class="alert alert-secondary border-0 shadow-sm mb-3">
          {{ repairMessage }}
        </div>
        <div class="d-flex justify-content-end mb-3">
          <button class="btn btn-sm btn-outline-primary rounded-pill me-2" type="button" :disabled="busy" @click="repairSchema">
            Repair schema
          </button>
          <button class="btn btn-sm btn-outline-dark rounded-pill" type="button" :disabled="busy" @click="syncUsers">
            Sync desde tenant
          </button>
        </div>
        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>ID</th>
                <th>Nombre</th>
                <th>Email</th>
                <th>Rol</th>
                <th>Activo</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="u in users" :key="u.id">
                <td>{{ u.id }}</td>
                <td>{{ u.name }}</td>
                <td>{{ u.email }}</td>
                <td>{{ u.role }}</td>
                <td>{{ u.active ? 'Sí' : 'No' }}</td>
                <td class="text-end">
                  <div class="d-flex gap-2 justify-content-end flex-wrap">
                    <button class="btn btn-sm btn-outline-dark rounded-pill" type="button" :disabled="busy" @click="openResetPassword(u)">
                      Reset clave
                    </button>
                    <button class="btn btn-sm btn-outline-primary rounded-pill" type="button" :disabled="busy" @click="openChangeEmail(u)">
                      Cambiar email
                    </button>
                    <button class="btn btn-sm btn-outline-danger rounded-pill" type="button" :disabled="busy" @click="openDeleteUser(u)">
                      Eliminar
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="users.length === 0">
                <td colspan="6" class="text-secondary">Sin datos</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <div v-else class="text-secondary">Cargando...</div>
</template>
