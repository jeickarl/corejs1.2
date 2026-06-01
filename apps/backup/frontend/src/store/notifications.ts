import { defineStore } from 'pinia'
import { apiGet, apiPatch } from '../api/http'

export type NotificationDto = {
  id: number
  title: string
  body: string
  type: string
  createdAt: string
  isRead: boolean
}

type NotificationsPageDto = {
  items: NotificationDto[]
  page: number
  perPage: number
  total: number
  unreadCount: number
}

let pollHandle: number | null = null

export const useNotificationsStore = defineStore('notifications', {
  state: () => ({
    items: [] as NotificationDto[],
    page: 1 as number,
    perPage: 10 as number,
    total: 0 as number,
    onlyUnread: false as boolean,
    unreadCount: 0 as number,
    message: '' as string,
    loading: false as boolean,
  }),
  actions: {
    async fetchPage(input?: { onlyUnread?: boolean; page?: number; perPage?: number }) {
      this.loading = true
      this.message = ''
      if (typeof input?.onlyUnread === 'boolean') this.onlyUnread = input.onlyUnread
      if (typeof input?.page === 'number' && Number.isFinite(input.page) && input.page > 0) this.page = Math.floor(input.page)
      if (typeof input?.perPage === 'number' && Number.isFinite(input.perPage) && input.perPage > 0)
        this.perPage = Math.floor(input.perPage)

      const qs = new URLSearchParams({
        onlyUnread: this.onlyUnread ? '1' : '0',
        page: String(this.page),
        perPage: String(this.perPage),
      })

      const res = await apiGet<NotificationsPageDto>(`/notifications?${qs.toString()}`)
      this.loading = false
      if (!res.ok) {
        this.message = res.error.message
        this.items = []
        this.total = 0
        return
      }

      this.items = res.data.items
      this.page = res.data.page
      this.perPage = res.data.perPage
      this.total = res.data.total
      this.unreadCount = res.data.unreadCount
    },
    async refreshUnreadCount() {
      const qs = new URLSearchParams({ onlyUnread: '1', page: '1', perPage: '1' })
      const res = await apiGet<NotificationsPageDto>(`/notifications?${qs.toString()}`)
      if (!res.ok) return
      this.unreadCount = res.data.unreadCount
    },
    startPolling(intervalMs = 25000) {
      if (pollHandle) return
      void this.refreshUnreadCount()
      pollHandle = window.setInterval(() => {
        void this.refreshUnreadCount()
      }, intervalMs)
    },
    stopPolling() {
      if (!pollHandle) return
      window.clearInterval(pollHandle)
      pollHandle = null
    },
    async markRead(id: number) {
      const res = await apiPatch<{ done: true }, Record<string, never>>(`/notifications/${id}/read`, {})
      if (!res.ok) return res
      this.items = this.items.map((n) => (n.id === id ? { ...n, isRead: true } : n))
      await this.refreshUnreadCount()
      return res
    },
    async markAllRead() {
      const res = await apiPatch<{ done: true }, Record<string, never>>('/notifications/read-all', {})
      if (!res.ok) return res
      this.items = this.items.map((n) => ({ ...n, isRead: true }))
      this.unreadCount = 0
      return res
    },
  },
})

