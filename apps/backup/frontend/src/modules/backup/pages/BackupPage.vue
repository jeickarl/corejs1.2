<script setup lang="ts">
import { ref } from 'vue'
import { apiGet, apiPost } from '../../../api/http'

type BackupPayload = {
  kind: 'corejs-backup'
  version: 1
  createdAt: string
  tables: Record<string, Array<Record<string, unknown>>>
}

const message = ref('')
const busy = ref(false)
const mode = ref<'replace' | 'append'>('replace')
const file = ref<File | null>(null)

function onFile(e: Event) {
  const input = e.target as HTMLInputElement
  file.value = input.files?.[0] ?? null
}

function downloadJson(name: string, data: unknown) {
  const blob = new Blob([JSON.stringify(data)], { type: 'application/json;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = name
  a.click()
  URL.revokeObjectURL(url)
}

async function exportBackup() {
  if (busy.value) return
  busy.value = true
  message.value = ''
  const res = await apiGet<BackupPayload>('/backup/export')
  if (!res.ok) {
    message.value = res.error.message
    busy.value = false
    return
  }
  const ts = new Date().toISOString().replaceAll(':', '-')
  downloadJson(`backup-${ts}.json`, res.data)
  busy.value = false
}

async function importBackup() {
  if (busy.value) return
  if (!file.value) return
  busy.value = true
  message.value = ''
  try {
    const text = await file.value.text()
    const payload = JSON.parse(text) as BackupPayload
    const res = await apiPost<{ done: true }, { mode: 'replace' | 'append'; payload: BackupPayload }>('/backup/import', {
      mode: mode.value,
      payload,
    })
    if (!res.ok) {
      message.value = res.error.message
      busy.value = false
      return
    }
    message.value = 'Importación realizada.'
    busy.value = false
  } catch {
    message.value = 'Archivo inválido.'
    busy.value = false
  }
}
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <div>
      <h5 class="fw-semibold mb-1">Backups</h5>
      <div class="text-secondary small">Exportar / Importar datos del tenant</div>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-0">{{ message }}</div>

    <div class="card shadow-soft border-0 rounded-custom">
      <div class="card-body d-flex flex-column gap-3">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
          <div>
            <div class="fw-semibold">Exportar</div>
            <div class="text-secondary small">Descarga un JSON con todas las tablas</div>
          </div>
          <button class="btn btn-dark rounded-pill px-4" type="button" :disabled="busy" @click="exportBackup">Exportar</button>
        </div>

        <hr class="my-0" />

        <div class="d-flex flex-column gap-2">
          <div class="fw-semibold">Importar</div>
          <div class="text-secondary small">Cargar un JSON exportado anteriormente</div>

          <div class="row g-2 align-items-end">
            <div class="col-md-6">
              <label class="form-label">Archivo</label>
              <input class="form-control" type="file" accept="application/json" @change="(e) => onFile(e)" />
            </div>
            <div class="col-md-3">
              <label class="form-label">Modo</label>
              <select v-model="mode" class="form-select">
                <option value="replace">Reemplazar</option>
                <option value="append">Agregar</option>
              </select>
            </div>
            <div class="col-md-3 text-end">
              <button class="btn btn-danger rounded-pill px-4 w-100" type="button" :disabled="busy || !file" @click="importBackup">
                Importar
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
