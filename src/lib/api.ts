import axios, { AxiosInstance, AxiosRequestConfig, AxiosResponse } from 'axios'
import {
  ApiResponse,
  ApiError,
  CustomerListResponse,
  CustomerTabsResponse,
  CustomerDetail,
  CardFieldsResponse,
  SMSRequest,
  SupervisorListResponse,
  SupervisorFilters,
  SupervisorCardsResponse,
  AgentListResponse,
  AgentFilters,
  CardListResponse,
  ReservationListResponse,
  ReservationFilters,
  FormListResponse,
  FormFilters,
  SMSLogListResponse,
  SMSFilters,
  SMSStats,
  ScheduleResponse,
  AvailabilityResponse,
  DashboardSummary,
} from '@/types'

// API Configuration
const normalizeBaseUrl = (value: string) => value.replace(/\/$/, '')

const resolveDefaultBaseUrl = () =>
  `${normalizeBaseUrl('https://maximum.stoor.ir')}/wp-json/user-cards-bridge/v1`

const API_BASE_URL = normalizeBaseUrl(
  import.meta.env.VITE_API_BASE_URL || resolveDefaultBaseUrl()
)

// Create axios instance
const api: AxiosInstance = axios.create({
  baseURL: API_BASE_URL,
  timeout: 30000,
  headers: {
    'Content-Type': 'application/json',
  },
})

// Request interceptor to add auth token
api.interceptors.request.use(
  (config) => {
    const resolveTokenFromStorage = (): string | null => {
      const rawToken = localStorage.getItem('token')

      const normalizeToken = (value: unknown): string | null => {
        if (typeof value === 'string' && value.trim() !== '') {
          return value
        }

        if (value && typeof value === 'object') {
          const tokenField =
            (value as Record<string, unknown>).token ??
            (value as Record<string, unknown>).access_token ??
            (value as Record<string, unknown>).jwt

          if (typeof tokenField === 'string' && tokenField.trim() !== '') {
            return tokenField
          }
        }

        return null
      }

      if (rawToken) {
        try {
          return normalizeToken(JSON.parse(rawToken))
        } catch {
          return normalizeToken(rawToken)
        }
      }

      // Fallback to zustand persisted state in case token key is missing
      const rawPersistedState = localStorage.getItem('auth-storage')
      if (rawPersistedState) {
        try {
          const parsed = JSON.parse(rawPersistedState)
          return normalizeToken(parsed?.state?.token ?? null)
        } catch {
          return null
        }
      }

      return null
    }

    const token = resolveTokenFromStorage()
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }

    return config
  },
  (error) => {
    return Promise.reject(error)
  }
)

// Response interceptor for error handling
api.interceptors.response.use(
  (response: AxiosResponse<ApiResponse>) => {
    return response
  },
  (error) => {
    // Handle unauthorized responses
    if (error.response?.status === 401) {
      // Clear token and redirect to login
      localStorage.removeItem('token')
      localStorage.removeItem('user')
      window.location.href = '/login'
      return Promise.reject(error)
    }

    // Transform error response to our format
    const apiError: ApiError = {
      success: false,
      data: null,
      error: {
        code: error.response?.data?.error?.code || 'UNKNOWN_ERROR',
        message: error.response?.data?.error?.message || error.message || 'خطای نامشخص',
        status: error.response?.status || 500,
      },
    }

    return Promise.reject(apiError)
  }
)

// Generic API methods
export const apiClient = {
  get: <T = any>(url: string, config?: AxiosRequestConfig): Promise<ApiResponse<T>> =>
    api.get(url, config).then(res => res.data),
  
  post: <T = any>(url: string, data?: any, config?: AxiosRequestConfig): Promise<ApiResponse<T>> =>
    api.post(url, data, config).then(res => res.data),
  
  put: <T = any>(url: string, data?: any, config?: AxiosRequestConfig): Promise<ApiResponse<T>> =>
    api.put(url, data, config).then(res => res.data),
  
  patch: <T = any>(url: string, data?: any, config?: AxiosRequestConfig): Promise<ApiResponse<T>> =>
    api.patch(url, data, config).then(res => res.data),
  
  delete: <T = any>(url: string, config?: AxiosRequestConfig): Promise<ApiResponse<T>> =>
    api.delete(url, config).then(res => res.data),
}

// Auth API
export const authApi = {
  login: (credentials: { username: string; password: string; role?: string }) =>
    apiClient.post('/auth/login', credentials),
  
  register: (userData: any) =>
    apiClient.post('/auth/register', userData),
  
  logout: () =>
    apiClient.post('/auth/logout'),
}

