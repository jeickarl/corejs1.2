export type ApiOk<T> = {
  ok: true
  data: T
}

export type ApiError = {
  ok: false
  error: {
    code: string
    message: string
  }
}

export type ApiResponse<T> = ApiOk<T> | ApiError

export type UserRole = 'SUPER_ADMIN' | 'ADMIN' | 'USER'

export type MeDto = {
  id: number
  email: string
  name: string
  photoUrl: string
  role: UserRole
  tenantId: number | null
}

export type TenantDto = {
  id: number
  companyName: string
  status: 'active' | 'suspended'
  createdAt: string
}

export type TenantDetailDto = TenantDto & {
  dbHost?: string
  dbPort?: number
  dbName?: string
  dbUser?: string
  licenseCount?: number
  lastLicense?: string | null
}

export type MasterUserDto = {
  id: number
  email: string
  name: string
  role: string
  active: boolean
}
