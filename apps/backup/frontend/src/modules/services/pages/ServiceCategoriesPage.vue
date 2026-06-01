<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { apiDelete, apiGet, apiPatch, apiPost } from '../../../api/http'

type CategoryDto = {
  id: number
  name: string
  description: string
  sortOrder: number
  active: boolean
  serviceCount: number
}

const message = ref('')
const busy = ref(false)
const items = ref<CategoryDto[]>([])

const showModal = ref(false)
const editing = ref<CategoryDto | null>(null)
const name = ref('')
const description = ref('')
const sortOrder = ref<number | null>(0)
const active = ref(true)

const sortedItems = computed(() => items.value.slice().sort((a, b) => a.sortOrder - b.sortOrder || a.name.localeCompare(b.name)))

function openCreate() {
  editing.value = null
  name.value = ''
  description.value = ''
  sortOrder.value = 0
  active.value = true
  showModal.value = true
}

function openEdit(c: CategoryDto) {
  editing.value = c
  name.value = c.name
  description.value = c.description
  sortOrder.value = c.sortOrder
  active.value = c.active
  showModal.value = true
}

function close() {
  showModal.value = false
}

async function load() {
  message.value = ''
  const res = await apiGet<{ items: CategoryDto[] }>('/services/categories')
  if (!res.ok) {
    message.value = res.error.message
    items.value = []
    return
  }
  items.value = res.data.items
}

async function save() {
  if (busy.value) return
  const nm = name.value.trim()
  if (!nm) return
  busy.value = true
  message.value = ''

  const body: Record<string, unknown> = {
    name: nm,
    description: description.value.trim() || undefined,
    sortOrder: Number(sortOrder.value ?? 0),
  }

  if (!editing.value) {
    const res = await apiPost<CategoryDto, Record<string, unknown>>('/services/categories', body)
    if (!res.ok) {
      message.value = res.error.message
      busy.value = false
      return
    }
  } else {
    const res = await apiPatch<{ done: true }, Record<string, unknown>>(`/services/categories/${editing.value.id}`, {
      ...body,
      active: Boolean(active.value),
    })
    if (!res.ok) {
      message.value = res.error.message
      busy.value = false
      return
    }
  }

  busy.value = false
  close()
  await load()
}

async function remove(c: CategoryDto) {
  const ok = window.confirm(`¿Eliminar categoría "${c.name}"?`)
  if (!ok) return
  busy.value = true
  message.value = ''
  const res = await apiDelete<{ done: true }>(`/services/categories/${c.id}`)
  if (!res.ok) {
    message.value = res.error.message
    busy.value = false
    return
  }
  busy.value = false
  await load()
}

onMounted(load)
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div>
        <h5 class="fw-semibold mb-1">Categorías de Servicios</h5>
        <div class="text-secondary small">device_categories</div>
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-light border rounded-pill px-4 fw-bold text-muted" type="button" @click="$router.push('/services')">
          Volver
        </button>
        <button class="btn btn-primary rounded-pill" type="button" @click="openCreate">Nueva</button>
      </div>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-0">{{ message }}</div>

    <div class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>Nombre</th>
                <th>Orden</th>
                <th>Servicios</th>
                <th>Estado</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in sortedItems" :key="c.id">
                <td class="fw-semibold">{{ c.name }}</td>
                <td>{{ c.sortOrder }}</td>
                <td>{{ c.serviceCount }}</td>
                <td>
                  <span v-if="c.active" class="badge bg-success">Activa</span>
                  <span v-else class="badge bg-secondary">Inactiva</span>
                </td>
                <td class="text-end">
                  <button class="btn btn-sm btn-outline-dark rounded-pill me-2" type="button" @click="openEdit(c)">Editar</button>
                  <button class="btn btn-sm btn-outline-danger rounded-pill" type="button" :disabled="c.serviceCount > 0" @click="remove(c)">
                    Eliminar
                  </button>
                </td>
              </tr>
              <tr v-if="sortedItems.length === 0">
                <td colspan="5" class="text-secondary">Sin datos</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div v-if="showModal" class="modal-backdrop fade show"></div>
    <div v-if="showModal" class="modal fade show d-block" tabindex="-1" role="dialog">
      <div class="modal-dialog">
        <div class="modal-content border-0 rounded-custom shadow">
          <div class="modal-header">
            <h5 class="modal-title">{{ editing ? 'Editar categoría' : 'Nueva categoría' }}</h5>
            <button type="button" class="btn-close" @click="close"></button>
          </div>
          <div class="modal-body">
            <div class="row g-3">
              <div class="col-md-12">
                <label class="form-label">Nombre</label>
                <input v-model="name" class="form-control" />
              </div>
              <div class="col-md-12">
                <label class="form-label">Descripción</label>
                <input v-model="description" class="form-control" />
              </div>
              <div class="col-md-6">
                <label class="form-label">Orden</label>
                <input v-model.number="sortOrder" class="form-control" type="number" min="0" step="1" />
              </div>
              <div class="col-md-6" v-if="editing">
                <div class="form-check mt-4">
                  <input id="cat-active" v-model="active" class="form-check-input" type="checkbox" />
                  <label class="form-check-label" for="cat-active">Activa</label>
                </div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-light border rounded-pill px-4 fw-bold text-muted" type="button" :disabled="busy" @click="close">
              Cancelar
            </button>
            <button class="btn btn-dark rounded-pill px-4" type="button" :disabled="busy || !name.trim()" @click="save">
              Guardar
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

