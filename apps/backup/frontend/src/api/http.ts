import type { ApiResponse } from '@corejs/shared/types'

const rawBaseUrl = (import.meta.env.VITE_API_BASE_URL as string | undefined) ?? ''
const baseUrl = rawBaseUrl.trim() ? rawBaseUrl.trim() : '/api'

function safeApiError(message: string, code = 'BAD_RESPONSE'): ApiResponse<never> {
  return { ok: false, error: { code, message } }
}

function isRecord(v: unknown): v is Record<string, unknown> {
  return typeof v === 'object' && v !== null
}

function normalizeUnknownErrorMessage(v: unknown): string {
  if (typeof v === 'string') return v
  if (Array.isArray(v)) return v.map((x) => (typeof x === 'string' ? x : '')).filter(Boolean).join(', ')
  return ''
}

function isApiResponseShape(v: unknown): v is ApiResponse<unknown> {
  if (!isRecord(v)) return false
  if (typeof v.ok !== 'boolean') return false
  if (v.ok) return 'data' in v
  if (!('error' in v)) return false
  const e = (v as { error?: unknown }).error
  if (!isRecord(e)) return false
  return typeof e.code === 'string' && typeof e.message === 'string'
}

async function parseApiResponse<T>(res: Response): Promise<ApiResponse<T>> {
  const text = await res.text()
  if (!text) {
    return safeApiError(`Respuesta vacía del servidor (HTTP ${res.status})`)
  }
  try {
    const parsed = JSON.parse(text) as unknown
    if (isApiResponseShape(parsed)) return parsed as ApiResponse<T>
    if (isRecord(parsed)) {
      const msg = normalizeUnknownErrorMessage(parsed.message)
      if (msg) return safeApiError(`${msg} (HTTP ${res.status})`, `HTTP_${res.status}`)
    }
    return safeApiError(`Respuesta inválida del servidor (HTTP ${res.status})`)
  } catch {
    const short = text.length > 200 ? `${text.slice(0, 200)}...` : text
    return safeApiError(`Respuesta inválida del servidor (${res.status}). ${short}`)
  }
}

export async function apiGet<T>(path: string): Promise<ApiResponse<T>> {
  try {
    const url = `${baseUrl}${path.startsWith('/') ? '' : '/'}${path}`
    const res = await fetch(url, { credentials: 'include' })
    return await parseApiResponse<T>(res)
  } catch {
    return safeApiError('No se pudo conectar a la API', 'UNAVAILABLE')
  }
}

export async function apiPost<T, B extends Record<string, unknown>>(
  path: string,
  body: B,
): Promise<ApiResponse<T>> {
  try {
    const url = `${baseUrl}${path.startsWith('/') ? '' : '/'}${path}`
    const res = await fetch(url, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    })
    return await parseApiResponse<T>(res)
  } catch {
    return safeApiError('No se pudo conectar a la API', 'UNAVAILABLE')
  }
}

export async function apiPatch<T, B extends Record<string, unknown>>(
  path: string,
  body: B,
): Promise<ApiResponse<T>> {
  try {
    const url = `${baseUrl}${path.startsWith('/') ? '' : '/'}${path}`
    const res = await fetch(url, {
      method: 'PATCH',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    })
    return await parseApiResponse<T>(res)
  } catch {
    return safeApiError('No se pudo conectar a la API', 'UNAVAILABLE')
  }
}

export async function apiDelete<T>(path: string): Promise<ApiResponse<T>> {
  try {
    const url = `${baseUrl}${path.startsWith('/') ? '' : '/'}${path}`
    const res = await fetch(url, { method: 'DELETE', credentials: 'include' })
    return await parseApiResponse<T>(res)
  } catch {
    return safeApiError('No se pudo conectar a la API', 'UNAVAILABLE')
  }
}
