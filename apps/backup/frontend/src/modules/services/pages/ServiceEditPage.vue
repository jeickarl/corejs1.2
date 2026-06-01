<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiGet, apiPatch } from '../../../api/http'

type DeviceCategoryDto = { id: number; name: string }
type ServiceDto = {
  id: number
  name: string
  description: string
  deviceCategoryId: number
  basePrice: number
  estimatedTime: number
  notes: string
  active: boolean
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

const categories = ref<DeviceCategoryDto[]>([])
const categoryId = ref<number | null>(null)
const name = ref('')
const description = ref('')
const basePrice = ref<number | null>(0)
const estimatedTime = ref<number | null>(0)
const notes = ref('')
const active = ref(true)

async function loadCategories() {
  const res = await apiGet<{ items: DeviceCategoryDto[] }>('/services/categories?onlyActive=1')
  categories.value = res.ok ? res.data.items : []
}

async function load() {
  loading.value = true
  message.value = ''
  const res = await apiGet<ServiceDto>(`/services/${id.value}`)
  if (!res.ok) {
    message.value = res.error.message
    loading.value = false
    return
  }
  name.value = res.data.name ?? ''
  description.value = res.data.description ?? ''
  categoryId.value = res.data.deviceCategoryId ?? null
  basePrice.value = res.data.basePrice ?? 0
  estimatedTime.value = res.data.estimatedTime ?? 0
  notes.value = res.data.notes ?? ''
  active.value = Boolean(res.data.active)
  loading.value = false
}

async function save() {
  if (saving.value) return
  if (!categoryId.value) return
  saving.value = true
  message.value = ''

  const body: Record<string, unknown> = {
    name: name.value.trim(),
    deviceCategoryId: categoryId.value,
    active: Boolean(active.value),
  }
  const d = opt(description.value)
  if (d) body.description = d
  const n = opt(notes.value)
  if (n) body.notes = n
  const bp = Number(basePrice.value ?? 0)
  if (Number.isFinite(bp)) body.basePrice = bp
  const et = Number(estimatedTime.value ?? 0)
  if (Number.isFinite(et)) body.estimatedTime = et

  const res = await apiPatch<{ done: true }, Record<string, unknown>>(`/services/${id.value}`, body)
  if (!res.ok) {
    message.value = res.error.message
    saving.value = false
    return
  }
  await router.push(`/services/${id.value}`)
}

onMounted(async () => {
  await loadCategories()
  await load()
})
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div>
        <h5 class="fw-semibold mb-1">Editar Servicio</h5>
        <div class="text-secondary small">Actualiza información</div>
      </div>
      <button class="btn btn-light border rounded-pill px-4 fw-bold text-muted" type="button" @click="$router.push(`/services/${id}`)">Cancelar</button>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-0">{{ message }}</div>
    <div v-if="loading" class="text-secondary">Cargando...</div>

    <div class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-medium">Nombre <span class="text-danger">*</span></label>
            <input v-model="name" class="form-control" />
          </div>
          <div class="col-md-6">
            <label class="form-label fw-medium">Categoría <span class="text-danger">*</span></label>
            <select v-model="categoryId" class="form-select">
              <option :value="null">Seleccionar</option>
              <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label fw-medium">Precio base</label>
            <input v-model.number="basePrice" class="form-control" type="number" min="0" step="0.01" />
          </div>
          <div class="col-md-3">
            <label class="form-label fw-medium">Tiempo estimado</label>
            <input v-model.number="estimatedTime" class="form-control" type="number" min="0" step="1" />
          </div>
          <div class="col-md-6">
            <label class="form-label fw-medium">Descripción</label>
            <input v-model="description" class="form-control" />
          </div>
          <div class="col-md-12">
            <label class="form-label fw-medium">Notas</label>
            <textarea v-model="notes" class="form-control" rows="3"></textarea>
          </div>
          <div class="col-md-12">
            <div class="form-check">
              <input id="svc-active" v-model="active" class="form-check-input" type="checkbox" />
              <label class="form-check-label" for="svc-active">Activo</label>
            </div>
          </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mt-3">
          <button class="btn btn-dark rounded-pill px-4" type="button" :disabled="saving || !categoryId || !name.trim()" @click="save">
            Guardar
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

