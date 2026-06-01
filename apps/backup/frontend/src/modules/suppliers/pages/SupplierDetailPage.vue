<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiDelete, apiGet } from '../../../api/http'

type SupplierDto = {
  id: number
  supplierCode: string
  supplierType: string
  companyName: string
  contactName: string
  taxId: string
  phone: string
  mobile: string
  email: string
  website: string
  address: string
  city: string
  state: string
  country: string
  postalCode: string
  paymentTerms: string
  creditLimit: number | null
  discountPercentage: number | null
  bankName: string
  accountNumber: string
  accountType: string
  isActive: boolean
  rating: number | null
  notes: string
  createdAt: string
  updatedAt: string
}

const route = useRoute()
const router = useRouter()
const id = computed(() => Number(route.params.id))

const loading = ref(false)
const busy = ref(false)
const message = ref('')
const supplier = ref<SupplierDto | null>(null)

async function load() {
  loading.value = true
  message.value = ''
  const res = await apiGet<SupplierDto>(`/suppliers/${id.value}`)
  supplier.value = res.ok ? res.data : null
  if (!res.ok) message.value = res.error.message
  loading.value = false
}

async function remove() {
  if (!supplier.value) return
  const ok = window.confirm(`¿Desactivar proveedor "${supplier.value.companyName}"?`)
  if (!ok) return
  busy.value = true
  message.value = ''
  const res = await apiDelete<{ done: true }>(`/suppliers/${supplier.value.id}`)
  if (!res.ok) {
    message.value = res.error.message
    busy.value = false
    return
  }
  busy.value = false
  await router.push('/suppliers')
}

onMounted(load)
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div>
        <h5 class="fw-semibold mb-1">Detalle de Proveedor</h5>
        <div v-if="supplier" class="text-secondary small">{{ supplier.companyName }}</div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-light border rounded-pill px-4 fw-bold text-muted" type="button" @click="$router.push('/suppliers')">
          Volver
        </button>
        <button v-if="supplier" class="btn btn-outline-dark rounded-pill px-4 fw-bold" type="button" :disabled="busy" @click="$router.push(`/suppliers/${supplier.id}/edit`)">
          Editar
        </button>
        <button v-if="supplier" class="btn btn-outline-danger rounded-pill px-4 fw-bold" type="button" :disabled="busy" @click="remove">
          Desactivar
        </button>
      </div>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-0">{{ message }}</div>
    <div v-if="loading" class="text-secondary">Cargando...</div>

    <div v-if="supplier" class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <div class="text-secondary small">Empresa</div>
            <div class="fw-semibold">{{ supplier.companyName }}</div>
          </div>
          <div class="col-md-6">
            <div class="text-secondary small">Contacto</div>
            <div class="fw-semibold">{{ supplier.contactName || '-' }}</div>
          </div>
          <div class="col-md-4">
            <div class="text-secondary small">NIT</div>
            <div class="fw-semibold">{{ supplier.taxId || '-' }}</div>
          </div>
          <div class="col-md-4">
            <div class="text-secondary small">Teléfono</div>
            <div class="fw-semibold">{{ supplier.phone || '-' }}</div>
          </div>
          <div class="col-md-4">
            <div class="text-secondary small">Celular</div>
            <div class="fw-semibold">{{ supplier.mobile || '-' }}</div>
          </div>
          <div class="col-md-6">
            <div class="text-secondary small">Email</div>
            <div class="fw-semibold">{{ supplier.email || '-' }}</div>
          </div>
          <div class="col-md-6">
            <div class="text-secondary small">Ciudad</div>
            <div class="fw-semibold">{{ supplier.city || '-' }}</div>
          </div>
          <div class="col-md-12">
            <div class="text-secondary small">Dirección</div>
            <div class="fw-semibold">{{ supplier.address || '-' }}</div>
          </div>
          <div class="col-md-12">
            <div class="text-secondary small">Notas</div>
            <div class="fw-semibold">{{ supplier.notes || '-' }}</div>
          </div>
          <div class="col-md-6">
            <div class="text-secondary small">Creado</div>
            <div class="fw-semibold">{{ supplier.createdAt }}</div>
          </div>
          <div class="col-md-6">
            <div class="text-secondary small">Actualizado</div>
            <div class="fw-semibold">{{ supplier.updatedAt }}</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

