<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiGet } from '../../../api/http'

type ServiceDto = {
  id: number
  name: string
  description: string
  deviceCategoryId: number
  deviceCategoryName: string
  basePrice: number
  estimatedTime: number
  notes: string
  active: boolean
  createdAt: string
  updatedAt: string
}

const route = useRoute()
const router = useRouter()
const id = computed(() => Number(route.params.id))

const loading = ref(false)
const message = ref('')
const service = ref<ServiceDto | null>(null)

async function load() {
  loading.value = true
  message.value = ''
  const res = await apiGet<ServiceDto>(`/services/${id.value}`)
  service.value = res.ok ? res.data : null
  if (!res.ok) message.value = res.error.message
  loading.value = false
}

onMounted(load)
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div>
        <h5 class="fw-semibold mb-1">Detalle Servicio</h5>
        <div v-if="service" class="text-secondary small">{{ service.name }}</div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-light border rounded-pill px-4 fw-bold text-muted" type="button" @click="router.push('/services')">
          Volver
        </button>
        <button v-if="service" class="btn btn-dark rounded-pill px-4 fw-bold" type="button" @click="router.push(`/services/${service.id}/edit`)">
          Editar
        </button>
      </div>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-0">{{ message }}</div>
    <div v-if="loading" class="text-secondary">Cargando...</div>

    <div v-if="service" class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <div class="text-secondary small">Nombre</div>
            <div class="fw-semibold">{{ service.name }}</div>
          </div>
          <div class="col-md-6">
            <div class="text-secondary small">Categoría</div>
            <div class="fw-semibold">{{ service.deviceCategoryName }}</div>
          </div>
          <div class="col-md-3">
            <div class="text-secondary small">Precio</div>
            <div class="fw-semibold">{{ service.basePrice }}</div>
          </div>
          <div class="col-md-3">
            <div class="text-secondary small">Tiempo</div>
            <div class="fw-semibold">{{ service.estimatedTime }}</div>
          </div>
          <div class="col-md-3">
            <div class="text-secondary small">Estado</div>
            <div class="fw-semibold">{{ service.active ? 'Activo' : 'Inactivo' }}</div>
          </div>
          <div class="col-md-12">
            <div class="text-secondary small">Descripción</div>
            <div class="fw-semibold">{{ service.description || '-' }}</div>
          </div>
          <div class="col-md-12">
            <div class="text-secondary small">Notas</div>
            <div class="fw-semibold">{{ service.notes || '-' }}</div>
          </div>
          <div class="col-md-6">
            <div class="text-secondary small">Creado</div>
            <div class="fw-semibold">{{ service.createdAt }}</div>
          </div>
          <div class="col-md-6">
            <div class="text-secondary small">Actualizado</div>
            <div class="fw-semibold">{{ service.updatedAt }}</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

