<script setup lang="ts">
import { computed, ref, watch } from 'vue'

type OrderStatus = { slug: string; name: string; emoji: string; color: string; sortOrder: number }

const props = defineProps<{
  open: boolean
  statuses: OrderStatus[]
  current: string
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'submit', status: string): void
}>()

const selected = ref('')

watch(
  () => props.open,
  (v) => {
    if (v) selected.value = props.current
  },
  { immediate: true },
)

const selectedStatus = computed(() => props.statuses.find((s) => s.slug === selected.value) || null)

function close() {
  emit('close')
}

function submit() {
  emit('submit', selected.value)
}
</script>

<template>
  <div v-if="open" class="position-fixed top-0 start-0 w-100 h-100" style="z-index: 1050;">
    <div class="position-absolute top-0 start-0 w-100 h-100 bg-dark" style="opacity: 0.5;" @click="close"></div>
    <div class="position-relative d-flex align-items-center justify-content-center h-100 p-3">
      <div class="bg-white rounded-4 shadow" style="width: 100%; max-width: 520px;">
        <div class="p-3 border-bottom d-flex align-items-center justify-content-between bg-dark text-white rounded-top-4">
          <div class="fw-semibold">Cambiar Estado</div>
          <button class="btn btn-sm btn-outline-light rounded-pill" type="button" @click="close">Cerrar</button>
        </div>
        <div class="p-3 bg-light">
          <label class="form-label small text-secondary fw-bold">Estado</label>
          <select v-model="selected" class="form-select rounded-pill">
            <option v-for="s in statuses" :key="s.slug" :value="s.slug">
              {{ (s.emoji ? s.emoji + ' ' : '') + s.name }}
            </option>
          </select>

          <div v-if="selectedStatus" class="mt-3 border rounded-4 p-3 bg-white">
            <div class="d-flex align-items-center justify-content-between">
              <div class="fw-semibold">
                {{ (selectedStatus.emoji ? selectedStatus.emoji + ' ' : '') + selectedStatus.name }}
              </div>
              <span class="badge rounded-pill" :style="{ backgroundColor: selectedStatus.color || '#6c757d' }">
                {{ selectedStatus.slug }}
              </span>
            </div>
          </div>
        </div>
        <div class="p-3 d-flex justify-content-end gap-2">
          <button class="btn btn-light border rounded-pill px-4 fw-bold text-muted" type="button" @click="close">
            Cancelar
          </button>
          <button class="btn btn-dark rounded-pill px-4 fw-bold" type="button" :disabled="!selected" @click="submit">
            Guardar
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

