<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { apiGet, apiPatch, apiPost, apiDelete } from '../../../api/http'

type CompanyConfig = {
  companyName: string
  companyPhone: string
  companyEmail: string
  companyWebsite: string
  companyAddress: string
  logoUrl: string
}

type RegionalConfig = {
  currency: string
  currencySymbol: string
  taxEnabled: boolean
  taxName: string
  taxRate: number
  invoiceDueDaysDefault: number
}

type PaymentMethod = {
  id: number
  name: string
  isDefault: boolean
  isActive: boolean
  createdAt: string
}

type PaymentAccount = {
  id: number
  paymentMethodId: number
  alias: string
  accountNumber: string
  accountType: string
  holderName: string
  holderId: string
  isActive: boolean
}

type WhatsappTemplates = { reception: string; ready: string; delivery: string; sale: string }
type Appearance = { themeMode: 'light' | 'dark' }

type SettingsUser = {
  id: number
  email: string
  name: string
  role: 'admin' | 'user'
  active: boolean
  createdAt: string
}

type ClientPortalConfig = {
  enableLookupById: boolean
  showTimeline: boolean
  allowApproval: boolean
  homeTitle: string
  homeSubtitle: string
  whatsappLink: string
  addressText: string
  hoursText: string
  mapEmbedUrl: string
}

type DeviceType = { id: number; name: string; isActive: boolean; sortOrder: number; createdAt: string; updatedAt: string }
type Brand = { id: number; name: string; isActive: boolean; createdAt: string; updatedAt: string }
type Model = { id: number; name: string; brandId: number | null; deviceTypeId: number | null; isActive: boolean; createdAt: string; updatedAt: string }

const tab = ref<'company' | 'regional' | 'payments' | 'deviceTypes' | 'brandsModels' | 'whatsapp' | 'appearance' | 'users' | 'portal'>(
  'company',
)

const message = ref('')
const loading = ref(false)

const company = ref<CompanyConfig>({
  companyName: '',
  companyPhone: '',
  companyEmail: '',
  companyWebsite: '',
  companyAddress: '',
  logoUrl: '',
})

const regional = ref<RegionalConfig>({
  currency: 'COP',
  currencySymbol: '$',
  taxEnabled: false,
  taxName: 'IVA',
  taxRate: 0,
  invoiceDueDaysDefault: 0,
})

const methods = ref<PaymentMethod[]>([])
const newMethodName = ref('')

const selectedMethodId = ref<number | null>(null)
const accounts = ref<PaymentAccount[]>([])
const newAccAlias = ref('')
const newAccNumber = ref('')
const newAccType = ref('')
const newAccHolder = ref('')
const newAccHolderId = ref('')
const newAccActive = ref(true)

const deviceTypes = ref<DeviceType[]>([])
const newDeviceTypeName = ref('')
const newDeviceTypeSort = ref<number>(0)
const newDeviceTypeActive = ref(true)

const brands = ref<Brand[]>([])
const newBrandName = ref('')
const newBrandActive = ref(true)

const models = ref<Model[]>([])
const newModelName = ref('')
const newModelBrandId = ref<number | null>(null)
const newModelDeviceTypeId = ref<number | null>(null)
const newModelActive = ref(true)

const whatsapp = ref<WhatsappTemplates>({ reception: '', ready: '', delivery: '', sale: '' })
const appearance = ref<Appearance>({ themeMode: 'light' })

const users = ref<SettingsUser[]>([])
const newUserEmail = ref('')
const newUserName = ref('')
const newUserRole = ref<'admin' | 'user'>('user')
const newUserPassword = ref('')
const newUserActive = ref(true)

const editOpen = ref(false)
const editType = ref<'account' | 'deviceType' | 'brand' | 'model' | 'user' | 'userPassword' | null>(null)
const editTitle = ref('')
const editBusy = ref(false)

const editAccountRef = ref<PaymentAccount | null>(null)
const editAccAlias = ref('')
const editAccNumber = ref('')
const editAccType = ref('')
const editAccHolder = ref('')
const editAccHolderId = ref('')

const editDeviceTypeRef = ref<DeviceType | null>(null)
const editDeviceTypeName = ref('')
const editDeviceTypeSort = ref<number>(0)

const editBrandRef = ref<Brand | null>(null)
const editBrandName = ref('')

const editModelRef = ref<Model | null>(null)
const editModelName = ref('')
const editModelBrandId = ref<number | null>(null)
const editModelDeviceTypeId = ref<number | null>(null)

const editUserRef = ref<SettingsUser | null>(null)
const editUserEmail = ref('')
const editUserName = ref('')
const editUserPassword = ref('')

const portal = ref<ClientPortalConfig>({
  enableLookupById: true,
  showTimeline: true,
  allowApproval: true,
  homeTitle: '',
  homeSubtitle: '',
  whatsappLink: '',
  addressText: '',
  hoursText: '',
  mapEmbedUrl: '',
})

async function loadAll() {
  loading.value = true
  message.value = ''

  const resCompany = await apiGet<CompanyConfig>('/settings/company')
  if (resCompany.ok) company.value = resCompany.data

  const resRegional = await apiGet<RegionalConfig>('/settings/regional')
  if (resRegional.ok) regional.value = resRegional.data

  const resMethods = await apiGet<PaymentMethod[]>('/settings/payment-methods')
  methods.value = resMethods.ok ? resMethods.data : []

  const resDeviceTypes = await apiGet<DeviceType[]>('/settings/device-types')
  deviceTypes.value = resDeviceTypes.ok ? resDeviceTypes.data : []

  const resBrands = await apiGet<Brand[]>('/settings/brands')
  brands.value = resBrands.ok ? resBrands.data : []

  const resModels = await apiGet<Model[]>('/settings/models')
  models.value = resModels.ok ? resModels.data : []

  const resWhatsapp = await apiGet<WhatsappTemplates>('/settings/whatsapp')
  if (resWhatsapp.ok) whatsapp.value = resWhatsapp.data

  const resAppearance = await apiGet<Appearance>('/settings/appearance')
  if (resAppearance.ok) appearance.value = resAppearance.data

  const resUsers = await apiGet<SettingsUser[]>('/settings/users')
  users.value = resUsers.ok ? resUsers.data : []

  const resPortal = await apiGet<ClientPortalConfig>('/settings/client-portal')
  if (resPortal.ok) portal.value = resPortal.data

  if (
    !resCompany.ok &&
    !resRegional.ok &&
    !resMethods.ok &&
    !resDeviceTypes.ok &&
    !resBrands.ok &&
    !resModels.ok &&
    !resWhatsapp.ok &&
    !resAppearance.ok &&
    !resUsers.ok &&
    !resPortal.ok
  ) {
    message.value = resCompany.ok ? '' : resCompany.error.message
  }

  loading.value = false
}

