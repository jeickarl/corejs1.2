<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiGet } from '../api/http'
import { useAuthStore } from '../store/auth'
import { useNotificationsStore } from '../store/notifications'

type CompanyConfigDto = {
  companyName: string
  logoUrl: string
}

type NotificationPreview = {
  id: number
  title: string
  body: string
  type: string
  createdAt: string
  isRead: boolean
}

type NotificationsPageDto = {
  items: NotificationPreview[]
  page: number
  perPage: number
  total: number
  unreadCount: number
}

const auth = useAuthStore()
const notifications = useNotificationsStore()
const router = useRouter()
const route = useRoute()

const isAdmin = computed(() => auth.role === 'ADMIN')

const companyName = ref('CORE')
const companyLogoUrl = ref('/assets/img/system_logo.png')

const showNotifMenu = ref(false)
const showUserMenu = ref(false)
const notifLoading = ref(false)
const notifItems = ref<NotificationPreview[]>([])
const mobileOpen = ref(false)
const isDesktop = ref(true)

let touchStartX = 0
let touchStartY = 0
let touchStartAt = 0

const companyLogoSrc = computed(() => (companyLogoUrl.value?.trim() ? companyLogoUrl.value : '/assets/img/system_logo.png'))

const hamburgerIconClass = computed(() => {
  if (isDesktop.value) return 'fas fa-bars text-secondary'
  return mobileOpen.value ? 'fas fa-times text-secondary' : 'fas fa-bars text-secondary'
})

const userDisplayName = computed(() => {
  const nm = (auth.me?.name ?? '').trim()
  if (nm) return nm
  return 'Usuario'
})

const userInitial = computed(() => {
  const v = userDisplayName.value.trim()
  return (v ? v[0] : 'U').toUpperCase()
})

const roleLabel = computed(() => {
  const r = auth.role ?? ''
  if (r === 'ADMIN') return 'Admin'
  if (r === 'USER') return 'Usuario'
  if (r === 'SUPER_ADMIN') return 'Super Admin'
  return r
})

const pageTitle = computed(() => {
  const p = String(route.path ?? '')
  if (p === '/' || p === '') return 'Panel Principal'
  if (p.startsWith('/orders')) return 'Órdenes'
  if (p.startsWith('/clients')) return 'Clientes'
  if (p.startsWith('/sales')) return 'Ventas'
  if (p.startsWith('/cash')) return 'Caja'
  if (p.startsWith('/inventory')) return 'Inventario'
  if (p.startsWith('/suppliers')) return 'Proveedores'
  if (p.startsWith('/purchase-orders')) return 'Compras'
  if (p.startsWith('/supplier-payments')) return 'Pagos Proveedores'
  if (p.startsWith('/purchase-receipts')) return 'Recepciones'
  if (p.startsWith('/services')) return 'Servicios'
  if (p.startsWith('/reports')) return 'Reportes'
  if (p.startsWith('/notifications')) return 'Notificaciones'
  if (p.startsWith('/backup')) return 'Backups'
  if (p.startsWith('/settings')) return 'Ajustes'
  return 'Panel'
})

function isActive(prefix: string) {
  const p = String(route.path ?? '')
  if (prefix === '/') return p === '/'
  return p.startsWith(prefix)
}

function applySavedSidebarState() {
  try {
    const collapsed = localStorage.getItem('sidebarCollapsed')
    if (collapsed === '1' || collapsed === 'true') document.body.classList.add('sidebar-collapsed')
  } catch {
  }
}

function syncViewportState() {
  isDesktop.value = window.innerWidth >= 992
  if (isDesktop.value) {
    mobileOpen.value = false
    document.body.classList.remove('sidebar-mobile-open')
    document.body.classList.remove('sidebar-collapsed')
    applySavedSidebarState()
    return
  }
  document.body.classList.remove('sidebar-collapsed')
  document.body.classList.remove('sidebar-mobile-open')
  mobileOpen.value = false
}

