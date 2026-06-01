<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiGet, apiPatch } from '../../../api/http'

type Client = {
  id: number
  clientType: string
  firstName: string
  companyName: string
  taxId: string
  legalRepresentative: string
  phone: string
  email: string
  idNumber: string
  address: string
  notes: string
  clientNumber: number | null
  createdAt: string
}

function opt(v: string): string | undefined {
  const t = v.trim()
  return t ? t : undefined
}

function digitsOnly(v: string): string {
  return v.replace(/[^0-9]/g, '')
}

const route = useRoute()
const router = useRouter()
const id = computed(() => Number(route.params.id))

const loading = ref(false)
const saving = ref(false)
const message = ref('')

const clientType = ref<'individual' | 'company'>('individual')
const name = ref('')
const idNumber = ref('')
const companyName = ref('')
const taxId = ref('')
const legalRepresentative = ref('')
const phonePrefix = ref('+57')
const phoneNumber = ref('')
const email = ref('')
const address = ref('')
const notes = ref('')

const isCompany = computed(() => clientType.value === 'company')

function fillFromClient(c: Client) {
  clientType.value = (c.clientType === 'company' ? 'company' : 'individual') as 'individual' | 'company'
  name.value = c.firstName ?? ''
  idNumber.value = c.idNumber ?? ''
  companyName.value = c.companyName ?? ''
  taxId.value = c.taxId ?? ''
  legalRepresentative.value = c.legalRepresentative ?? ''
  email.value = c.email ?? ''
  address.value = c.address ?? ''
  notes.value = c.notes ?? ''

  const rawPhone = String(c.phone ?? '').trim()
  const parts = rawPhone.split(/\s+/).filter(Boolean)
  if (parts[0] && parts[0].startsWith('+')) {
    phonePrefix.value = parts[0]
    phoneNumber.value = digitsOnly(parts.slice(1).join(' '))
  } else {
    phonePrefix.value = '+57'
    phoneNumber.value = digitsOnly(rawPhone)
  }
}

async function load() {
  loading.value = true
  message.value = ''
  const res = await apiGet<Client>(`/clients/${id.value}`)
  if (!res.ok) {
    message.value = res.error.message
    loading.value = false
    return
  }
  fillFromClient(res.data)
  loading.value = false
}

async function save() {
  if (saving.value) return
  saving.value = true
  message.value = ''
  const digits = phoneNumber.value.replace(/[^0-9]/g, '')
  const phone = `${phonePrefix.value.trim()} ${digits}`.trim()

  const body: Record<string, unknown> = { clientType: clientType.value, phone }
  if (isCompany.value) {
    body.companyName = opt(companyName.value)
    body.taxId = opt(taxId.value)
    body.legalRepresentative = opt(legalRepresentative.value)
  } else {
    body.name = opt(name.value)
    body.idNumber = opt(idNumber.value)
  }

  const em = opt(email.value)
  if (em) body.email = em
  const addr = opt(address.value)
  if (addr) body.address = addr
  const nt = opt(notes.value)
  if (nt) body.notes = nt

  const res = await apiPatch<Client, Record<string, unknown>>(`/clients/${id.value}`, body)
  if (!res.ok) {
    message.value = res.error.message
    saving.value = false
    return
  }
  await router.push(`/clients/${res.data.id}`)
}

watch(clientType, (t) => {
  if (t === 'individual') {
    companyName.value = ''
    taxId.value = ''
    legalRepresentative.value = ''
  } else {
    name.value = ''
  }
})

onMounted(load)
</script>