async function saveCompany() {
  message.value = ''
  const res = await apiPatch<{ done: true }, Record<string, unknown>>('/settings/company', { ...company.value })
  message.value = res.ok ? 'Guardado' : res.error.message
}

async function saveRegional() {
  message.value = ''
  const res = await apiPatch<{ done: true }, Record<string, unknown>>('/settings/regional', { ...regional.value })
  message.value = res.ok ? 'Guardado' : res.error.message
}

async function addPaymentMethod() {
  const name = newMethodName.value.trim()
  if (!name) return
  message.value = ''
  const res = await apiPost<{ id: number }, Record<string, unknown>>('/settings/payment-methods', {
    name,
    isDefault: false,
    isActive: true,
  })
  if (!res.ok) {
    message.value = res.error.message
    return
  }
  newMethodName.value = ''
  await loadAll()
}

async function loadAccounts(methodId: number) {
  const res = await apiGet<PaymentAccount[]>(`/settings/payment-methods/${methodId}/accounts`)
  accounts.value = res.ok ? res.data : []
}

async function selectMethod(m: PaymentMethod) {
  selectedMethodId.value = m.id
  newAccAlias.value = ''
  newAccNumber.value = ''
  newAccType.value = ''
  newAccHolder.value = ''
  newAccHolderId.value = ''
  newAccActive.value = true
  await loadAccounts(m.id)
}

async function addAccount() {
  if (!selectedMethodId.value) return
  const alias = newAccAlias.value.trim()
  if (!alias) return
  message.value = ''
  const res = await apiPost<{ id: number }, Record<string, unknown>>(`/settings/payment-methods/${selectedMethodId.value}/accounts`, {
    alias,
    accountNumber: newAccNumber.value.trim() || undefined,
    accountType: newAccType.value.trim() || undefined,
    holderName: newAccHolder.value.trim() || undefined,
    holderId: newAccHolderId.value.trim() || undefined,
    isActive: Boolean(newAccActive.value),
  })
  message.value = res.ok ? 'Creado' : res.error.message
  if (!res.ok) return
  newAccAlias.value = ''
  newAccNumber.value = ''
  newAccType.value = ''
  newAccHolder.value = ''
  newAccHolderId.value = ''
  newAccActive.value = true
  await loadAccounts(selectedMethodId.value)
}

async function editAccount(a: PaymentAccount) {
  if (!selectedMethodId.value) return
  editAccountRef.value = a
  editAccAlias.value = a.alias
  editAccNumber.value = a.accountNumber
  editAccType.value = a.accountType
  editAccHolder.value = a.holderName
  editAccHolderId.value = a.holderId
  editTitle.value = `Editar cuenta: ${a.alias}`
  editType.value = 'account'
  editOpen.value = true
}

async function toggleAccountActive(a: PaymentAccount) {
  if (!selectedMethodId.value) return
  message.value = ''
  const res = await apiPatch<{ done: true }, Record<string, unknown>>(
    `/settings/payment-methods/${selectedMethodId.value}/accounts/${a.id}`,
    { alias: a.alias, accountNumber: a.accountNumber, accountType: a.accountType, holderName: a.holderName, holderId: a.holderId, isActive: !a.isActive },
  )
  message.value = res.ok ? 'Actualizado' : res.error.message
  await loadAccounts(selectedMethodId.value)
}

async function deleteAccount(a: PaymentAccount) {
  const ok = window.confirm(`¿Desactivar cuenta "${a.alias}"?`)
  if (!ok) return
  message.value = ''
  const res = await apiDelete<{ done: true }>(`/settings/payment-accounts/${a.id}`)
  message.value = res.ok ? 'Actualizado' : res.error.message
  if (selectedMethodId.value) await loadAccounts(selectedMethodId.value)
}

async function addDeviceType() {
  const name = newDeviceTypeName.value.trim()
  if (!name) return
  message.value = ''
  const res = await apiPost<{ id: number }, Record<string, unknown>>('/settings/device-types', {
    name,
    sortOrder: Number(newDeviceTypeSort.value || 0),
    isActive: Boolean(newDeviceTypeActive.value),
  })
  message.value = res.ok ? 'Creado' : res.error.message
  if (!res.ok) return
  newDeviceTypeName.value = ''
  newDeviceTypeSort.value = 0
  newDeviceTypeActive.value = true
  await loadAll()
}

async function editDeviceType(t: DeviceType) {
  editDeviceTypeRef.value = t
  editDeviceTypeName.value = t.name
  editDeviceTypeSort.value = t.sortOrder
  editTitle.value = `Editar tipo: ${t.name}`
  editType.value = 'deviceType'
  editOpen.value = true
}

async function toggleDeviceTypeActive(t: DeviceType) {
  message.value = ''
  const res = await apiPatch<{ done: true }, Record<string, unknown>>(`/settings/device-types/${t.id}`, { isActive: !t.isActive })
  message.value = res.ok ? 'Actualizado' : res.error.message
  await loadAll()
}

async function deleteDeviceType(t: DeviceType) {
  const ok = window.confirm(`¿Desactivar tipo "${t.name}"?`)
  if (!ok) return
  message.value = ''
  const res = await apiDelete<{ done: true }>(`/settings/device-types/${t.id}`)
  message.value = res.ok ? 'Actualizado' : res.error.message
  await loadAll()
}

