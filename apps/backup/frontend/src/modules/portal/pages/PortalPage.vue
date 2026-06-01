<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { apiGet, apiPost } from '../../../api/http'

type PortalConfig = {
  enableLookupById: boolean
  showTimeline: boolean
  allowApproval: boolean
  homeTitle: string
  homeSubtitle: string
  whatsappLink: string
  addressText: string
  hoursText: string
  mapEmbedUrl: string
}

type PortalOrder = {
  id: number
  orderNumber: string
  clientId: number
  clientName: string
  deviceBrand: string
  deviceModel: string
  reportedIssue: string
  status: string
  approvalStatus: string
  estimatedCost: number
  verificationCode: string
  createdAt: string
}

type PortalHistoryItem = { id: number; status: string; createdAt: string }

type VerifyResult = {
  foundByCode: boolean
  order: PortalOrder
  config: PortalConfig
  history: PortalHistoryItem[]
  canApprove: boolean
}

const route = useRoute()
const tenantId = computed(() => String(route.params.tenantId ?? ''))

const config = ref<PortalConfig | null>(null)
const message = ref('')
const busy = ref(false)

const mode = ref<'order' | 'id'>('order')
const query = ref('')
const result = ref<VerifyResult | null>(null)

const approvalCode = ref('')
const approvalComment = ref('')

async function loadConfig() {
  const res = await apiGet<PortalConfig>(`/portal/${tenantId.value}/config`)
  config.value = res.ok ? res.data : null
}

async function verify() {
  if (busy.value) return
  message.value = ''
  result.value = null
  busy.value = true
  const res = await apiPost<VerifyResult, { mode: 'order' | 'id'; query: string }>(`/portal/${tenantId.value}/verify`, {
    mode: mode.value,
    query: query.value,
  })
  if (!res.ok) {
    message.value = res.error.message
    busy.value = false
    return
  }
  result.value = res.data
  approvalCode.value = ''
  approvalComment.value = ''
  busy.value = false
}

async function submit(decision: 'approve' | 'reject') {
  if (!result.value) return
  if (busy.value) return
  const code = approvalCode.value.trim()
  if (!code) return
  busy.value = true
  message.value = ''
  const res = await apiPost<{ done: true }, { verificationCode: string; decision: 'approve' | 'reject'; comment?: string }>(
    `/portal/${tenantId.value}/orders/${result.value.order.id}/approval`,
    {
      verificationCode: code,
      decision,
      comment: approvalComment.value.trim() || undefined,
    },
  )
  if (!res.ok) {
    message.value = res.error.message
    busy.value = false
    return
  }
  busy.value = false
  await verify()
}

onMounted(async () => {
  await loadConfig()
})
</script>

<template>
  <div class="container py-4" style="max-width: 980px">
    <div class="d-flex flex-column gap-2 mb-3">
      <h3 class="fw-semibold mb-0">{{ config?.homeTitle || 'Portal Cliente' }}</h3>
      <div class="text-secondary">{{ config?.homeSubtitle || 'Consulta el estado de tu orden' }}</div>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-3">{{ message }}</div>

    <div class="card shadow-soft border-0 rounded-custom mb-3">
      <div class="card-body">
        <div class="row g-2 align-items-end">
          <div class="col-md-3">
            <label class="form-label small text-secondary">Modo</label>
            <select v-model="mode" class="form-select">
              <option value="order">Orden o código</option>
              <option value="id" :disabled="!config?.enableLookupById">Documento</option>
            </select>
          </div>
          <div class="col-md-7">
            <label class="form-label small text-secondary">Buscar</label>
            <input v-model="query" class="form-control" :placeholder="mode === 'order' ? 'WO-123 o código' : 'NIT / Cédula / Teléfono'" />
          </div>
          <div class="col-md-2">
            <button class="btn btn-dark rounded-pill w-100" type="button" :disabled="busy || !query.trim()" @click="verify">
              Consultar
            </button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="result" class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
          <div>
            <div class="text-secondary small">Orden</div>
            <div class="fw-semibold">{{ result.order.orderNumber }}</div>
          </div>
          <div>
            <div class="text-secondary small">Estado</div>
            <div class="fw-semibold">{{ result.order.status }}</div>
          </div>
          <div>
            <div class="text-secondary small">Equipo</div>
            <div class="fw-semibold">{{ result.order.deviceBrand }} {{ result.order.deviceModel }}</div>
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-12">
            <div class="text-secondary small">Problema reportado</div>
            <div class="fw-semibold">{{ result.order.reportedIssue || '-' }}</div>
          </div>

          <div class="col-md-6" v-if="result.foundByCode">
            <div class="text-secondary small">Presupuesto</div>
            <div class="fw-semibold">{{ result.order.estimatedCost }}</div>
          </div>
          <div class="col-md-6" v-if="result.foundByCode">
            <div class="text-secondary small">Código</div>
            <div class="fw-semibold">{{ result.order.verificationCode }}</div>
          </div>
        </div>

        <div v-if="!result.foundByCode" class="alert alert-secondary border-0 mb-3">
          Para ver historial y presupuesto, ingresa tu código de verificación.
        </div>

        <div v-if="result.history.length > 0" class="mb-3">
          <div class="fw-semibold mb-2">Historial</div>
          <ul class="list-group">
            <li v-for="h in result.history" :key="h.id" class="list-group-item d-flex justify-content-between">
              <div>{{ h.status }}</div>
              <div class="text-secondary small">{{ h.createdAt }}</div>
            </li>
          </ul>
        </div>

        <div v-if="result.canApprove" class="card border-0 bg-light">
          <div class="card-body">
            <div class="fw-semibold mb-2">Aprobación</div>
            <div class="row g-2 align-items-end">
              <div class="col-md-4">
                <label class="form-label small text-secondary">Código</label>
                <input v-model="approvalCode" class="form-control" placeholder="Código de verificación" />
              </div>
              <div class="col-md-8">
                <label class="form-label small text-secondary">Comentario</label>
                <input v-model="approvalComment" class="form-control" placeholder="Opcional" />
              </div>
              <div class="col-12 d-flex gap-2 justify-content-end mt-2">
                <button class="btn btn-outline-danger rounded-pill px-4" type="button" :disabled="busy || !approvalCode.trim()" @click="submit('reject')">
                  Rechazar
                </button>
                <button class="btn btn-success rounded-pill px-4" type="button" :disabled="busy || !approvalCode.trim()" @click="submit('approve')">
                  Aprobar
                </button>
              </div>
            </div>
          </div>
        </div>

        <div v-if="config?.whatsappLink" class="mt-3">
          <a class="btn btn-outline-success rounded-pill" :href="config.whatsappLink" target="_blank" rel="noreferrer">WhatsApp</a>
        </div>
      </div>
    </div>
  </div>
</template>