function toggleSidebar() {
  if (isDesktop.value) {
    document.body.classList.toggle('sidebar-collapsed')
    try {
      localStorage.setItem('sidebarCollapsed', document.body.classList.contains('sidebar-collapsed') ? '1' : '0')
    } catch {
    }
    return
  }
  const open = !document.body.classList.contains('sidebar-mobile-open')
  if (open) document.body.classList.add('sidebar-mobile-open')
  else document.body.classList.remove('sidebar-mobile-open')
  mobileOpen.value = open
}

function closeMobileSidebar() {
  document.body.classList.remove('sidebar-mobile-open')
  mobileOpen.value = false
}

function onLogoClick(e: MouseEvent) {
  if (!isDesktop.value) return
  e.preventDefault()
  toggleSidebar()
}

async function loadCompany() {
  if (!auth.me?.tenantId) return
  if (!isAdmin.value) return
  const res = await apiGet<CompanyConfigDto>('/settings/company')
  if (!res.ok) return
  companyName.value = res.data.companyName?.trim() ? res.data.companyName : companyName.value
  companyLogoUrl.value = res.data.logoUrl?.trim() ? res.data.logoUrl : companyLogoUrl.value
}

async function loadNotifPreview() {
  notifLoading.value = true
  const qs = new URLSearchParams({ onlyUnread: '0', page: '1', perPage: '5' })
  const res = await apiGet<NotificationsPageDto>(`/notifications?${qs.toString()}`)
  notifLoading.value = false
  if (!res.ok) {
    notifItems.value = []
    return
  }
  notifItems.value = res.data.items
}

function closeMenus() {
  showNotifMenu.value = false
  showUserMenu.value = false
}

function toggleNotifMenu() {
  showUserMenu.value = false
  showNotifMenu.value = !showNotifMenu.value
  if (showNotifMenu.value) void loadNotifPreview()
}

function toggleUserMenu() {
  showNotifMenu.value = false
  showUserMenu.value = !showUserMenu.value
}

function notifText(n: NotificationPreview) {
  const body = (n.body ?? '').trim()
  if (!body) return ''
  return body.length > 90 ? `${body.slice(0, 90)}...` : body
}

function onDocClick() {
  closeMenus()
}

async function logout() {
  notifications.stopPolling()
  await auth.logout()
  await router.replace('/login')
}

function isMobileNavPath(path: string) {
  if (path === '/settings' && isAdmin.value) return true
  if (path === '/suppliers') return true
  if (path === '/inventory/products') return true
  if (path === '/cash') return true
  if (path === '/sales') return true
  if (path === '/clients') return true
  if (path === '/orders') return true
  if (path === '/') return true
  return false
}

function navIndexFor(currentPath: string, items: string[]) {
  const idx = items.findIndex((p) => (p === '/' ? currentPath === '/' : currentPath.startsWith(p)))
  return idx >= 0 ? idx : 0
}

function onTouchStart(e: TouchEvent) {
  if (window.innerWidth > 768) return
  const t = e.changedTouches?.[0]
  if (!t) return
  touchStartX = t.pageX
  touchStartY = t.pageY
  touchStartAt = Date.now()
}

function onTouchEnd(e: TouchEvent) {
  if (window.innerWidth > 768) return
  const t = e.changedTouches?.[0]
  if (!t) return
  const distX = t.pageX - touchStartX
  const distY = t.pageY - touchStartY
  const elapsed = Date.now() - touchStartAt
  const threshold = 50
  const restraint = 80
  const allowedTime = 600
  if (elapsed > allowedTime) return
  if (Math.abs(distY) > restraint) return
  if (Math.abs(distX) < threshold) return

  const currentPath = String(route.path ?? '')
  const items = [
    '/',
    '/orders',
    '/clients',
    '/sales',
    '/cash',
    '/inventory/products',
    '/suppliers',
    '/settings',
  ].filter((p) => isMobileNavPath(p))

  const idx = navIndexFor(currentPath, items)
  const next = distX < 0 ? items[Math.min(items.length - 1, idx + 1)] : items[Math.max(0, idx - 1)]
  if (!next || next === currentPath) return
  void router.push(next)
}

onMounted(async () => {
  await auth.ensureLoaded()
  notifications.startPolling()
  syncViewportState()
  void loadCompany()
  document.addEventListener('click', onDocClick)
  window.addEventListener('resize', syncViewportState)
})