async function addBrand() {
  const name = newBrandName.value.trim()
  if (!name) return
  message.value = ''
  const res = await apiPost<{ id: number }, Record<string, unknown>>('/settings/brands', { name, isActive: Boolean(newBrandActive.value) })
  message.value = res.ok ? 'Creado' : res.error.message
  if (!res.ok) return
  newBrandName.value = ''
  newBrandActive.value = true
  await loadAll()
}

async function editBrand(b: Brand) {
  editBrandRef.value = b
  editBrandName.value = b.name
  editTitle.value = `Editar marca: ${b.name}`
  editType.value = 'brand'
  editOpen.value = true
}

async function toggleBrandActive(b: Brand) {
  message.value = ''
  const res = await apiPatch<{ done: true }, Record<string, unknown>>(`/settings/brands/${b.id}`, { isActive: !b.isActive })
  message.value = res.ok ? 'Actualizado' : res.error.message
  await loadAll()
}

async function deleteBrand(b: Brand) {
  const ok = window.confirm(`¿Desactivar marca "${b.name}"?`)
  if (!ok) return
  message.value = ''
  const res = await apiDelete<{ done: true }>(`/settings/brands/${b.id}`)
  message.value = res.ok ? 'Actualizado' : res.error.message
  await loadAll()
}

async function addModel() {
  const name = newModelName.value.trim()
  if (!name) return
  message.value = ''
  const res = await apiPost<{ id: number }, Record<string, unknown>>('/settings/models', {
    name,
    brandId: newModelBrandId.value,
    deviceTypeId: newModelDeviceTypeId.value,
    isActive: Boolean(newModelActive.value),
  })
  message.value = res.ok ? 'Creado' : res.error.message
  if (!res.ok) return
  newModelName.value = ''
  newModelBrandId.value = null
  newModelDeviceTypeId.value = null
  newModelActive.value = true
  await loadAll()
}

async function editModel(m: Model) {
  editModelRef.value = m
  editModelName.value = m.name
  editModelBrandId.value = m.brandId
  editModelDeviceTypeId.value = m.deviceTypeId
  editTitle.value = `Editar modelo: ${m.name}`
  editType.value = 'model'
  editOpen.value = true
}

async function toggleModelActive(m: Model) {
  message.value = ''
  const res = await apiPatch<{ done: true }, Record<string, unknown>>(`/settings/models/${m.id}`, { isActive: !m.isActive })
  message.value = res.ok ? 'Actualizado' : res.error.message
  await loadAll()
}

async function deleteModel(m: Model) {
  const ok = window.confirm(`¿Desactivar modelo "${m.name}"?`)
  if (!ok) return
  message.value = ''
  const res = await apiDelete<{ done: true }>(`/settings/models/${m.id}`)
  message.value = res.ok ? 'Actualizado' : res.error.message
  await loadAll()
}

async function setDefault(m: PaymentMethod) {
  message.value = ''
  const res = await apiPatch<{ done: true }, Record<string, unknown>>(`/settings/payment-methods/${m.id}`, {
    isDefault: true,
    isActive: true,
    name: m.name,
  })
  message.value = res.ok ? 'Guardado' : res.error.message
  await loadAll()
}

async function toggleActive(m: PaymentMethod) {
  message.value = ''
  if (m.isActive) {
    const res = await apiDelete<{ done: true }>(`/settings/payment-methods/${m.id}`)
    message.value = res.ok ? 'Actualizado' : res.error.message
  } else {
    const res = await apiPatch<{ done: true }, Record<string, unknown>>(`/settings/payment-methods/${m.id}`, {
      isActive: true,
      name: m.name,
      isDefault: m.isDefault,
    })
    message.value = res.ok ? 'Actualizado' : res.error.message
  }
  await loadAll()
}

async function saveWhatsapp() {
  message.value = ''
  const res = await apiPatch<{ done: true }, Record<string, unknown>>('/settings/whatsapp', { ...whatsapp.value })
  message.value = res.ok ? 'Guardado' : res.error.message
}

async function saveAppearance() {
  message.value = ''
  const res = await apiPatch<{ done: true }, Record<string, unknown>>('/settings/appearance', { themeMode: appearance.value.themeMode })
  message.value = res.ok ? 'Guardado' : res.error.message
}

async function savePortal() {
  message.value = ''
  const res = await apiPatch<{ done: true }, Record<string, unknown>>('/settings/client-portal', { ...portal.value })
  message.value = res.ok ? 'Guardado' : res.error.message
}

async function createUser() {
  const email = newUserEmail.value.trim()
  const name = newUserName.value.trim()
  const password = newUserPassword.value.trim()
  if (!email || !name || !password) return
  message.value = ''
  const res = await apiPost<{ id: number }, Record<string, unknown>>('/settings/users', {
    email,
    name,
    role: newUserRole.value,
    password,
    active: Boolean(newUserActive.value),
  })
  message.value = res.ok ? 'Creado' : res.error.message
  if (!res.ok) return
  newUserEmail.value = ''
  newUserName.value = ''
  newUserPassword.value = ''
  newUserRole.value = 'user'
  newUserActive.value = true
  await loadAll()
}

async function resetPassword(u: SettingsUser) {
  editUserRef.value = u
  editUserPassword.value = ''
  editTitle.value = `Nueva contraseña: ${u.email}`
  editType.value = 'userPassword'
  editOpen.value = true
}

async function toggleUserActive(u: SettingsUser) {
  message.value = ''
  const res = await apiPatch<{ done: true }, Record<string, unknown>>(`/settings/users/${u.id}`, { active: !u.active })
  message.value = res.ok ? 'Actualizado' : res.error.message
  await loadAll()
}

async function toggleUserRole(u: SettingsUser) {
  message.value = ''
  const nextRole = u.role === 'admin' ? 'user' : 'admin'
  const res = await apiPatch<{ done: true }, Record<string, unknown>>(`/settings/users/${u.id}`, { role: nextRole })
  message.value = res.ok ? 'Actualizado' : res.error.message
  await loadAll()
}

async function editUser(u: SettingsUser) {
  editUserRef.value = u
  editUserEmail.value = u.email
  editUserName.value = u.name
  editTitle.value = `Editar usuario: ${u.email}`
  editType.value = 'user'
  editOpen.value = true
}

