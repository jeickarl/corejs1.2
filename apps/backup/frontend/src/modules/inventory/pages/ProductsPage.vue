<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { apiGet } from '../../../api/http'

type Product = {
  id: number
  sku: string
  name: string
  description: string
  salePrice: number
  costPrice: number
  currentStock: number
  minStock: number
  isActive: boolean
  createdAt: string
  updatedAt: string
}

type PageDto = { items: Product[]; page: number; perPage: number; total: number }

const router = useRouter()

const search = ref('')
const page = ref(1)
const perPage = ref(10)
const total = ref(0)
const items = ref<Product[]>([])
const message = ref('')

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))

async function load() {
  message.value = ''
  const qs = new URLSearchParams({
    search: search.value,
    onlyActive: '0',
    page: String(page.value),
    perPage: String(perPage.value),
  })
  const res = await apiGet<PageDto>(`/inventory/products?${qs.toString()}`)
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
  void router.push(`/inventory/products/${id}`)
}

function edit(id: number) {
  void router.push(`/inventory/products/${id}/edit`)
}

onMounted(load)
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
      <div>
        <h5 class="fw-semibold mb-1">Inventario</h5>
        <div class="text-secondary small">Productos y stock</div>
      </div>
      <div class="d-flex gap-2 align-items-center flex-wrap justify-content-end">
        <button class="btn btn-primary rounded-pill" type="button" @click="$router.push('/inventory/products/new')">Nuevo</button>
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
                <th>SKU</th>
                <th>Nombre</th>
                <th style="width: 110px;">Stock</th>
                <th style="width: 110px;">Mín</th>
                <th style="width: 140px;">Precio</th>
                <th style="width: 110px;">Activo</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in items" :key="p.id">
                <td>{{ p.id }}</td>
                <td>{{ p.sku }}</td>
                <td class="fw-semibold">{{ p.name }}</td>
                <td>
                  <span class="badge rounded-pill" :class="p.currentStock <= p.minStock ? 'text-bg-danger' : 'text-bg-success'">
                    {{ p.currentStock }}
                  </span>
                </td>
                <td>{{ p.minStock }}</td>
                <td>{{ p.salePrice }}</td>
                <td>{{ p.isActive ? 'Sí' : 'No' }}</td>
                <td class="text-end">
                  <div class="d-flex justify-content-end gap-2">
                    <button class="btn btn-sm btn-outline-dark rounded-pill" type="button" @click="open(p.id)">Ver</button>
                    <button class="btn btn-sm btn-outline-secondary rounded-pill" type="button" @click="edit(p.id)">Editar</button>
                  </div>
                </td>
              </tr>
              <tr v-if="items.length === 0">
                <td colspan="8" class="text-secondary">Sin datos</td>
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

