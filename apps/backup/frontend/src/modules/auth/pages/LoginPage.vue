<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../../../store/auth'

const router = useRouter()
const auth = useAuthStore()

const email = ref('')
const password = ref('')
const error = ref('')

async function onSubmit() {
  error.value = ''
  const res = await auth.login(email.value, password.value)
  if (!res.ok) {
    error.value = res.error.message
    return
  }
  if (res.data.role === 'SUPER_ADMIN') {
    await router.replace('/super-admin')
    return
  }
  await router.replace('/')
}
</script>

<template>
  <div class="container d-flex align-items-center justify-content-center" style="min-height: 100vh;">
    <div class="card shadow-lg border-0 rounded-4 w-100" style="max-width: 400px;">
      <div class="card-body p-4 p-md-5 text-center">
        <div class="d-flex align-items-center justify-content-center mb-4">
          <img src="/assets/img/system_logo.png" alt="CORE Logo" height="50" class="me-2" />
          <h1 class="fw-bold mb-0 text-dark" style="font-size: 2rem; letter-spacing: -1px;">CORE</h1>
        </div>
        <h4 class="mb-4 text-secondary fw-semibold">Iniciar Sesión</h4>
        <div v-if="error" class="alert alert-danger p-2 small mb-4" role="alert">
          {{ error }}
        </div>
        <form @submit.prevent="onSubmit">
          <div class="mb-3 text-start">
            <label class="form-label">Usuario o Email</label>
            <input v-model="email" class="form-control" type="text" autocomplete="username" />
          </div>
          <div class="mb-3 text-start">
            <label class="form-label">Contraseña</label>
            <input
              v-model="password"
              class="form-control"
              type="password"
              autocomplete="current-password"
            />
          </div>
          <button class="btn btn-dark w-100 rounded-btn" type="submit">Entrar</button>
        </form>
      </div>
    </div>
  </div>
</template>