<template>
  <div class="d-flex flex-column">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
      <div>
        <nav aria-label="breadcrumb">
          <ol class="breadcrumb mb-2">
            <li class="breadcrumb-item">
              <a class="text-decoration-none" href="#" @click.prevent="router.push('/clients')">Clientes</a>
            </li>
            <li class="breadcrumb-item">
              <a class="text-decoration-none" href="#" @click.prevent="router.push(`/clients/${id}`)">Detalle</a>
            </li>
            <li class="breadcrumb-item active" aria-current="page">Editar</li>
          </ol>
        </nav>
        <h2 class="fw-bold text-dark mb-1"><i class="fas fa-user-edit me-2 text-primary no-theme"></i>Editar Cliente</h2>
        <p class="text-muted mb-0">Actualiza la información del cliente</p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-light border rounded-pill px-4 fw-bold text-muted" type="button" @click="router.push(`/clients/${id}`)">Cancelar</button>
        <button class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm" type="button" :disabled="saving || loading" @click="save">
          Guardar
        </button>
      </div>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-0">
      {{ message }}
    </div>

    <div v-if="loading" class="text-secondary">Cargando...</div>

    <div v-if="!loading" class="card shadow-sm rounded-4 border-0 overflow-hidden">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-3">
          <h6 class="fw-bold mb-0"><i class="fas fa-id-badge me-2 text-primary no-theme"></i>Tipo de Cliente</h6>
        </div>

        <div class="d-flex gap-4 flex-wrap">
          <div class="form-check">
            <input id="ct-individual" v-model="clientType" class="form-check-input" type="radio" value="individual" />
            <label class="form-check-label cursor-pointer" for="ct-individual"><i class="fas fa-user me-2"></i>Persona Natural</label>
          </div>
          <div class="form-check">
            <input id="ct-company" v-model="clientType" class="form-check-input" type="radio" value="company" />
            <label class="form-check-label cursor-pointer" for="ct-company"><i class="fas fa-building me-2"></i>Empresa</label>
          </div>
        </div>

        <div class="border-top my-4"></div>

        <div class="d-flex align-items-center justify-content-between mb-3">
          <h6 class="fw-bold mb-0"><i class="fas fa-info-circle me-2 text-primary no-theme"></i>Información</h6>
        </div>

        <div v-if="!isCompany" class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-medium">Nombre Completo <span class="text-danger">*</span></label>
            <div class="input-group">
              <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3">
                <i class="fas fa-user"></i>
              </span>
              <input v-model="name" class="form-control bg-light border-start-0 rounded-end-pill px-3" maxlength="200" placeholder="Ej. Juan Pérez" />
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-medium">Identificación (Cédula/DNI)</label>
            <div class="input-group">
              <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3">
                <i class="fas fa-id-card"></i>
              </span>
              <input v-model="idNumber" class="form-control bg-light border-start-0 rounded-end-pill px-3" maxlength="20" placeholder="Ej. 1234567890" />
            </div>
          </div>
        </div>

        <div v-else class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-medium">Razón Social <span class="text-danger">*</span></label>
            <div class="input-group">
              <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3">
                <i class="fas fa-building"></i>
              </span>
              <input v-model="companyName" class="form-control bg-light border-start-0 rounded-end-pill px-3" maxlength="200" placeholder="Ej. Soluciones S.A.S." />
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-medium">NIT/RUC <span class="text-danger">*</span></label>
            <div class="input-group">
              <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3">
                <i class="fas fa-fingerprint"></i>
              </span>
              <input v-model="taxId" class="form-control bg-light border-start-0 rounded-end-pill px-3" maxlength="20" placeholder="Ej. 900.123.456-7" />
            </div>
          </div>
          <div class="col-md-12">
            <label class="form-label fw-medium">Representante Legal</label>
            <div class="input-group">
              <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3">
                <i class="fas fa-user-tie"></i>
              </span>
              <input v-model="legalRepresentative" class="form-control bg-light border-start-0 rounded-end-pill px-3" maxlength="100" placeholder="Nombre del representante legal" />
            </div>
          </div>
        </div>

        <div class="border-top my-4"></div>

        <div class="d-flex align-items-center justify-content-between mb-3">
          <h6 class="fw-bold mb-0"><i class="fas fa-address-book me-2 text-primary no-theme"></i>Contacto</h6>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <label class="form-label fw-medium">Teléfono Móvil <span class="text-danger">*</span></label>
            <div class="input-group">
              <span class="input-group-text bg-light border-end-0 text-muted rounded-start-pill px-3">
                <i class="fas fa-phone"></i>
              </span>
              <input v-model="phonePrefix" class="form-control border-start-0 text-center bg-light text-muted" style="max-width: 90px" />
              <input v-model="phoneNumber" class="form-control bg-light border-start-0 rounded-end-pill px-3" maxlength="20" placeholder="300 123 4567" />
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-medium">Correo Electrónico</label>
            <div class="input-group">
              <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3">
                <i class="fas fa-envelope"></i>
              </span>
              <input v-model="email" class="form-control bg-light border-start-0 rounded-end-pill px-3" maxlength="100" placeholder="cliente@ejemplo.com" />
            </div>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-medium">Dirección</label>
          <div class="input-group">
            <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3">
              <i class="fas fa-map-marker-alt"></i>
            </span>
            <textarea v-model="address" class="form-control bg-light border-start-0 rounded-end-pill px-3 py-2" rows="2" maxlength="255" placeholder="Dirección completa del cliente"></textarea>
          </div>
        </div>

        <div class="mb-0">
          <label class="form-label fw-medium">Notas</label>
          <textarea v-model="notes" class="form-control bg-light rounded-pill px-3 py-2" rows="2" maxlength="1000" placeholder="Notas internas"></textarea>
        </div>
      </div>
    </div>
  </div>
</template>
