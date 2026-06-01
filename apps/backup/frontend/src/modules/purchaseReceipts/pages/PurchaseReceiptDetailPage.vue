<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiGet } from '../../../api/http'

type ReceiptItemDto = {
  id: number
  productId: number
  productName: string
  quantity: number
  unitCost: number
  subtotal: number
}

type PurchaseReceiptDto = {
  id: number
  receiptNumber: string
  purchaseOrderId: number
  poNumber: string
  supplierName: string
  receivedDate: string
  notes: string
  totalAmount: number
  createdAt: string
  items: ReceiptItemDto[]
}

const route = useRoute()
const router = useRouter()
const id = computed(() => Number(route.params.id))

const loading = ref(false)
const message = ref('')
const receipt = ref<PurchaseReceiptDto | null>(null)

async function load() {
  loading.value = true
  message.value = ''
  const res = await apiGet<PurchaseReceiptDto>(`/purchase-receipts/${id.value}`)
  receipt.value = res.ok ? res.data : null
  if (!res.ok) message.value = res.error.message
  loading.value = false
}

onMounted(load)
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div>
        <h5 class="fw-semibold mb-1">Detalle Recepción</h5>
        <div v-if="receipt" class="text-secondary small">{{ receipt.receiptNumber }} · {{ receipt.poNumber }} · {{ receipt.supplierName }}</div>
      </div>
      <button class="btn btn-light border rounded-pill px-4 fw-bold text-muted" type="button" @click="router.push('/purchase-receipts')">
        Volver
      </button>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-0">{{ message }}</div>
    <div v-if="loading" class="text-secondary">Cargando...</div>

    <div v-if="receipt" class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <div class="text-secondary small">Recepción</div>
            <div class="fw-semibold">{{ receipt.receiptNumber }}</div>
          </div>
          <div class="col-md-4">
            <div class="text-secondary small">Orden</div>
            <div class="fw-semibold">{{ receipt.poNumber }}</div>
          </div>
          <div class="col-md-4">
            <div class="text-secondary small">Fecha</div>
            <div class="fw-semibold">{{ receipt.receivedDate }}</div>
          </div>
          <div class="col-md-8">
            <div class="text-secondary small">Proveedor</div>
            <div class="fw-semibold">{{ receipt.supplierName }}</div>
          </div>
          <div class="col-md-4">
            <div class="text-secondary small">Total</div>
            <div class="fw-semibold">{{ receipt.totalAmount }}</div>
          </div>
          <div class="col-md-12">
            <div class="text-secondary small">Notas</div>
            <div class="fw-semibold">{{ receipt.notes || '-' }}</div>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>Producto</th>
                <th style="width: 140px">Cantidad</th>
                <th style="width: 160px">Costo</th>
                <th style="width: 160px">Subtotal</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="it in receipt.items" :key="it.id">
                <td class="fw-semibold">{{ it.productName }}</td>
                <td>{{ it.quantity }}</td>
                <td>{{ it.unitCost }}</td>
                <td>{{ it.subtotal }}</td>
              </tr>
              <tr v-if="receipt.items.length === 0">
                <td colspan="4" class="text-secondary">Sin ítems</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

