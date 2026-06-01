<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiGet, apiPatch } from '../../../api/http'

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

function opt(v: string): string | undefined {
  const t = v.trim()
  return t ? t : undefined
}

const route = useRoute()
const router = useRouter()
const id = computed(() => Number(route.params.id))

const loading = ref(false)
const saving = ref(false)
const message = ref('')

const companyName = ref('')
const contactName = ref('')
const taxId = ref('')
const phone = ref('')
const mobile = ref('')
const email = ref('')
const city = ref('')
const address = ref('')
const notes = ref('')
const isActive = ref(true)

async function load() {
  loading.value = true
  message.value = ''
  const res = await apiGet<SupplierDto>(`/suppliers/${id.value}`)
  if (!res.ok) {
    message.value = res.error.message
    loading.value = false
    return
  }
  companyName.value = res.data.companyName ?? ''
  contactName.value = res.data.contactName ?? ''
  taxId.value = res.data.taxId ?? ''
  phone.value = res.data.phone ?? ''
  mobile.value = res.data.mobile ?? ''
  email.value = res.data.email ?? ''
  city.value = res.data.city ?? ''
  address.value = res.data.address ?? ''
  notes.value = res.data.notes ?? ''
  isActive.value = Boolean(res.data.isActive)
  loading.value = false
}

async function save() {
  if (saving.value) return
  saving.value = true
  message.value = ''

  const body: Record<string, unknown> = {
    companyName: companyName.value.trim(),
    isActive: Boolean(isActive.value),
  }

  const cn = opt(contactName.value)
  if (cn) body.contactName = cn
  const tx = opt(taxId.value)
  if (tx) body.taxId = tx
  const ph = opt(phone.value)
  if (ph) body.phone = ph
  const mb = opt(mobile.value)
  if (mb) body.mobile = mb
  const em = opt(email.value)
  if (em) body.email = em
  const cy = opt(city.value)
  if (cy) body.city = cy
  const addr = opt(address.value)
  if (addr) body.address = addr
  const nt = opt(notes.value)
  if (nt) body.notes = nt

  const res = await apiPatch<{ done: true }, Record<string, unknown>>(`/suppliers/${id.value}`, body)
  if (!res.ok) {
    message.value = res.error.message
    saving.value = false
    return
  }
  await router.push(`/suppliers/${id.value}`)
}

onMounted(load)
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div>
        <h5 class="fw-semibold mb-1">Editar Proveedor</h5>
        <div class="text-secondary small">Actualiza información del proveedor</div>
      </div>
      <button class="btn btn-light border rounded-pill px-4 fw-bold text-muted" type="button" @click="$router.push(`/suppliers/${id}`)">
        Cancelar
      </button>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-0">
      {{ message }}
    </div>
    <div v-if="loading" class="text-secondary">Cargando...</div>

    <div class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-medium">Empresa / Razón social <span class="text-danger">*</span></label>
            <input v-model="companyName" class="form-control" />
          </div>
          <div class="col-md-6">
            <label class="form-label fw-medium">Contacto</label>
            <input v-model="contactName" class="form-control" />
          </div>
          <div class="col-md-4">
            <label class="form-label fw-medium">NIT</label>
            <input v-model="taxId" class="form-control" />
          </div>
          <div class="col-md-4">
            <label class="form-label fw-medium">Teléfono</label>
            <input v-model="phone" class="form-control" />
          </div>
          <div class="col-md-4">
            <label class="form-label fw-medium">Celular</label>
            <input v-model="mobile" class="form-control" />
          </div>
          <div class="col-md-6">
            <label class="form-label fw-medium">Email</label>
            <input v-model="email" class="form-control" />
          </div>
          <div class="col-md-6">
            <label class="form-label fw-medium">Ciudad</label>
            <input v-model="city" class="form-control" />
          </div>
          <div class="col-md-12">
            <label class="form-label fw-medium">Dirección</label>
            <input v-model="address" class="form-control" />
          </div>
          <div class="col-md-12">
            <label class="form-label fw-medium">Notas</label>
            <textarea v-model="notes" class="form-control" rows="3"></textarea>
          </div>
          <div class="col-md-12">
            <div class="form-check">
              <input id="sup-active" v-model="isActive" class="form-check-input" type="checkbox" />
              <label class="form-check-label" for="sup-active">Activo</label>
            </div>
          </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mt-3">
          <button class="btn btn-dark rounded-pill px-4" type="button" :disabled="saving" @click="save">
            Guardar
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

