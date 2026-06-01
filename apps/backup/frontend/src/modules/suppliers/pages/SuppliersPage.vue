<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { apiGet } from '../../../api/http'

type SupplierDto = {
  id: number
  companyName: string
  contactName: string
  taxId: string
  phone: string
  email: string
  city: string
  isActive: boolean
  createdAt: string
}

type PageDto = { items: SupplierDto[]; page: number; perPage: number; total: number }

const search = ref('')
const page = ref(1)
const perPage = ref(10)
const total = ref(0)
const items = ref<SupplierDto[]>([])
const message = ref('')
const router = useRouter()

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))

async function load() {
  message.value = ''
  const qs = new URLSearchParams({
    search: search.value,
    page: String(page.value),
    perPage: String(perPage.value),
    onlyActive: '1',
  })
  const res = await apiGet<PageDto>(`/suppliers?${qs.toString()}`)
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
  void router.push(`/suppliers/${id}`)
}

onMounted(load)
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
      <div>
        <h5 class="fw-semibold mb-1">Proveedores</h5>
        <div class="text-secondary small">Listado de proveedores</div>
      </div>
      <div class="d-flex gap-2 align-items-center flex-wrap justify-content-end">
        <button class="btn btn-primary rounded-pill" type="button" @click="$router.push('/suppliers/new')">
          Nuevo
        </button>
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
                <th>Empresa</th>
                <th>Contacto</th>
                <th>Ciudad</th>
                <th>Email</th>
                <th>Teléfono</th>
                <th>NIT</th>
                <th>Creado</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in items" :key="s.id">
                <td>{{ s.id }}</td>
                <td class="fw-semibold">{{ s.companyName }}</td>
                <td>{{ s.contactName }}</td>
                <td>{{ s.city }}</td>
                <td>{{ s.email }}</td>
                <td>{{ s.phone }}</td>
                <td>{{ s.taxId }}</td>
                <td>{{ s.createdAt }}</td>
                <td class="text-end">
                  <span v-if="!s.isActive" class="badge bg-secondary">Inactivo</span>
                  <button v-else class="btn btn-sm btn-outline-dark rounded-pill" type="button" @click="open(s.id)">
                    Ver
                  </button>
                </td>
              </tr>
              <tr v-if="items.length === 0">
                <td colspan="9" class="text-secondary">Sin datos</td>
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
