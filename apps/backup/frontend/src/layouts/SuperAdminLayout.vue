<script setup lang="ts">
import { onMounted } from 'vue'
import { useAuthStore } from '../store/auth'
import { useRouter } from 'vue-router'

const auth = useAuthStore()
const router = useRouter()
onMounted(() => auth.ensureLoaded())

async function logout() {
  await auth.logout()
  await router.replace('/login')
}
</script>

<template>
  <div class="d-flex" style="min-height: 100vh;">
    <aside class="bg-white border-end" style="width: 280px;">
      <div class="p-3 border-bottom d-flex align-items-center gap-2">
        <img src="/assets/img/system_logo.png" alt="CORE" style="height: 36px;" />
        <div class="fw-bold">CORE</div>
      </div>
      <div class="p-3">
        <div class="list-group">
          <router-link class="list-group-item list-group-item-action" to="/super-admin"
            >Dashboard Maestro</router-link
          >
          <router-link class="list-group-item list-group-item-action" to="/super-admin/health"
            >Health</router-link
          >
          <router-link class="list-group-item list-group-item-action" to="/super-admin/db-pool"
            >DB Pool</router-link
          >
          <router-link class="list-group-item list-group-item-action" to="/super-admin/repair-schema"
            >Repair Schema</router-link
          >
          <router-link class="list-group-item list-group-item-action" to="/super-admin/tenants"
            >Tenants</router-link
          >
        </div>
      </div>
    </aside>

    <main class="flex-grow-1 bg-light">
      <div class="p-3 border-bottom bg-white d-flex align-items-center justify-content-between">
        <div class="fw-semibold">Super Admin</div>
        <div class="d-flex align-items-center gap-2">
          <div class="text-secondary small">{{ auth.me?.email ?? '' }}</div>
          <button class="btn btn-sm btn-outline-dark rounded-pill" type="button" @click="logout">
            Salir
          </button>
        </div>
      </div>
      <div class="p-4">
        <router-view />
      </div>
    </main>
  </div>
</template>
