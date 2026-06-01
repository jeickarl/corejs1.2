<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { apiGet } from '../../../api/http'

type TenantHealth = {
  id: number
  companyName: string
  status: string
  dbHost: string
  dbPort: number
  dbName: string
  dbUser: string
  ok: boolean
  error: string | null
}

const items = ref<TenantHealth[]>([])
const message = ref('')
const loading = ref(false)

async function load() {
  loading.value = true
  message.value = ''
  const res = await apiGet<TenantHealth[]>('/super-admin/health/tenants')
  loading.value = false
  if (!res.ok) {
    message.value = res.error.message
    items.value = []
    return
  }
  items.value = res.data
}

onMounted(load)
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <h5 class="fw-semibold mb-1">Health</h5>
        <div class="text-secondary small">Conexión a DB de tenants</div>
      </div>
      <button class="btn btn-dark rounded-pill" type="button" :disabled="loading" @click="load">Refrescar</button>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-0">{{ message }}</div>

    <div class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div v-if="loading" class="text-secondary small">Cargando...</div>
        <div v-else class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>ID</th>
                <th>Empresa</th>
                <th>Estado</th>
                <th>DB</th>
                <th>Usuario</th>
                <th>Conexión</th>
                <th>Error</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="t in items" :key="t.id">
                <td>{{ t.id }}</td>
                <td class="fw-semibold">{{ t.companyName }}</td>
                <td>{{ t.status }}</td>
                <td class="font-monospace small">{{ t.dbHost }}:{{ t.dbPort }} / {{ t.dbName }}</td>
                <td class="font-monospace small">{{ t.dbUser }}</td>
                <td>
                  <span v-if="t.ok" class="badge bg-success">OK</span>
                  <span v-else class="badge bg-danger">FAIL</span>
                </td>
                <td class="text-secondary small">{{ t.error ?? '' }}</td>
              </tr>
              <tr v-if="items.length === 0">
                <td colspan="7" class="text-secondary">Sin datos</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

