<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { apiGet, apiPatch, apiPost } from '../../../api/http'

type CashSession = {
  id: number
  status: 'open' | 'closed'
  openingDate: string
  closingDate: string | null
  openedBy: number | null
  closedBy: number | null
  initialAmount: number
  finalAmount: number | null
  systemTotal: number
  physicalCount: number | null
  difference: number | null
}

type CashSummary = { totalIncome: number; totalExpense: number; systemTotal: number }

type CashMovement = {
  id: number
  type: 'income' | 'expense'
  cashSessionId: number
  amount: number
  paymentMethod: string | null
  concept: string | null
  referenceNumber: string | null
  notes: string | null
  createdAt: string
  createdBy: number | null
}

const message = ref('')
const busy = ref(false)

const session = ref<CashSession | null>(null)
const summary = ref<CashSummary | null>(null)
const movements = ref<CashMovement[]>([])

const initialAmount = ref<number>(0)
const finalAmount = ref<number>(0)
const physicalCount = ref<number | null>(null)

const hasOpen = computed(() => Boolean(session.value && session.value.status === 'open'))

async function load() {
  message.value = ''
  const res = await apiGet<CashSession | null>('/cash/session')
  if (!res.ok) {
    message.value = res.error.message
    session.value = null
    summary.value = null
    movements.value = []
    return
  }
  session.value = res.data
  if (!res.data) {
    summary.value = null
    movements.value = []
    return
  }
  const sum = await apiGet<CashSummary>(`/cash/summary?cashSessionId=${res.data.id}`)
  summary.value = sum.ok ? sum.data : null
  const mov = await apiGet<CashMovement[]>(`/cash/movements?cashSessionId=${res.data.id}&limit=200`)
  movements.value = mov.ok ? mov.data : []
}

async function openCash() {
  if (busy.value) return
  busy.value = true
  message.value = ''
  const res = await apiPost<{ id: number }, { initialAmount: number }>('/cash/open', { initialAmount: initialAmount.value })
  if (!res.ok) {
    message.value = res.error.message
    busy.value = false
    return
  }
  busy.value = false
  await load()
}

async function closeCash() {
  if (busy.value) return
  if (!session.value) return
  const ok = window.confirm('¿Cerrar caja?')
  if (!ok) return
  busy.value = true
  message.value = ''
  const body: { finalAmount: number; physicalCount?: number | null } = { finalAmount: finalAmount.value }
  if (physicalCount.value !== null) body.physicalCount = physicalCount.value
  const res = await apiPatch<{ closed: true }, { finalAmount: number; physicalCount?: number | null }>('/cash/close', body)
  if (!res.ok) {
    message.value = res.error.message
    busy.value = false
    return
  }
  busy.value = false
  await load()
}

onMounted(load)
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
      <div>
        <h5 class="fw-semibold mb-1">Caja</h5>
        <div class="text-secondary small">Apertura, cierre y movimientos</div>
      </div>
      <div v-if="session" class="text-secondary small">
        Sesión #{{ session.id }} · {{ session.status === 'open' ? 'Abierta' : 'Cerrada' }}
      </div>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-0">{{ message }}</div>

    <div v-if="!hasOpen" class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <h6 class="fw-semibold mb-3">Abrir caja</h6>
        <div class="row g-3 align-items-end">
          <div class="col-md-4">
            <label class="form-label fw-medium">Monto inicial</label>
            <input v-model.number="initialAmount" class="form-control" type="number" min="0" step="0.01" />
          </div>
          <div class="col-md-8 d-flex justify-content-end">
            <button class="btn btn-dark rounded-pill px-4" type="button" :disabled="busy" @click="openCash">Abrir</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="hasOpen && session" class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <h6 class="fw-semibold mb-0">Resumen</h6>
          <div class="text-secondary small">Apertura: {{ session.openingDate }}</div>
        </div>

        <div class="row g-3 mt-1">
          <div class="col-md-3">
            <div class="text-secondary small">Inicial</div>
            <div class="fw-semibold">{{ session.initialAmount }}</div>
          </div>
          <div class="col-md-3">
            <div class="text-secondary small">Ingresos</div>
            <div class="fw-semibold">{{ summary?.totalIncome ?? 0 }}</div>
          </div>
          <div class="col-md-3">
            <div class="text-secondary small">Egresos</div>
            <div class="fw-semibold">{{ summary?.totalExpense ?? 0 }}</div>
          </div>
          <div class="col-md-3">
            <div class="text-secondary small">Total sistema</div>
            <div class="fw-bold">{{ summary?.systemTotal ?? 0 }}</div>
          </div>
        </div>

        <div class="border-top my-4"></div>

        <h6 class="fw-semibold mb-3">Cerrar caja</h6>
        <div class="row g-3 align-items-end">
          <div class="col-md-4">
            <label class="form-label fw-medium">Monto final</label>
            <input v-model.number="finalAmount" class="form-control" type="number" min="0" step="0.01" />
          </div>
          <div class="col-md-4">
            <label class="form-label fw-medium">Conteo físico (opcional)</label>
            <input v-model.number="physicalCount" class="form-control" type="number" min="0" step="0.01" />
          </div>
          <div class="col-md-4 d-flex justify-content-end">
            <button class="btn btn-danger rounded-pill px-4" type="button" :disabled="busy" @click="closeCash">Cerrar</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="hasOpen && session" class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
          <h6 class="fw-semibold mb-0">Movimientos</h6>
          <button class="btn btn-sm btn-outline-dark rounded-pill" type="button" :disabled="busy" @click="load">Actualizar</button>
        </div>
        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>ID</th>
                <th>Tipo</th>
                <th>Monto</th>
                <th>Método</th>
                <th>Concepto</th>
                <th>Referencia</th>
                <th>Fecha</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="m in movements" :key="`${m.type}-${m.id}`">
                <td>{{ m.id }}</td>
                <td>
                  <span class="badge rounded-pill" :class="m.type === 'income' ? 'text-bg-success' : 'text-bg-danger'">
                    {{ m.type === 'income' ? 'Ingreso' : 'Egreso' }}
                  </span>
                </td>
                <td class="fw-semibold">{{ m.amount }}</td>
                <td>{{ m.paymentMethod || '-' }}</td>
                <td>{{ m.concept || '-' }}</td>
                <td>{{ m.referenceNumber || '-' }}</td>
                <td>{{ m.createdAt }}</td>
              </tr>
              <tr v-if="movements.length === 0">
                <td colspan="7" class="text-secondary">Sin datos</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

