<script setup lang="ts">
import { computed, ref } from 'vue'
import { apiPost } from '../../../api/http'

type RepairSchemaFail = { step: string; error: string }
type RepairSchemaTenantResult = {
  tenantId: number
  companyName: string
  status: string
  ok: number
  fail: number
  fails: RepairSchemaFail[]
}

const running = ref(false)
const message = ref('')
const results = ref<RepairSchemaTenantResult[]>([])

const totals = computed(() => {
  const tenants = results.value.length
  const failTenants = results.value.filter((r) => r.fail > 0).length
  const okSteps = results.value.reduce((acc, r) => acc + (Number(r.ok) || 0), 0)
  const failSteps = results.value.reduce((acc, r) => acc + (Number(r.fail) || 0), 0)
  return { tenants, failTenants, okSteps, failSteps }
})

function failsText(r: RepairSchemaTenantResult) {
  if (!r.fails?.length) return ''
  return r.fails.map((f) => `${f.step}: ${f.error}`).join(' | ')
}

async function runAll() {
  if (running.value) return
  const ok = window.confirm('¿Ejecutar repair schema para todos los tenants activos?')
  if (!ok) return
  running.value = true
  message.value = ''
  results.value = []
  const res = await apiPost<RepairSchemaTenantResult[], Record<string, never>>('/super-admin/repair-schema', {})
  running.value = false
  if (!res.ok) {
    message.value = res.error.message
    return
  }
  results.value = res.data
  message.value = `Completado. tenants=${totals.value.tenants} failTenants=${totals.value.failTenants} okSteps=${totals.value.okSteps} failSteps=${totals.value.failSteps}`
}
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <h5 class="fw-semibold mb-1">Repair Schema</h5>
        <div class="text-secondary small">Crea/asegura tablas y columnas mínimas en cada tenant (estado=active)</div>
      </div>
      <button class="btn btn-dark rounded-pill" type="button" :disabled="running" @click="runAll">Ejecutar</button>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-0">{{ message }}</div>

    <div class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div v-if="running" class="text-secondary small">Ejecutando...</div>
        <div v-else class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>Tenant</th>
                <th>Empresa</th>
                <th>Estado</th>
                <th>OK</th>
                <th>FAIL</th>
                <th>Detalle</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in results" :key="r.tenantId">
                <td>{{ r.tenantId }}</td>
                <td class="fw-semibold">{{ r.companyName }}</td>
                <td>{{ r.status }}</td>
                <td><span class="badge bg-success">{{ r.ok }}</span></td>
                <td>
                  <span v-if="r.fail === 0" class="badge bg-secondary">0</span>
                  <span v-else class="badge bg-danger">{{ r.fail }}</span>
                </td>
                <td class="small text-secondary text-truncate" style="max-width: 520px;">{{ failsText(r) }}</td>
              </tr>
              <tr v-if="results.length === 0">
                <td colspan="6" class="text-secondary">Sin datos</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>
