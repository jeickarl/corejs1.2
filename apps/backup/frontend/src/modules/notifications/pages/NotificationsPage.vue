<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useNotificationsStore } from '../../../store/notifications'

const store = useNotificationsStore()
const onlyUnread = ref(false)

const totalPages = computed(() => Math.max(1, Math.ceil(store.total / store.perPage)))

async function load() {
  await store.fetchPage({ onlyUnread: onlyUnread.value, page: store.page, perPage: store.perPage })
}

function go(p: number) {
  const safe = Math.min(Math.max(1, p), totalPages.value)
  void store.fetchPage({ onlyUnread: onlyUnread.value, page: safe, perPage: store.perPage })
}

function setOnlyUnread(v: boolean) {
  onlyUnread.value = v
  void store.fetchPage({ onlyUnread: v, page: 1, perPage: store.perPage })
}

async function markRead(id: number) {
  const res = await store.markRead(id)
  if (!res.ok) store.message = res.error.message
}

async function markAllRead() {
  const res = await store.markAllRead()
  if (!res.ok) store.message = res.error.message
}

onMounted(() => {
  onlyUnread.value = store.onlyUnread
  void load()
})
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
      <div>
        <h5 class="fw-semibold mb-1">Notificaciones</h5>
        <div class="text-secondary small">Alertas y eventos recientes</div>
      </div>
      <div class="d-flex gap-2 align-items-center flex-wrap justify-content-end">
        <div class="btn-group" role="group">
          <button
            class="btn btn-outline-dark rounded-start-pill"
            type="button"
            :class="{ active: !onlyUnread }"
            @click="setOnlyUnread(false)"
          >
            Todas
          </button>
          <button
            class="btn btn-outline-dark rounded-end-pill"
            type="button"
            :class="{ active: onlyUnread }"
            @click="setOnlyUnread(true)"
          >
            No leídas
            <span v-if="store.unreadCount > 0" class="badge text-bg-danger ms-2">{{ store.unreadCount }}</span>
          </button>
        </div>
        <button class="btn btn-outline-secondary rounded-pill" type="button" :disabled="store.loading" @click="load">
          Actualizar
        </button>
        <button
          class="btn btn-primary rounded-pill"
          type="button"
          :disabled="store.unreadCount === 0 || store.loading"
          @click="markAllRead"
        >
          Marcar todo leído
        </button>
      </div>
    </div>

    <div v-if="store.message" class="alert alert-warning border-0 shadow-sm mb-0">
      {{ store.message }}
    </div>

    <div class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <div v-if="store.loading" class="text-secondary small">Cargando...</div>

        <div v-else class="list-group">
          <div v-for="n in store.items" :key="n.id" class="list-group-item d-flex gap-3 align-items-start">
            <div class="pt-1">
              <span v-if="!n.isRead" class="badge rounded-pill text-bg-danger">Nuevo</span>
              <span v-else class="badge rounded-pill text-bg-secondary">Leído</span>
            </div>
            <div class="flex-grow-1">
              <div class="d-flex justify-content-between gap-2">
                <div class="fw-semibold">{{ n.title }}</div>
                <div class="text-secondary small">{{ n.createdAt }}</div>
              </div>
              <div v-if="n.body" class="text-secondary mt-1">{{ n.body }}</div>
              <div v-if="n.type" class="mt-2">
                <span class="badge rounded-pill text-bg-light border">{{ n.type }}</span>
              </div>
            </div>
            <div class="text-end">
              <button
                v-if="!n.isRead"
                class="btn btn-sm btn-outline-dark rounded-pill"
                type="button"
                @click="markRead(n.id)"
              >
                Marcar leído
              </button>
            </div>
          </div>
          <div v-if="store.items.length === 0" class="list-group-item text-secondary">Sin notificaciones</div>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-3">
          <div class="text-secondary small">Total: {{ store.total }}</div>
          <div class="d-flex gap-2 align-items-center">
            <button
              class="btn btn-sm btn-outline-secondary rounded-pill"
              type="button"
              :disabled="store.page <= 1 || store.loading"
              @click="go(store.page - 1)"
            >
              Anterior
            </button>
            <div class="small">Página {{ store.page }} / {{ totalPages }}</div>
            <button
              class="btn btn-sm btn-outline-secondary rounded-pill"
              type="button"
              :disabled="store.page >= totalPages || store.loading"
              @click="go(store.page + 1)"
            >
              Siguiente
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

