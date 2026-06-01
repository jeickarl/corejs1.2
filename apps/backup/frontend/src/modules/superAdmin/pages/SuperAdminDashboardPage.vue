<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { apiGet } from '../../../api/http'

type TenantDto = { id: number; companyName: string; status: 'active' | 'suspended'; createdAt: string }

const tenantsCount = ref<number | null>(null)

onMounted(async () => {
  const res = await apiGet<TenantDto[]>('/super-admin/tenants')
  tenantsCount.value = res.ok ? res.data.length : null
})
</script>

<template>
  <div class="card shadow-soft border-0 rounded-custom">
    <div class="card-body">
      <h5 class="fw-semibold mb-2">Dashboard Maestro</h5>
      <div class="text-secondary">Tenants: {{ tenantsCount ?? '—' }}</div>
    </div>
  </div>
</template>