// Users API
export const usersApi = {
  getManagers: () =>
    apiClient.get('/managers'),
  
  getSupervisors: (filters?: SupervisorFilters) =>
    apiClient.get<SupervisorListResponse>('/supervisors', { params: filters }),
  
  getSupervisor: (id: number) =>
    apiClient.get(`/supervisors/${id}`),
  
  getSupervisorCards: (id: number) =>
    apiClient.get(`/supervisors/${id}/cards`),
  
  assignSupervisorCards: (id: number, cardIds: number[], setDefault = false) =>
    apiClient.post(`/supervisors/${id}/cards`, { cards: cardIds, set_default: setDefault ? 1 : 0 }),
  
  getAgents: (filters?: AgentFilters) =>
    apiClient.get<AgentListResponse>('/agents', { params: filters }),
  
  getAgent: (id: number) =>
    apiClient.get(`/agents/${id}`),
  
  createAgent: (agentData: any) =>
    apiClient.post('/agents', agentData),
  
  updateAgentSupervisor: (id: number, supervisorId: number) =>
    apiClient.patch(`/agents/${id}/supervisor`, { supervisor_id: supervisorId }),
}

// Customers API
export const customersApi = {
  getCustomers: (filters?: any) =>
    apiClient.get<CustomerListResponse>('/customers', { params: filters }),
  
  getCustomerTabs: () =>
    apiClient.get<CustomerTabsResponse>('/customers/tabs'),
  
  getCustomer: (id: number) =>
    apiClient.get<CustomerDetail>(`/customers/${id}`),
  
  updateCustomerStatus: (id: number, status: string, reason?: string) =>
    apiClient.patch(`/customers/${id}/status`, { status, reason }),
  
  addCustomerNote: (id: number, note: string) =>
    apiClient.post(`/customers/${id}/notes`, { note }),
  
  assignSupervisor: (id: number, supervisorId: number) =>
    apiClient.post(`/customers/${id}/assign-supervisor`, { supervisor_id: supervisorId }),
  
  assignAgent: (id: number, agentId: number) =>
    apiClient.post(`/customers/${id}/assign-agent`, { agent_id: agentId }),
  
  sendNormalCode: (id: number) =>
    apiClient.post(`/customers/${id}/normal/send-code`),
  
  initUpsell: (id: number, cardId: number, fieldKey: string) =>
    apiClient.post(`/customers/${id}/upsell/init`, { card_id: cardId, field_key: fieldKey }),
}

// Cards API
export const cardsApi = {
  getCards: (filters?: any) =>
    apiClient.get<CardListResponse>('/cards', { params: filters }),
  
  getCard: (id: number) =>
    apiClient.get(`/cards/${id}`),
  
  getCardFields: (id: number) =>
    apiClient.get<CardFieldsResponse>(`/cards/${id}/fields`),

  getSupervisorCards: (supervisorId: number) =>
    apiClient.get<SupervisorCardsResponse>(`/supervisors/${supervisorId}/cards`),
}

// Forms API
export const formsApi = {
  getForms: (filters?: FormFilters) =>
    apiClient.get<FormListResponse>('/forms', { params: filters }),

  getForm: (id: number) =>
    apiClient.get(`/forms/${id}`),
}

// Schedule API
export const scheduleApi = {
  getSchedule: (supervisorId: number, cardId: number) =>
    apiClient.get<ScheduleResponse>(`/schedule/${supervisorId}/${cardId}`),
  
  updateSchedule: (supervisorId: number, cardId: number, matrix: any[]) =>
    apiClient.put<ScheduleResponse>(`/schedule/${supervisorId}/${cardId}`, { matrix }),
  
  getAvailability: (cardId: number, supervisorId?: number) =>
    apiClient.get<AvailabilityResponse>(`/availability/${cardId}`, { params: { supervisor_id: supervisorId } }),
}

// Reservations API
export const reservationsApi = {
  getReservations: (filters?: ReservationFilters) =>
    apiClient.get<ReservationListResponse>('/reservations', { params: filters }),
  
  getReservation: (id: number) =>
    apiClient.get(`/reservations/${id}`),
  
  createReservation: (reservationData: any) =>
    apiClient.post('/reservations', reservationData),
  
  cancelReservation: (id: number) =>
    apiClient.delete(`/reservations/${id}`),
}

// SMS API
export const smsApi = {
  sendSMS: (smsData: SMSRequest) =>
    apiClient.post('/sms/send', smsData),
  
  getSMSLogs: (filters?: SMSFilters) =>
    apiClient.get<SMSLogListResponse>('/sms/logs', { params: filters }),
  
  getSMSStatistics: (params?: { days?: number }) =>
    apiClient.get<SMSStats>('/sms/statistics', { params }),
  
  testSMS: () =>
    apiClient.post('/sms/test'),
}

// Dashboard API
export const statsApi = {
  getSummary: (params?: { days?: number; activity?: number }) =>
    apiClient.get<DashboardSummary>('/dashboard/summary', { params }),
}

export default api
