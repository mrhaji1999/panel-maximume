import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import { User, UserRole } from '@/types'
import { authApi } from '@/lib/api'
import { getStorageItem, setStorageItem, removeStorageItem } from '@/lib/utils'

interface AuthState {
  user: User | null
  token: string | null
  isAuthenticated: boolean
  isLoading: boolean
  error: string | null
}

interface AuthActions {
  login: (username: string, password: string, role?: UserRole) => Promise<void>
  register: (userData: any) => Promise<void>
  logout: () => void
  setUser: (user: User) => void
  setToken: (token: string) => void
  setLoading: (loading: boolean) => void
  setError: (error: string | null) => void
  clearError: () => void
  checkAuth: () => void
}

type AuthStore = AuthState & AuthActions

export const useAuthStore = create<AuthStore>()(
  persist(
    (set) => ({
      // Initial state
      user: null,
      token: null,
      isAuthenticated: false,
      isLoading: false,
      error: null,

      // Actions
      login: async (username: string, password: string, role?: UserRole) => {
        set({ isLoading: true, error: null })
        
        try {
          const response = await authApi.login({ username, password, role })
          
          if (response.success) {
            const { token, user } = response.data
            
            // Store in localStorage
            setStorageItem('token', token)
            setStorageItem('user', user)
            
            set({
              user,
              token,
              isAuthenticated: true,
              isLoading: false,
              error: null,
            })
          } else {
            throw new Error('ورود ناموفق')
          }
        } catch (error: any) {
          const errorMessage = error?.error?.message || error?.message || 'خطا در ورود'
          set({
            error: errorMessage,
            isLoading: false,
            isAuthenticated: false,
            user: null,
            token: null,
          })
          throw error
        }
      },

      register: async (userData: any) => {
        set({ isLoading: true, error: null })
        
        try {
          const response = await authApi.register(userData)
          
          if (response.success) {
            const { token, user } = response.data
            
            // Store in localStorage
            setStorageItem('token', token)
            setStorageItem('user', user)
            
            set({
              user,
              token,
              isAuthenticated: true,
              isLoading: false,
              error: null,
            })
          } else {
            throw new Error('ثبت‌نام ناموفق')
          }
        } catch (error: any) {
          const errorMessage = error?.error?.message || error?.message || 'خطا در ثبت‌نام'
          set({
            error: errorMessage,
            isLoading: false,
            isAuthenticated: false,
            user: null,
            token: null,
          })
          throw error
        }
      },

      logout: () => {
        // Clear localStorage
        removeStorageItem('token')
        removeStorageItem('user')
        
        // Reset state
        set({
          user: null,
          token: null,
          isAuthenticated: false,
          isLoading: false,
          error: null,
        })
      },

      setUser: (user: User) => {
        set({ user })
        setStorageItem('user', user)
      },

      setToken: (token: string) => {
        set({ token })
        setStorageItem('token', token)
      },

      setLoading: (isLoading: boolean) => {
        set({ isLoading })
      },

      setError: (error: string | null) => {
        set({ error })
      },

      clearError: () => {
        set({ error: null })
      },

      checkAuth: () => {
        const token = getStorageItem('token', null)
        const user = getStorageItem('user', null)
        
        if (token && user) {
          set({
            token,
            user,
            isAuthenticated: true,
          })
        } else {
          set({
            token: null,
            user: null,
            isAuthenticated: false,
          })
        }
      },
    }),
    {
      name: 'auth-storage',
      partialize: (state) => ({
        user: state.user,
        token: state.token,
        isAuthenticated: state.isAuthenticated,
      }),
    }
  )
)

// Selectors
export const useAuth = () => useAuthStore((state) => ({
  user: state.user,
  token: state.token,
  isAuthenticated: state.isAuthenticated,
  isLoading: state.isLoading,
  error: state.error,
}))

export const useAuthActions = () => useAuthStore((state) => ({
  login: state.login,
  register: state.register,
  logout: state.logout,
  setUser: state.setUser,
  setToken: state.setToken,
  setLoading: state.setLoading,
  setError: state.setError,
  clearError: state.clearError,
  checkAuth: state.checkAuth,
}))

// Role-based selectors
export const useIsManager = () => useAuthStore((state) => state.user?.role === 'company_manager')
export const useIsSupervisor = () => useAuthStore((state) => state.user?.role === 'supervisor')
export const useIsAgent = () => useAuthStore((state) => state.user?.role === 'agent')

export const useCanAccess = (requiredRole: UserRole | UserRole[]) => {
  return useAuthStore((state) => {
    if (!state.user) return false
    
    const userRole = state.user.role
    const roles = Array.isArray(requiredRole) ? requiredRole : [requiredRole]
    
    // Manager has access to everything
    if (userRole === 'company_manager') return true
    
    // Check if user role is in required roles
    return roles.includes(userRole)
  })
}
