import type { MeDto, UserRole } from '@corejs/shared/types'
import { defineStore } from 'pinia'
import { apiGet, apiPost } from '../api/http'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    loaded: false as boolean,
    me: null as MeDto | null,
  }),
  getters: {
    role: (s): UserRole | null => s.me?.role ?? null,
  },
  actions: {
    async ensureLoaded() {
      if (this.loaded) return
      const res = await apiGet<MeDto>('/auth/me')
      this.loaded = true
      this.me = res.ok ? res.data : null
    },
    async login(email: string, password: string) {
      const res = await apiPost<MeDto, { email: string; password: string }>('/auth/login', {
        email,
        password,
      })
      this.loaded = true
      this.me = res.ok ? res.data : null
      return res
    },
    async logout() {
      await apiPost<{ done: true }, Record<string, never>>('/auth/logout', {})
      this.loaded = true
      this.me = null
    },
  },
})
