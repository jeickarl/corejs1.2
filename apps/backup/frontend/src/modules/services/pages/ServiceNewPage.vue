<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { apiGet, apiPost } from '../../../api/http'

type DeviceCategoryDto = { id: number; name: string }
type ServiceDto = { id: number }

function opt(v: string): string | undefined {
  const t = v.trim()
  return t ? t : undefined
}

const router = useRouter()
const message = ref('')
const saving = ref(false)

const categories = ref<DeviceCategoryDto[]>([])
const categoryId = ref<number | null>(null)
const name = ref('')
const description = ref('')
const basePrice = ref<number | null>(0)
const estimatedTime = ref<number | null>(0)
const notes = ref('')

const categoryOptions = computed(() => categories.value.slice().sort((a, b) => a.name.localeCompare(b.name)))

async function loadCategories() {
  const res = await apiGet<{ items: DeviceCategoryDto[] }>('/services/categories?onlyActive=1')
  categories.value = res.ok ? res.data.items : []
}

async function save() {
  if (saving.value) return
  if (!categoryId.value) return
  saving.value = true
  message.value = ''

  const body: Record<string, unknown> = {
    name: name.value.trim(),
    deviceCategoryId: categoryId.value,
  }
  const d = opt(description.value)
  if (d) body.description = d
  const n = opt(notes.value)
  if (n) body.notes = n
  const bp = Number(basePrice.value ?? 0)
  if (Number.isFinite(bp)) body.basePrice = bp
  const et = Number(estimatedTime.value ?? 0)
  if (Number.isFinite(et)) body.estimatedTime = et

  const res = await apiPost<ServiceDto, Record<string, unknown>>('/services', body)
  if (!res.ok) {
    message.value = res.error.message
    saving.value = false
    return
  }
  await router.push(`/services/${res.data.id}`)
}

onMounted(loadCategories)
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div>
        <h5 class="fw-semibold mb-1">Nuevo Servicio</h5>
        <div class="text-secondary small">Crear servicio</div>
      </div>
      <button class="btn btn-light border rounded-pill px-4 fw-bold text-muted" type="button" @click="$router.push('/services')">Cancelar</button>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-0">{{ message }}</div>

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
              <option v-for="c in categoryOptions" :key="c.id" :value="c.id">{{ c.name }}</option>
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