async function deleteUser(u: SettingsUser) {
  const ok = window.confirm(`¿Eliminar usuario ${u.email}?`)
  if (!ok) return
  message.value = ''
  const res = await apiDelete<{ deleted: true }>(`/settings/users/${u.id}`)
  message.value = res.ok ? 'Eliminado' : res.error.message
  await loadAll()
}

function closeEdit() {
  editOpen.value = false
  editType.value = null
  editTitle.value = ''
  editBusy.value = false
  editAccountRef.value = null
  editDeviceTypeRef.value = null
  editBrandRef.value = null
  editModelRef.value = null
  editUserRef.value = null
  editUserPassword.value = ''
}

async function submitEdit() {
  if (!editType.value) return
  if (editBusy.value) return
  message.value = ''
  editBusy.value = true
  try {
    if (editType.value === 'account') {
      if (!selectedMethodId.value || !editAccountRef.value) return
      const alias = editAccAlias.value.trim()
      if (!alias) return
      const res = await apiPatch<{ done: true }, Record<string, unknown>>(
        `/settings/payment-methods/${selectedMethodId.value}/accounts/${editAccountRef.value.id}`,
        {
          alias,
          accountNumber: editAccNumber.value.trim(),
          accountType: editAccType.value.trim(),
          holderName: editAccHolder.value.trim(),
          holderId: editAccHolderId.value.trim(),
          isActive: editAccountRef.value.isActive,
        },
      )
      message.value = res.ok ? 'Actualizado' : res.error.message
      await loadAccounts(selectedMethodId.value)
      if (!res.ok) return
    } else if (editType.value === 'deviceType') {
      if (!editDeviceTypeRef.value) return
      const name = editDeviceTypeName.value.trim()
      if (!name) return
      const sortOrder = Number(editDeviceTypeSort.value)
      const res = await apiPatch<{ done: true }, Record<string, unknown>>(`/settings/device-types/${editDeviceTypeRef.value.id}`, {
        name,
        sortOrder: Number.isFinite(sortOrder) ? sortOrder : editDeviceTypeRef.value.sortOrder,
        isActive: editDeviceTypeRef.value.isActive,
      })
      message.value = res.ok ? 'Actualizado' : res.error.message
      await loadAll()
      if (!res.ok) return
    } else if (editType.value === 'brand') {
      if (!editBrandRef.value) return
      const name = editBrandName.value.trim()
      if (!name) return
      const res = await apiPatch<{ done: true }, Record<string, unknown>>(`/settings/brands/${editBrandRef.value.id}`, {
        name,
        isActive: editBrandRef.value.isActive,
      })
      message.value = res.ok ? 'Actualizado' : res.error.message
      await loadAll()
      if (!res.ok) return
    } else if (editType.value === 'model') {
      if (!editModelRef.value) return
      const name = editModelName.value.trim()
      if (!name) return
      const res = await apiPatch<{ done: true }, Record<string, unknown>>(`/settings/models/${editModelRef.value.id}`, {
        name,
        brandId: editModelBrandId.value,
        deviceTypeId: editModelDeviceTypeId.value,
        isActive: editModelRef.value.isActive,
      })
      message.value = res.ok ? 'Actualizado' : res.error.message
      await loadAll()
      if (!res.ok) return
    } else if (editType.value === 'user') {
      if (!editUserRef.value) return
      const email = editUserEmail.value.trim()
      const name = editUserName.value.trim()
      if (!email || !name) return
      const res = await apiPatch<{ done: true }, Record<string, unknown>>(`/settings/users/${editUserRef.value.id}`, { email, name })
      message.value = res.ok ? 'Actualizado' : res.error.message
      await loadAll()
      if (!res.ok) return
    } else if (editType.value === 'userPassword') {
      if (!editUserRef.value) return
      const pw = editUserPassword.value.trim()
      if (!pw) return
      const res = await apiPatch<{ done: true }, Record<string, unknown>>(`/settings/users/${editUserRef.value.id}/password`, { newPassword: pw })
      message.value = res.ok ? 'Actualizado' : res.error.message
      if (!res.ok) return
    }

    closeEdit()
  } finally {
    editBusy.value = false
  }
}

onMounted(loadAll)
</script>

