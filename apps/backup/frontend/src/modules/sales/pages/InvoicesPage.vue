<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { apiGet } from '../../../api/http'

type InvoiceListItem = {
  id: number
  invoiceNumber: string
  clientId: number
  clientName: string
  invoiceDate: string
  totalAmount: number
  paidAmount: number
  pendingAmount: number
  paymentStatus: 'pending' | 'partial' | 'paid'
  status: 'draft' | 'sent' | 'paid' | 'cancelled'
}

type PageDto = { items: InvoiceListItem[]; page: number; perPage: number; total: number }

const router = useRouter()
const search = ref('')
const status = ref('')
const paymentStatus = ref('')
const page = ref(1)
const perPage = ref(10)
const total = ref(0)
const items = ref<InvoiceListItem[]>([])
const message = ref('')

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))

function badgeForPayment(ps: InvoiceListItem['paymentStatus']) {
  if (ps === 'paid') return { text: 'Pagado', color: '#28a745' }
  if (ps === 'partial') return { text: 'Parcial', color: '#ffc107' }
  return { text: 'Pendiente', color: '#6c757d' }
}

function badgeForStatus(st: InvoiceListItem['status']) {
  if (st === 'cancelled') return { text: 'Anulada', color: '#dc3545' }
  if (st === 'paid') return { text: 'Cerrada', color: '#28a745' }
  if (st === 'sent') return { text: 'Emitida', color: '#0d6efd' }
  return { text: 'Borrador', color: '#6c757d' }
}

async function load() {
  message.value = ''
  const qs = new URLSearchParams({
    search: search.value,
    status: status.value,
    paymentStatus: paymentStatus.value,
    page: String(page.value),
    perPage: String(perPage.value),
  })
  const res = await apiGet<PageDto>(`/sales/invoices?${qs.toString()}`)
  if (!res.ok) {
    message.value = res.error.message
    items.value = []
    total.value = 0
    return
  }
  items.value = res.data.items
  total.value = res.data.total
}

function go(p: number) {
  page.value = Math.min(Math.max(1, p), totalPages.value)
  void load()
}

function open(id: number) {
  void router.push(`/sales/${id}`)
}

onMounted(load)
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
      <div>
        <h5 class="fw-semibold mb-1">Ventas</h5>
        <div class="text-secondary small">Facturas y pagos</div>
      </div>
      <div class="d-flex gap-2 align-items-center flex-wrap justify-content-end">
        <button class="btn btn-primary rounded-pill" type="button" @click="$router.push('/sales/new')">Nueva</button>
        <select v-model="status" class="form-select" style="max-width: 200px">
          <option value="">Estado: Todos</option>
          <option value="sent">Emitida</option>
          <option value="paid">Cerrada</option>
          <option value="cancelled">Anulada</option>
          <option value="draft">Borrador</option>
        </select>
        <select v-model="paymentStatus" class="form-select" style="max-width: 220px">
          <option value="">Pago: Todos</option>
          <option value="pending">Pago: Pendiente</option>
          <option value="partial">Pago: Parcial</option>
          <option value="paid">Pago: Pagado</option>
        </select>
        <input v-model="search" class="form-control" style="max-width: 320px" placeholder="Buscar..." />
        <button class="btn btn-dark rounded-pill" type="button" @click="go(1)">Buscar</button>
      </div>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-0">
      {{ message }}
    </div>

    <div class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>ID</th>
                <th>Factura</th>
                <th>Cliente</th>
                <th>Total</th>
                <th>Pagado</th>
                <th>Pendiente</th>
                <th>Pago</th>
                <th>Estado</th>
                <th>Fecha</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="i in items" :key="i.id">
                <td>{{ i.id }}</td>
                <td class="fw-semibold">{{ i.invoiceNumber || i.id }}</td>
                <td>{{ i.clientName }}</td>
                <td>{{ i.totalAmount }}</td>
                <td>{{ i.paidAmount }}</td>
                <td>{{ i.pendingAmount }}</td>
                <td>
                  <span class="badge rounded-pill" :style="{ backgroundColor: badgeForPayment(i.paymentStatus).color }">
                    {{ badgeForPayment(i.paymentStatus).text }}
                  </span>
                </td>
                <td>
                  <span class="badge rounded-pill" :style="{ backgroundColor: badgeForStatus(i.status).color }">
                    {{ badgeForStatus(i.status).text }}
                  </span>
                </td>
                <td>{{ i.invoiceDate }}</td>
                <td class="text-end">
                  <button class="btn btn-sm btn-outline-dark rounded-pill" type="button" @click="open(i.id)">Ver</button>
                </td>
              </tr>
              <tr v-if="items.length === 0">
                <td colspan="10" class="text-secondary">Sin datos</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-3">
          <div class="text-secondary small">Total: {{ total }}</div>
          <div class="d-flex gap-2 align-items-center">
            <button class="btn btn-sm btn-outline-secondary rounded-pill" type="button" :disabled="page <= 1" @click="go(page - 1)">
              Anterior
            </button>
            <div class="small">Página {{ page }} / {{ totalPages }}</div>
            <button class="btn btn-sm btn-outline-secondary rounded-pill" type="button" :disabled="page >= totalPages" @click="go(page + 1)">
              Siguiente
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