onUnmounted(() => {
  notifications.stopPolling()
  document.removeEventListener('click', onDocClick)
  window.removeEventListener('resize', syncViewportState)
})

watch(
  () => route.path,
  () => {
    closeMobileSidebar()
    closeMenus()
  },
)
</script>

<template>
  <div>
    <div class="sidebar-overlay" :class="{ active: mobileOpen }" @click="closeMobileSidebar"></div>

    <aside class="sidebar-modern d-flex flex-column" id="sidebar" @click.stop>
      <div class="sidebar-nav mt-2">
        <ul class="nav-list">
          <li class="nav-item" :class="{ active: isActive('/') }">
            <router-link class="nav-link" to="/">
              <i class="fas fa-home nav-icon"></i>
              <span class="nav-text">Inicio</span>
            </router-link>
          </li>

          <li class="nav-item" :class="{ active: isActive('/orders') }">
            <router-link class="nav-link" to="/orders">
              <i class="fas fa-clipboard-list nav-icon"></i>
              <span class="nav-text">Órdenes</span>
            </router-link>
          </li>

          <li class="nav-item" :class="{ active: isActive('/clients') }">
            <router-link class="nav-link" to="/clients">
              <i class="fas fa-users nav-icon"></i>
              <span class="nav-text">Clientes</span>
            </router-link>
          </li>

          <li class="nav-item" :class="{ active: isActive('/sales') }">
            <router-link class="nav-link" to="/sales">
              <i class="fas fa-file-invoice-dollar nav-icon"></i>
              <span class="nav-text">Ventas</span>
            </router-link>
          </li>

          <li class="nav-item" :class="{ active: isActive('/cash') }">
            <router-link class="nav-link" to="/cash">
              <i class="fas fa-cash-register nav-icon"></i>
              <span class="nav-text">Caja</span>
            </router-link>
          </li>

          <li class="nav-item" :class="{ active: isActive('/inventory') }">
            <router-link class="nav-link" to="/inventory/products">
              <i class="fas fa-boxes nav-icon"></i>
              <span class="nav-text">Inventario</span>
            </router-link>
          </li>

          <li class="nav-item" :class="{ active: isActive('/suppliers') }">
            <router-link class="nav-link" to="/suppliers">
              <i class="fas fa-truck nav-icon"></i>
              <span class="nav-text">Proveedores</span>
            </router-link>
          </li>

          <li v-if="isAdmin" class="nav-item" :class="{ active: isActive('/settings') }">
            <router-link class="nav-link" to="/settings">
              <i class="fas fa-cog nav-icon"></i>
              <span class="nav-text">Ajustes</span>
            </router-link>
          </li>

        </ul>
      </div>
    </aside>

    <header
      class="top-header justify-content-between w-100 px-3 px-md-4"
      @click.stop
      @touchstart.passive="onTouchStart"
      @touchend.passive="onTouchEnd"
    >
      <div class="header-left d-flex align-items-center gap-3">
        <button id="sidebarToggle" type="button" class="btn btn-light shadow-sm d-flex justify-content-center align-items-center" aria-label="Toggle Sidebar" @click="toggleSidebar">
          <i :class="hamburgerIconClass"></i>
        </button>
        <router-link id="logoToggleBtn" to="/" class="d-flex align-items-center text-decoration-none" @click="onLogoClick">
          <img :src="companyLogoSrc" alt="Logo" style="height: 32px; width: auto; object-fit: contain; margin-right: 12px; border-radius: 4px;">
          <span class="fw-bold fs-5 d-none d-sm-block text-dark" style="letter-spacing: -0.5px;">{{ companyName }}</span>
        </router-link>
        <div class="d-none d-md-block" style="width: 1px; height: 24px; background-color: #cbd5e1; margin: 0 5px;"></div>
        <h5 class="page-title mb-0 text-truncate d-none d-md-block text-muted fw-medium" style="max-width: 30vw; font-size: 1.05rem; position: relative; top: 1px;">
          {{ pageTitle }}
        </h5>
      </div>

      <div class="header-right d-flex align-items-center gap-2 gap-md-3">
        <div class="dropdown position-relative">
          <button
            id="notifBellBtn"
            class="btn btn-light bg-white border shadow-sm position-relative rounded-circle d-flex align-items-center justify-content-center"
            style="width: 40px; height: 40px;"
            type="button"
            @click="toggleNotifMenu"
          >
            <i class="fas fa-bell text-secondary"></i>
            <span v-if="notifications.unreadCount > 0" class="position-absolute top-0 start-100 translate-middle p-1 bg-danger border border-light rounded-circle"></span>
          </button>

          <ul v-if="showNotifMenu" class="dropdown-menu dropdown-menu-end shadow border-0 mt-2 py-0 show" style="border-radius: 12px; min-width: 320px;">
            <li class="px-3 py-2 border-bottom d-flex align-items-center justify-content-between">
              <span class="fw-semibold">Notificaciones</span>
              <router-link class="small text-decoration-none" to="/notifications" @click="closeMenus">Ver todas</router-link>
            </li>
            <li v-if="notifLoading" class="px-3 py-3 text-muted small">Cargando...</li>
            <li v-else-if="notifItems.length === 0" class="px-3 py-3 text-muted small">Sin notificaciones</li>
            <li v-else>
              <button
                v-for="n in notifItems"
                :key="n.id"
                class="dropdown-item py-2"
                type="button"
                @click="router.push('/notifications'); closeMenus()"
              >
                <div class="d-flex justify-content-between gap-2">
                  <div class="fw-semibold text-truncate" style="max-width: 220px;">
                    <span v-if="!n.isRead" class="badge rounded-pill text-bg-danger me-2">Nuevo</span>{{ n.title }}
                  </div>
                  <div class="text-secondary small">{{ n.createdAt }}</div>
                </div>
                <div v-if="notifText(n)" class="text-secondary small text-truncate" style="max-width: 280px;">{{ notifText(n) }}</div>
              </button>
            </li>
          </ul>
        </div>

        <div class="dropdown position-relative">
          <button
            class="btn btn-light bg-white border border-light-subtle shadow-sm rounded-pill d-flex align-items-center gap-2 py-1 px-2"
            type="button"
            @click="toggleUserMenu"
          >
            <div class="avatar-initial d-flex align-items-center justify-content-center fw-bold text-white shadow-sm" style="width: 32px; height: 32px; border-radius: 50%; font-size: 0.9rem; background: var(--primary-color);">
              {{ userInitial }}
            </div>
            <div class="text-start d-none d-md-block lh-sm pe-2">
              <div class="fw-bold text-dark" style="font-size: 0.85rem; padding-bottom: 1px;">{{ userDisplayName }}</div>
              <div class="text-secondary" style="font-size: 0.70rem; font-weight: 500;">{{ roleLabel }}</div>
            </div>
            <i class="fas fa-chevron-down text-secondary ms-1 pe-1 d-none d-sm-block" style="font-size: 0.75rem;"></i>
          </button>

          <ul v-if="showUserMenu" class="dropdown-menu dropdown-menu-end shadow border-0 mt-2 py-2 show" style="border-radius: 12px; min-width: 220px;">
            <li class="px-3 py-2 mb-1 d-md-none border-bottom">
              <div class="fw-bold text-dark" style="font-size: 0.9rem;">{{ userDisplayName }}</div>
              <div class="text-secondary" style="font-size: 0.75rem;">{{ roleLabel }}</div>
            </li>
            <li v-if="isAdmin">
              <router-link class="dropdown-item py-2 d-flex align-items-center gap-3 text-secondary" to="/settings" @click="closeMenus">
                <i class="fas fa-cog fa-lg"></i> Ajustes
              </router-link>
            </li>
            <li><hr class="dropdown-divider my-2"></li>
            <li>
              <button class="dropdown-item text-danger py-2 fw-semibold d-flex align-items-center gap-3" type="button" @click="logout">
                <i class="fas fa-sign-out-alt fa-lg"></i> Cerrar Sesión
              </button>
            </li>
          </ul>
        </div>
      </div>
    </header>

    <div class="main-content">
      <main class="container-fluid">
        <router-view />
      </main>
    </div>
  </div>
</template>