<template>
  <div class="d-flex flex-column gap-3">
    <teleport to="body">
      <div
        v-if="editOpen"
        class="position-fixed top-0 start-0 w-100 h-100"
        style="background: rgba(0,0,0,.5); z-index: 1050;"
      >
        <div
          class="position-absolute top-50 start-50 translate-middle bg-white rounded-4 p-3 shadow"
          style="min-width: 340px; max-width: 92vw;"
        >
          <div class="d-flex align-items-start justify-content-between gap-3">
            <div class="fw-semibold">{{ editTitle }}</div>
            <button class="btn-close" type="button" :disabled="editBusy" @click="closeEdit"></button>
          </div>

          <div v-if="editType === 'account'" class="mt-3">
            <div class="row g-2">
              <div class="col-12">
                <label class="form-label">Alias</label>
                <input v-model="editAccAlias" class="form-control" :disabled="editBusy" />
              </div>
              <div class="col-12">
                <label class="form-label">Número</label>
                <input v-model="editAccNumber" class="form-control" :disabled="editBusy" />
              </div>
              <div class="col-12">
                <label class="form-label">Tipo</label>
                <input v-model="editAccType" class="form-control" :disabled="editBusy" />
              </div>
              <div class="col-12">
                <label class="form-label">Titular</label>
                <input v-model="editAccHolder" class="form-control" :disabled="editBusy" />
              </div>
              <div class="col-12">
                <label class="form-label">Identificación</label>
                <input v-model="editAccHolderId" class="form-control" :disabled="editBusy" />
              </div>
            </div>
          </div>

          <div v-else-if="editType === 'deviceType'" class="mt-3">
            <div class="row g-2">
              <div class="col-12">
                <label class="form-label">Nombre</label>
                <input v-model="editDeviceTypeName" class="form-control" :disabled="editBusy" />
              </div>
              <div class="col-12">
                <label class="form-label">Orden</label>
                <input v-model.number="editDeviceTypeSort" class="form-control" type="number" :disabled="editBusy" />
              </div>
            </div>
          </div>

          <div v-else-if="editType === 'brand'" class="mt-3">
            <label class="form-label">Nombre</label>
            <input v-model="editBrandName" class="form-control" :disabled="editBusy" />
          </div>

          <div v-else-if="editType === 'model'" class="mt-3">
            <div class="row g-2">
              <div class="col-12">
                <label class="form-label">Nombre</label>
                <input v-model="editModelName" class="form-control" :disabled="editBusy" />
              </div>
              <div class="col-12">
                <label class="form-label">Marca</label>
                <select v-model="editModelBrandId" class="form-select" :disabled="editBusy">
                  <option :value="null">-</option>
                  <option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label">Tipo de dispositivo</label>
                <select v-model="editModelDeviceTypeId" class="form-select" :disabled="editBusy">
                  <option :value="null">-</option>
                  <option v-for="t in deviceTypes" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
              </div>
            </div>
          </div>

          <div v-else-if="editType === 'user'" class="mt-3">
            <div class="row g-2">
              <div class="col-12">
                <label class="form-label">Email</label>
                <input v-model="editUserEmail" class="form-control" :disabled="editBusy" />
              </div>
              <div class="col-12">
                <label class="form-label">Nombre</label>
                <input v-model="editUserName" class="form-control" :disabled="editBusy" />
              </div>
            </div>
          </div>

          <div v-else-if="editType === 'userPassword'" class="mt-3">
            <label class="form-label">Nueva contraseña</label>
            <input v-model="editUserPassword" class="form-control" type="password" :disabled="editBusy" />
          </div>

          <div class="d-flex justify-content-end gap-2 mt-3">
            <button class="btn btn-outline-secondary rounded-pill" type="button" :disabled="editBusy" @click="closeEdit">
              Cancelar
            </button>
            <button class="btn btn-dark rounded-pill" type="button" :disabled="editBusy" @click="submitEdit">
              Guardar
            </button>
          </div>
        </div>
      </div>
    </teleport>

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
      <div>
        <h5 class="fw-semibold mb-1">Configuraciones</h5>
        <div class="text-secondary small">Empresa, impuestos, pagos y plantillas</div>
      </div>
    </div>

    <div v-if="message" class="alert alert-warning border-0 shadow-sm mb-0">
      {{ message }}
    </div>
    <div v-if="loading" class="text-secondary">Cargando...</div>

    <div class="card shadow-soft border-0 rounded-custom">
      <div class="card-body">
        <ul class="nav nav-pills gap-2 flex-wrap">
          <li class="nav-item">
            <button class="btn btn-sm rounded-pill" :class="tab === 'company' ? 'btn-dark' : 'btn-outline-dark'" type="button" @click="tab = 'company'">
              Empresa
            </button>
          </li>
          <li class="nav-item">
            <button class="btn btn-sm rounded-pill" :class="tab === 'regional' ? 'btn-dark' : 'btn-outline-dark'" type="button" @click="tab = 'regional'">
              Regional
            </button>
          </li>
          <li class="nav-item">
            <button class="btn btn-sm rounded-pill" :class="tab === 'payments' ? 'btn-dark' : 'btn-outline-dark'" type="button" @click="tab = 'payments'">
              Pagos
            </button>
          </li>
          <li class="nav-item">
            <button
              class="btn btn-sm rounded-pill"
              :class="tab === 'deviceTypes' ? 'btn-dark' : 'btn-outline-dark'"
              type="button"
              @click="tab = 'deviceTypes'"
            >
              Tipos
            </button>
          </li>
          <li class="nav-item">
            <button
              class="btn btn-sm rounded-pill"
              :class="tab === 'brandsModels' ? 'btn-dark' : 'btn-outline-dark'"
              type="button"
              @click="tab = 'brandsModels'"
            >
              Marcas/Modelos
            </button>
          </li>
          <li class="nav-item">
            <button class="btn btn-sm rounded-pill" :class="tab === 'whatsapp' ? 'btn-dark' : 'btn-outline-dark'" type="button" @click="tab = 'whatsapp'">
              WhatsApp
            </button>
          </li>
          <li class="nav-item">
            <button class="btn btn-sm rounded-pill" :class="tab === 'appearance' ? 'btn-dark' : 'btn-outline-dark'" type="button" @click="tab = 'appearance'">
              Apariencia
            </button>
          </li>
          <li class="nav-item">
            <button class="btn btn-sm rounded-pill" :class="tab === 'users' ? 'btn-dark' : 'btn-outline-dark'" type="button" @click="tab = 'users'">
              Usuarios
            </button>
          </li>
          <li class="nav-item">
            <button class="btn btn-sm rounded-pill" :class="tab === 'portal' ? 'btn-dark' : 'btn-outline-dark'" type="button" @click="tab = 'portal'">
              Portal Cliente
            </button>
          </li>
        </ul>

        <div class="border-top my-3"></div>

        <div v-if="tab === 'company'" class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-medium">Nombre</label>
            <input v-model="company.companyName" class="form-control" />
          </div>
          <div class="col-md-6">
            <label class="form-label fw-medium">Teléfono</label>
            <input v-model="company.companyPhone" class="form-control" />
          </div>
          <div class="col-md-6">
            <label class="form-label fw-medium">Email</label>
            <input v-model="company.companyEmail" class="form-control" />
          </div>
          <div class="col-md-6">
            <label class="form-label fw-medium">Website</label>
            <input v-model="company.companyWebsite" class="form-control" />
          </div>
          <div class="col-md-12">
            <label class="form-label fw-medium">Dirección</label>
            <input v-model="company.companyAddress" class="form-control" />
          </div>
          <div class="col-md-12">
            <label class="form-label fw-medium">Logo URL</label>
            <input v-model="company.logoUrl" class="form-control" />
          </div>
          <div class="col-12 text-end">
            <button class="btn btn-dark rounded-pill px-4" type="button" @click="saveCompany">Guardar</button>
          </div>
        </div>

        <div v-if="tab === 'regional'" class="row g-3">
          <div class="col-md-3">
            <label class="form-label fw-medium">Moneda</label>
            <input v-model="regional.currency" class="form-control" />
          </div>
          <div class="col-md-3">
            <label class="form-label fw-medium">Símbolo</label>
            <input v-model="regional.currencySymbol" class="form-control" />
          </div>
          <div class="col-md-3">
            <label class="form-label fw-medium">Impuesto</label>
            <select v-model="regional.taxEnabled" class="form-select">
              <option :value="true">Habilitado</option>
              <option :value="false">Deshabilitado</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label fw-medium">Nombre Impuesto</label>
            <input v-model="regional.taxName" class="form-control" />
          </div>
          <div class="col-md-3">
            <label class="form-label fw-medium">Tasa %</label>
            <input v-model.number="regional.taxRate" class="form-control" type="number" min="0" step="0.01" />
          </div>
          <div class="col-md-3">
            <label class="form-label fw-medium">Días vencimiento (default)</label>
            <input v-model.number="regional.invoiceDueDaysDefault" class="form-control" type="number" min="0" step="1" />
          </div>
          <div class="col-12 text-end">
            <button class="btn btn-dark rounded-pill px-4" type="button" @click="saveRegional">Guardar</button>
          </div>
        </div>

        <div v-if="tab === 'deviceTypes'" class="d-flex flex-column gap-3">
          <div class="border rounded-4 p-3 bg-light">
            <div class="fw-semibold mb-2">Nuevo tipo de dispositivo</div>
            <div class="row g-2 align-items-end">
              <div class="col-md-6">
                <label class="form-label fw-medium">Nombre <span class="text-danger">*</span></label>
                <input v-model="newDeviceTypeName" class="form-control" />
              </div>
              <div class="col-md-2">
                <label class="form-label fw-medium">Orden</label>
                <input v-model.number="newDeviceTypeSort" class="form-control" type="number" />
              </div>
              <div class="col-md-2">
                <label class="form-label fw-medium">Activo</label>
                <select v-model="newDeviceTypeActive" class="form-select">
                  <option :value="true">Sí</option>
                  <option :value="false">No</option>
                </select>
              </div>
              <div class="col-md-2 text-end">
                <button class="btn btn-dark rounded-pill px-4" type="button" @click="addDeviceType">Crear</button>
              </div>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Nombre</th>
                  <th>Orden</th>
                  <th>Activo</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="t in deviceTypes" :key="t.id">
                  <td>{{ t.id }}</td>
                  <td class="fw-semibold">{{ t.name }}</td>
                  <td>{{ t.sortOrder }}</td>
                  <td>{{ t.isActive ? 'Sí' : 'No' }}</td>
                  <td class="text-end">
                    <div class="d-flex justify-content-end gap-2 flex-wrap">
                      <button class="btn btn-sm btn-outline-secondary rounded-pill" type="button" @click="editDeviceType(t)">Editar</button>
                      <button class="btn btn-sm btn-outline-warning rounded-pill" type="button" @click="toggleDeviceTypeActive(t)">
                        {{ t.isActive ? 'Desactivar' : 'Activar' }}
                      </button>
                      <button class="btn btn-sm btn-outline-danger rounded-pill" type="button" @click="deleteDeviceType(t)">Desactivar</button>
                    </div>
                  </td>
                </tr>
                <tr v-if="deviceTypes.length === 0">
                  <td colspan="5" class="text-secondary">Sin tipos</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-if="tab === 'brandsModels'" class="d-flex flex-column gap-3">
          <div class="row g-3">
            <div class="col-md-6">
              <div class="border rounded-4 p-3 bg-light h-100">
                <div class="fw-semibold mb-2">Nueva marca</div>
                <div class="row g-2 align-items-end">
                  <div class="col-md-7">
                    <label class="form-label fw-medium">Nombre <span class="text-danger">*</span></label>
                    <input v-model="newBrandName" class="form-control" />
                  </div>
                  <div class="col-md-3">
                    <label class="form-label fw-medium">Activo</label>
                    <select v-model="newBrandActive" class="form-select">
                      <option :value="true">Sí</option>
                      <option :value="false">No</option>
                    </select>
                  </div>
                  <div class="col-md-2 text-end">
                    <button class="btn btn-dark rounded-pill px-4" type="button" @click="addBrand">Crear</button>
                  </div>
                </div>

                <div class="table-responsive mt-3">
                  <table class="table align-middle mb-0">
                    <thead>
                      <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Activo</th>
                        <th></th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="b in brands" :key="b.id">
                        <td>{{ b.id }}</td>
                        <td class="fw-semibold">{{ b.name }}</td>
                        <td>{{ b.isActive ? 'Sí' : 'No' }}</td>
                        <td class="text-end">
                          <div class="d-flex justify-content-end gap-2 flex-wrap">
                            <button class="btn btn-sm btn-outline-secondary rounded-pill" type="button" @click="editBrand(b)">Editar</button>
                            <button class="btn btn-sm btn-outline-warning rounded-pill" type="button" @click="toggleBrandActive(b)">
                              {{ b.isActive ? 'Desactivar' : 'Activar' }}
                            </button>
                            <button class="btn btn-sm btn-outline-danger rounded-pill" type="button" @click="deleteBrand(b)">Desactivar</button>
                          </div>
                        </td>
                      </tr>
                      <tr v-if="brands.length === 0">
                        <td colspan="4" class="text-secondary">Sin marcas</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <div class="col-md-6">
              <div class="border rounded-4 p-3 bg-light h-100">
                <div class="fw-semibold mb-2">Nuevo modelo</div>
                <div class="row g-2 align-items-end">
                  <div class="col-md-4">
                    <label class="form-label fw-medium">Nombre <span class="text-danger">*</span></label>
                    <input v-model="newModelName" class="form-control" />
                  </div>
                  <div class="col-md-4">
                    <label class="form-label fw-medium">Tipo</label>
                    <select v-model="newModelDeviceTypeId" class="form-select">
                      <option :value="null">-</option>
                      <option v-for="t in deviceTypes" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label fw-medium">Marca</label>
                    <select v-model="newModelBrandId" class="form-select">
                      <option :value="null">-</option>
                      <option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </select>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label fw-medium">Activo</label>
                    <select v-model="newModelActive" class="form-select">
                      <option :value="true">Sí</option>
                      <option :value="false">No</option>
                    </select>
                  </div>
                  <div class="col-12 text-end">
                    <button class="btn btn-dark rounded-pill px-4" type="button" @click="addModel">Crear</button>
                  </div>
                </div>

                <div class="table-responsive mt-3">
                  <table class="table align-middle mb-0">
                    <thead>
                      <tr>
                        <th>ID</th>
                        <th>Modelo</th>
                        <th>Marca</th>
                        <th>Tipo</th>
                        <th>Activo</th>
                        <th></th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="m in models" :key="m.id">
                        <td>{{ m.id }}</td>
                        <td class="fw-semibold">{{ m.name }}</td>
                        <td>
                          {{
                            m.brandId ? (brands.find((b) => b.id === m.brandId)?.name || `#${m.brandId}`) : '-'
                          }}
                        </td>
                        <td>
                          {{
                            m.deviceTypeId
                              ? (deviceTypes.find((t) => t.id === m.deviceTypeId)?.name || `#${m.deviceTypeId}`)
                              : '-'
                          }}
                        </td>
                        <td>{{ m.isActive ? 'Sí' : 'No' }}</td>
                        <td class="text-end">
                          <div class="d-flex justify-content-end gap-2 flex-wrap">
                            <button class="btn btn-sm btn-outline-secondary rounded-pill" type="button" @click="editModel(m)">Editar</button>
                            <button class="btn btn-sm btn-outline-warning rounded-pill" type="button" @click="toggleModelActive(m)">
                              {{ m.isActive ? 'Desactivar' : 'Activar' }}
                            </button>
                            <button class="btn btn-sm btn-outline-danger rounded-pill" type="button" @click="deleteModel(m)">Desactivar</button>
                          </div>
                        </td>
                      </tr>
                      <tr v-if="models.length === 0">
                        <td colspan="6" class="text-secondary">Sin modelos</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div v-if="tab === 'payments'" class="d-flex flex-column gap-3">
          <div class="d-flex gap-2 align-items-center flex-wrap">
            <input v-model="newMethodName" class="form-control" style="max-width: 320px" placeholder="Nuevo método (Ej: Nequi)" />
            <button class="btn btn-outline-dark rounded-pill" type="button" @click="addPaymentMethod">Agregar</button>
          </div>
          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Nombre</th>
                  <th>Default</th>
                  <th>Activo</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="m in methods" :key="m.id">
                  <td>{{ m.id }}</td>
                  <td class="fw-semibold">{{ m.name }}</td>
                  <td>
                    <span class="badge rounded-pill" :class="m.isDefault ? 'text-bg-success' : 'text-bg-secondary'">
                      {{ m.isDefault ? 'Sí' : 'No' }}
                    </span>
                  </td>
                  <td>{{ m.isActive ? 'Sí' : 'No' }}</td>
                  <td class="text-end">
                    <div class="d-flex justify-content-end gap-2 flex-wrap">
                      <button class="btn btn-sm btn-outline-secondary rounded-pill" type="button" @click="selectMethod(m)">
                        Cuentas
                      </button>
                      <button class="btn btn-sm btn-outline-success rounded-pill" type="button" :disabled="m.isDefault" @click="setDefault(m)">
                        Hacer Default
                      </button>
                      <button class="btn btn-sm btn-outline-danger rounded-pill" type="button" @click="toggleActive(m)">
                        {{ m.isActive ? 'Desactivar' : 'Activar' }}
                      </button>
                    </div>
                  </td>
                </tr>
                <tr v-if="methods.length === 0">
                  <td colspan="5" class="text-secondary">Sin métodos</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div v-if="selectedMethodId" class="border rounded-4 p-3 bg-light">
            <div class="fw-semibold mb-2">Cuentas del método #{{ selectedMethodId }}</div>

            <div class="row g-2 align-items-end">
              <div class="col-md-3">
                <label class="form-label fw-medium">Alias <span class="text-danger">*</span></label>
                <input v-model="newAccAlias" class="form-control" />
              </div>
              <div class="col-md-3">
                <label class="form-label fw-medium">Número</label>
                <input v-model="newAccNumber" class="form-control" />
              </div>
              <div class="col-md-2">
                <label class="form-label fw-medium">Tipo</label>
                <input v-model="newAccType" class="form-control" />
              </div>
              <div class="col-md-2">
                <label class="form-label fw-medium">Titular</label>
                <input v-model="newAccHolder" class="form-control" />
              </div>
              <div class="col-md-1">
                <label class="form-label fw-medium">ID</label>
                <input v-model="newAccHolderId" class="form-control" />
              </div>
              <div class="col-md-1">
                <label class="form-label fw-medium">Activo</label>
                <select v-model="newAccActive" class="form-select">
                  <option :value="true">Sí</option>
                  <option :value="false">No</option>
                </select>
              </div>
              <div class="col-12 text-end">
                <button class="btn btn-outline-dark rounded-pill" type="button" @click="addAccount">Agregar cuenta</button>
              </div>
            </div>

            <div class="table-responsive mt-3">
              <table class="table align-middle mb-0">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Alias</th>
                    <th>Número</th>
                    <th>Tipo</th>
                    <th>Titular</th>
                    <th>Activo</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="a in accounts" :key="a.id">
                    <td>{{ a.id }}</td>
                    <td class="fw-semibold">{{ a.alias }}</td>
                    <td>{{ a.accountNumber }}</td>
                    <td>{{ a.accountType }}</td>
                    <td>{{ a.holderName }}</td>
                    <td>{{ a.isActive ? 'Sí' : 'No' }}</td>
                    <td class="text-end">
                      <div class="d-flex justify-content-end gap-2 flex-wrap">
                        <button class="btn btn-sm btn-outline-secondary rounded-pill" type="button" @click="editAccount(a)">Editar</button>
                        <button class="btn btn-sm btn-outline-warning rounded-pill" type="button" @click="toggleAccountActive(a)">
                          {{ a.isActive ? 'Desactivar' : 'Activar' }}
                        </button>
                        <button class="btn btn-sm btn-outline-danger rounded-pill" type="button" @click="deleteAccount(a)">Desactivar</button>
                      </div>
                    </td>
                  </tr>
                  <tr v-if="accounts.length === 0">
                    <td colspan="7" class="text-secondary">Sin cuentas</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div v-if="tab === 'whatsapp'" class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-medium">Recepción</label>
            <textarea v-model="whatsapp.reception" class="form-control" rows="4"></textarea>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-medium">Listo</label>
            <textarea v-model="whatsapp.ready" class="form-control" rows="4"></textarea>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-medium">Entrega</label>
            <textarea v-model="whatsapp.delivery" class="form-control" rows="4"></textarea>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-medium">Venta</label>
            <textarea v-model="whatsapp.sale" class="form-control" rows="4"></textarea>
          </div>
          <div class="col-12 text-end">
            <button class="btn btn-dark rounded-pill px-4" type="button" @click="saveWhatsapp">Guardar</button>
          </div>
        </div>

        <div v-if="tab === 'appearance'" class="row g-3">
          <div class="col-md-4">
            <label class="form-label fw-medium">Modo</label>
            <select v-model="appearance.themeMode" class="form-select">
              <option value="light">Claro</option>
              <option value="dark">Oscuro</option>
            </select>
          </div>
          <div class="col-12 text-end">
            <button class="btn btn-dark rounded-pill px-4" type="button" @click="saveAppearance">Guardar</button>
          </div>
        </div>

        <div v-if="tab === 'portal'" class="d-flex flex-column gap-3">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label fw-medium">Búsqueda por ID</label>
              <select v-model="portal.enableLookupById" class="form-select">
                <option :value="true">Habilitado</option>
                <option :value="false">Deshabilitado</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Timeline</label>
              <select v-model="portal.showTimeline" class="form-select">
                <option :value="true">Mostrar</option>
                <option :value="false">Ocultar</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-medium">Aprobación</label>
              <select v-model="portal.allowApproval" class="form-select">
                <option :value="true">Permitir</option>
                <option :value="false">No permitir</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Título</label>
              <input v-model="portal.homeTitle" class="form-control" />
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Subtítulo</label>
              <input v-model="portal.homeSubtitle" class="form-control" />
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">WhatsApp Link</label>
              <input v-model="portal.whatsappLink" class="form-control" placeholder="https://wa.me/..." />
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Dirección</label>
              <input v-model="portal.addressText" class="form-control" />
            </div>
            <div class="col-md-6">
              <label class="form-label fw-medium">Horarios</label>
              <input v-model="portal.hoursText" class="form-control" />
            </div>
            <div class="col-md-12">
              <label class="form-label fw-medium">Mapa (embed)</label>
              <textarea v-model="portal.mapEmbedUrl" class="form-control" rows="3"></textarea>
            </div>
            <div class="col-12 text-end">
              <button class="btn btn-dark rounded-pill px-4" type="button" @click="savePortal">Guardar</button>
            </div>
          </div>
        </div>

        <div v-if="tab === 'users'" class="d-flex flex-column gap-3">
          <div class="border rounded-4 p-3 bg-light">
            <div class="fw-semibold mb-2">Nuevo usuario</div>
            <div class="row g-2 align-items-end">
              <div class="col-md-3">
                <label class="form-label fw-medium">Email</label>
                <input v-model="newUserEmail" class="form-control" />
              </div>
              <div class="col-md-3">
                <label class="form-label fw-medium">Nombre</label>
                <input v-model="newUserName" class="form-control" />
              </div>
              <div class="col-md-2">
                <label class="form-label fw-medium">Rol</label>
                <select v-model="newUserRole" class="form-select">
                  <option value="user">Usuario</option>
                  <option value="admin">Admin</option>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label fw-medium">Activo</label>
                <select v-model="newUserActive" class="form-select">
                  <option :value="true">Sí</option>
                  <option :value="false">No</option>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label fw-medium">Password</label>
                <input v-model="newUserPassword" class="form-control" type="password" />
              </div>
              <div class="col-12 text-end">
                <button class="btn btn-dark rounded-pill px-4" type="button" @click="createUser">Crear</button>
              </div>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Email</th>
                  <th>Nombre</th>
                  <th>Rol</th>
                  <th>Activo</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="u in users" :key="u.id">
                  <td>{{ u.id }}</td>
                  <td class="fw-semibold">{{ u.email }}</td>
                  <td>{{ u.name }}</td>
                  <td>
                    <span class="badge rounded-pill" :class="u.role === 'admin' ? 'text-bg-primary' : 'text-bg-secondary'">
                      {{ u.role === 'admin' ? 'Admin' : 'Usuario' }}
                    </span>
                  </td>
                  <td>{{ u.active ? 'Sí' : 'No' }}</td>
                  <td class="text-end">
                    <div class="d-flex justify-content-end gap-2 flex-wrap">
                      <button class="btn btn-sm btn-outline-secondary rounded-pill" type="button" @click="editUser(u)">Editar</button>
                      <button class="btn btn-sm btn-outline-dark rounded-pill" type="button" @click="resetPassword(u)">Password</button>
                      <button class="btn btn-sm btn-outline-success rounded-pill" type="button" @click="toggleUserRole(u)">
                        {{ u.role === 'admin' ? 'Hacer Usuario' : 'Hacer Admin' }}
                      </button>
                      <button class="btn btn-sm btn-outline-warning rounded-pill" type="button" @click="toggleUserActive(u)">
                        {{ u.active ? 'Desactivar' : 'Activar' }}
                      </button>
                      <button class="btn btn-sm btn-outline-danger rounded-pill" type="button" @click="deleteUser(u)">Eliminar</button>
                    </div>
                  </td>
                </tr>
                <tr v-if="users.length === 0">
                  <td colspan="6" class="text-secondary">Sin usuarios</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
