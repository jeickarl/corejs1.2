import { createRouter, createWebHistory } from 'vue-router'
import type { UserRole } from '@corejs/shared/types'
import LoginPage from '../modules/auth/pages/LoginPage.vue'
import DashboardHomePage from '../modules/dashboard/pages/DashboardHomePage.vue'
import MainLayout from '../layouts/MainLayout.vue'
import ClientsPage from '../modules/clients/pages/ClientsPage.vue'
import ClientNewPage from '../modules/clients/pages/ClientNewPage.vue'
import ClientDetailPage from '../modules/clients/pages/ClientDetailPage.vue'
import ClientEditPage from '../modules/clients/pages/ClientEditPage.vue'
import CashPage from '../modules/cash/pages/CashPage.vue'
import InvoicesPage from '../modules/sales/pages/InvoicesPage.vue'
import InvoiceNewPage from '../modules/sales/pages/InvoiceNewPage.vue'
import InvoiceDetailPage from '../modules/sales/pages/InvoiceDetailPage.vue'
import ProductsPage from '../modules/inventory/pages/ProductsPage.vue'
import ProductNewPage from '../modules/inventory/pages/ProductNewPage.vue'
import ProductDetailPage from '../modules/inventory/pages/ProductDetailPage.vue'
import ProductEditPage from '../modules/inventory/pages/ProductEditPage.vue'
import SettingsPage from '../modules/settings/pages/SettingsPage.vue'
import OrdersPage from '../modules/orders/pages/OrdersPage.vue'
import OrderNewPage from '../modules/orders/pages/OrderNewPage.vue'
import OrderDetailPage from '../modules/orders/pages/OrderDetailPage.vue'
import OrderEditPage from '../modules/orders/pages/OrderEditPage.vue'
import SuppliersPage from '../modules/suppliers/pages/SuppliersPage.vue'
import SupplierNewPage from '../modules/suppliers/pages/SupplierNewPage.vue'
import SupplierDetailPage from '../modules/suppliers/pages/SupplierDetailPage.vue'
import SupplierEditPage from '../modules/suppliers/pages/SupplierEditPage.vue'
import PurchaseOrdersPage from '../modules/purchaseOrders/pages/PurchaseOrdersPage.vue'
import PurchaseOrderNewPage from '../modules/purchaseOrders/pages/PurchaseOrderNewPage.vue'
import PurchaseOrderDetailPage from '../modules/purchaseOrders/pages/PurchaseOrderDetailPage.vue'
import PurchaseOrderEditPage from '../modules/purchaseOrders/pages/PurchaseOrderEditPage.vue'
import SupplierPaymentsPage from '../modules/supplierPayments/pages/SupplierPaymentsPage.vue'
import PurchaseReceiptsPage from '../modules/purchaseReceipts/pages/PurchaseReceiptsPage.vue'
import PurchaseReceiptNewPage from '../modules/purchaseReceipts/pages/PurchaseReceiptNewPage.vue'
import PurchaseReceiptDetailPage from '../modules/purchaseReceipts/pages/PurchaseReceiptDetailPage.vue'
import ServicesPage from '../modules/services/pages/ServicesPage.vue'
import ServiceNewPage from '../modules/services/pages/ServiceNewPage.vue'
import ServiceDetailPage from '../modules/services/pages/ServiceDetailPage.vue'
import ServiceEditPage from '../modules/services/pages/ServiceEditPage.vue'
import ServiceCategoriesPage from '../modules/services/pages/ServiceCategoriesPage.vue'
import BackupPage from '../modules/backup/pages/BackupPage.vue'
import PortalPage from '../modules/portal/pages/PortalPage.vue'
import NotificationsPage from '../modules/notifications/pages/NotificationsPage.vue'
import ReportsPage from '../modules/reports/pages/ReportsPage.vue'
import SuperAdminLayout from '../layouts/SuperAdminLayout.vue'
import SuperAdminDashboardPage from '../modules/superAdmin/pages/SuperAdminDashboardPage.vue'
import TenantDetailPage from '../modules/superAdmin/pages/TenantDetailPage.vue'
import TenantsPage from '../modules/superAdmin/pages/TenantsPage.vue'
import HealthPage from '../modules/superAdmin/pages/HealthPage.vue'
import DbPoolPage from '../modules/superAdmin/pages/DbPoolPage.vue'
import RepairSchemaPage from '../modules/superAdmin/pages/RepairSchemaPage.vue'
import { useAuthStore } from '../store/auth'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', component: LoginPage },
    { path: '/portal/:tenantId', component: PortalPage },
    {
      path: '/',
      component: MainLayout,
      meta: { auth: true },
      children: [
        { path: '', component: DashboardHomePage },
        { path: 'reports', component: ReportsPage },
        { path: 'clients', component: ClientsPage },
        { path: 'clients/new', component: ClientNewPage },
        { path: 'clients/:id/edit', component: ClientEditPage },
        { path: 'clients/:id', component: ClientDetailPage },
        { path: 'cash', component: CashPage },
        { path: 'sales', component: InvoicesPage },
        { path: 'sales/new', component: InvoiceNewPage },
        { path: 'sales/:id', component: InvoiceDetailPage },
        { path: 'settings', component: SettingsPage },
        { path: 'inventory/products', component: ProductsPage },
        { path: 'inventory/products/new', component: ProductNewPage },
        { path: 'inventory/products/:id/edit', component: ProductEditPage },
        { path: 'inventory/products/:id', component: ProductDetailPage },
        { path: 'orders', component: OrdersPage },
        { path: 'orders/new', component: OrderNewPage },
        { path: 'orders/:id/edit', component: OrderEditPage },
        { path: 'orders/:id', component: OrderDetailPage },
        { path: 'suppliers', component: SuppliersPage },
        { path: 'suppliers/new', component: SupplierNewPage },
        { path: 'suppliers/:id/edit', component: SupplierEditPage },
        { path: 'suppliers/:id', component: SupplierDetailPage },
        { path: 'purchase-orders', component: PurchaseOrdersPage },
        { path: 'purchase-orders/new', component: PurchaseOrderNewPage },
        { path: 'purchase-orders/:id/edit', component: PurchaseOrderEditPage },
        { path: 'purchase-orders/:id', component: PurchaseOrderDetailPage },
        { path: 'supplier-payments', component: SupplierPaymentsPage },
        { path: 'purchase-receipts', component: PurchaseReceiptsPage },
        { path: 'purchase-receipts/new', component: PurchaseReceiptNewPage },
        { path: 'purchase-receipts/:id', component: PurchaseReceiptDetailPage },
        { path: 'services', component: ServicesPage },
        { path: 'services/new', component: ServiceNewPage },
        { path: 'services/categories', component: ServiceCategoriesPage },
        { path: 'services/:id/edit', component: ServiceEditPage },
        { path: 'services/:id', component: ServiceDetailPage },
        { path: 'backup', component: BackupPage },
        { path: 'notifications', component: NotificationsPage },
      ],
    },
    {
      path: '/super-admin',
      component: SuperAdminLayout,
      meta: { role: 'SUPER_ADMIN' satisfies UserRole },
      children: [
        { path: '', component: SuperAdminDashboardPage },
        { path: 'health', component: HealthPage },
        { path: 'db-pool', component: DbPoolPage },
        { path: 'repair-schema', component: RepairSchemaPage },
        { path: 'tenants', component: TenantsPage },
        { path: 'tenants/:id', component: TenantDetailPage },
      ],
    },
  ],
})

router.beforeEach(async (to) => {
  const requiredRole = to.meta.role as UserRole | undefined
  const requiresAuth = Boolean(to.meta.auth) || Boolean(requiredRole)
  const auth = useAuthStore()
  await auth.ensureLoaded()

  if (to.path === '/login') {
    if (auth.role === 'SUPER_ADMIN') return { path: '/super-admin' }
    if (auth.role === 'ADMIN' || auth.role === 'USER') return { path: '/' }
    return true
  }

  if (requiresAuth && !auth.role) {
    return { path: '/login' }
  }

  if (to.path === '/' || to.path === '') {
    if (auth.role === 'SUPER_ADMIN') return { path: '/super-admin' }
  }

  if (!requiredRole) return true
  if (auth.role === requiredRole) return true
  return { path: '/' }
})
