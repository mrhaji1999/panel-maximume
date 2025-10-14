import { create } from 'zustand'
import { persist } from 'zustand/middleware'

interface UIState {
  theme: 'light' | 'dark'
  sidebarOpen: boolean
  mobileMenuOpen: boolean
  loading: boolean
  notifications: Notification[]
}

interface Notification {
  id: string
  type: 'success' | 'error' | 'warning' | 'info'
  title: string
  message: string
  duration?: number
  timestamp: number
}

interface UIActions {
  setTheme: (theme: 'light' | 'dark') => void
  toggleTheme: () => void
  setSidebarOpen: (open: boolean) => void
  toggleSidebar: () => void
  setMobileMenuOpen: (open: boolean) => void
  toggleMobileMenu: () => void
  setLoading: (loading: boolean) => void
  addNotification: (notification: Omit<Notification, 'id' | 'timestamp'>) => void
  removeNotification: (id: string) => void
  clearNotifications: () => void
}

type UIStore = UIState & UIActions

export const useUIStore = create<UIStore>()(
  persist(
    (set, get) => ({
      // Initial state
      theme: 'light',
      sidebarOpen: true,
      mobileMenuOpen: false,
      loading: false,
      notifications: [],

      // Actions
      setTheme: (theme: 'light' | 'dark') => {
        set({ theme })
        // Apply theme to document
        document.documentElement.classList.toggle('dark', theme === 'dark')
      },

      toggleTheme: () => {
        const { theme } = get()
        const newTheme = theme === 'light' ? 'dark' : 'light'
        get().setTheme(newTheme)
      },

      setSidebarOpen: (sidebarOpen: boolean) => {
        set({ sidebarOpen })
      },

      toggleSidebar: () => {
        const { sidebarOpen } = get()
        set({ sidebarOpen: !sidebarOpen })
      },

      setMobileMenuOpen: (mobileMenuOpen: boolean) => {
        set({ mobileMenuOpen })
      },

      toggleMobileMenu: () => {
        const { mobileMenuOpen } = get()
        set({ mobileMenuOpen: !mobileMenuOpen })
      },

      setLoading: (loading: boolean) => {
        set({ loading })
      },

      addNotification: (notification) => {
        const id = Math.random().toString(36).substr(2, 9)
        const timestamp = Date.now()
        
        set((state) => ({
          notifications: [
            ...state.notifications,
            { ...notification, id, timestamp }
          ]
        }))

        // Auto remove notification after duration
        const duration = notification.duration || 5000
        setTimeout(() => {
          get().removeNotification(id)
        }, duration)
      },

      removeNotification: (id: string) => {
        set((state) => ({
          notifications: state.notifications.filter(n => n.id !== id)
        }))
      },

      clearNotifications: () => {
        set({ notifications: [] })
      },
    }),
    {
      name: 'ui-storage',
      partialize: (state) => ({
        theme: state.theme,
        sidebarOpen: state.sidebarOpen,
      }),
    }
  )
)

// Selectors
export const useTheme = () => useUIStore((state) => state.theme)
export const useSidebar = () => useUIStore((state) => ({
  sidebarOpen: state.sidebarOpen,
  setSidebarOpen: state.setSidebarOpen,
  toggleSidebar: state.toggleSidebar,
}))
export const useMobileMenu = () => useUIStore((state) => ({
  mobileMenuOpen: state.mobileMenuOpen,
  setMobileMenuOpen: state.setMobileMenuOpen,
  toggleMobileMenu: state.toggleMobileMenu,
}))
export const useLoading = () => useUIStore((state) => ({
  loading: state.loading,
  setLoading: state.setLoading,
}))
export const useNotifications = () => useUIStore((state) => ({
  notifications: state.notifications,
  addNotification: state.addNotification,
  removeNotification: state.removeNotification,
  clearNotifications: state.clearNotifications,
}))

// Notification helpers
export const useNotification = () => {
  const { addNotification } = useNotifications()
  
  return {
    success: (title: string, message: string, duration?: number) =>
      addNotification({ type: 'success', title, message, duration }),
    
    error: (title: string, message: string, duration?: number) =>
      addNotification({ type: 'error', title, message, duration }),
    
    warning: (title: string, message: string, duration?: number) =>
      addNotification({ type: 'warning', title, message, duration }),
    
    info: (title: string, message: string, duration?: number) =>
      addNotification({ type: 'info', title, message, duration }),
  }
}
