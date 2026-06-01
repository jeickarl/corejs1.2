<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { apiGet } from '../../../api/http'

type DeviceCategoryDto = { id: number; name: string; active: boolean }
type ServiceDto = {
  id: number
  name: string
  deviceCategoryId: number
  deviceCategoryName: string
  basePrice: number
  estimatedTime: number
  active: boolean
  createdAt: string
}

type ServicesPageDto = { items: ServiceDto[]; page: number; perPage: number; total: number }

const router = useRouter()
const message = ref('')

const search = ref('')
const categoryId = ref<number | null>(null)
const onlyActive = ref(true)

const page = ref(1)
const perPage = ref(10)
const total = ref(0)
const items = ref<ServiceDto[]>([])
const categories = ref<DeviceCategoryDto[]>([])

const categoryOptions = computed(() => categories.value.slice().sort((a, b) => a.name.localeCompare(b.name)))
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))

async function loadCategories() {
  const res = await apiGet<{ items: DeviceCategoryDto[] }>(`/services/categories?onlyActive=1`)
  categories.value = res.ok ? res.data.items : []
}

async function load() {
  message.value = ''
  const qs = new URLSearchParams({
    search: search.value,
    page: String(page.value),
    perPage: String(perPage.value),
    onlyActive: onlyActive.value ? '1' : '0',
  })
  if (categoryId.value) qs.set('categoryId', String(categoryId.value))
  const res = await apiGet<ServicesPageDto>(`/services?${qs.toString()}`)
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
  void router.push(`/services/${id}`)
}

function edit(id: number) {
  void router.push(`/services/${id}/edit`)
}

onMounted(async () => {
  await loadCategories()
  await load()
})
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
      <div>
        <h5 class="fw-semibold mb-1">Servicios</h5>
        <div class="text-secondary small">Catálogo de servicios</div>
      </div>
      <div class="d-flex gap-2 align-items-center flex-wrap justify-content-end">
        <button class="btn btn-outline-secondary rounded-pill" type="button" @click="$router.push('/services/categories')">
          Categorías
        </button>
        <button class="btn btn-primary rounded-pill" type="button" @click="$router.push('/services/new')">Nuevo</button>
      </div>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-0">{{ message }}</div>

    <div class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div class="row g-2 align-items-end mb-3">
          <div class="col-md-4">
            <label class="form-label small text-secondary">Buscar</label>
            <input v-model="search" class="form-control" placeholder="Nombre / descripción" />
          </div>
          <div class="col-md-4">
            <label class="form-label small text-secondary">Categoría</label>
            <select v-model="categoryId" class="form-select">
              <option :value="null">Todas</option>
              <option v-for="c in categoryOptions" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>
          <div class="col-md-2">
            <div class="form-check mt-4">
              <input id="svc-only-active" v-model="onlyActive" class="form-check-input" type="checkbox" />
              <label class="form-check-label" for="svc-only-active">Solo activos</label>
            </div>
          </div>
          <div class="col-md-2 text-end">
            <button class="btn btn-dark rounded-pill w-100" type="button" @click="go(1)">Buscar</button>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>Servicio</th>
                <th>Categoría</th>
                <th>Precio</th>
                <th>Tiempo</th>
                <th>Creado</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in items" :key="s.id">
                <td class="fw-semibold">{{ s.name }}</td>
                <td>{{ s.deviceCategoryName }}</td>
                <td>{{ s.basePrice }}</td>
                <td>{{ s.estimatedTime }}</td>
                <td>{{ s.createdAt }}</td>
                <td class="text-end">
                  <span v-if="!s.active" class="badge bg-secondary me-2">Inactivo</span>
                  <button class="btn btn-sm btn-outline-dark rounded-pill me-2" type="button" @click="open(s.id)">Ver</button>
                  <button class="btn btn-sm btn-dark rounded-pill" type="button" @click="edit(s.id)">Editar</button>
                </td>
              </tr>
              <tr v-if="items.length === 0">
                <td colspan="6" class="text-secondary">Sin datos</td>
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

