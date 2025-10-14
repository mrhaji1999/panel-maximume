import { useEffect, type ReactNode } from 'react'
import { Routes, Route, Navigate } from 'react-router-dom'
import { useAuth, useAuthActions } from '@/store/authStore'
import { useTheme } from '@/store/uiStore'
import type { UserRole } from '@/types'
import { Sidebar } from '@/components/layout/sidebar'
import { Header } from '@/components/layout/header'
import { Notifications } from '@/components/layout/notifications'

// Auth pages
import { LoginPage } from '@/pages/auth/login'
import { RegisterPage } from '@/pages/auth/register'

// Feature pages
import { DashboardPage } from '@/pages/dashboard'
import { CustomersPage } from '@/pages/customers'
import { SupervisorsPage } from '@/pages/supervisors'
import { AgentsPage } from '@/pages/agents'
import { MyCardsPage } from '@/pages/my-cards'
import { MyAgentsPage } from '@/pages/my-agents'
import { MyCustomersPage } from '@/pages/my-customers'
import { AssignedCustomersPage } from '@/pages/assigned-customers'
import { FormsPage } from '@/pages/forms'
import { SchedulePage } from '@/pages/schedule'
import { ReservationsPage } from '@/pages/reservations'
import { SMSPage } from '@/pages/sms'
import { SettingsPage } from '@/pages/settings'

interface ProtectedRouteProps {
  children: ReactNode
  allowedRoles?: UserRole[]
}

function ProtectedRoute({ children, allowedRoles }: ProtectedRouteProps) {
  const { isAuthenticated, isLoading, user } = useAuth()

  if (isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <div className="h-16 w-16 animate-spin rounded-full border-b-2 border-primary" />
      </div>
    )
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />
  }

  if (allowedRoles && user) {
    const hasRole =
      allowedRoles.includes(user.role) || user.role === 'company_manager'
    if (!hasRole) {
      return <Navigate to="/dashboard" replace />
    }
  }

  return <>{children}</>
}

function PublicRoute({ children }: { children: ReactNode }) {
  const { isAuthenticated, isLoading } = useAuth()

  if (isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <div className="h-16 w-16 animate-spin rounded-full border-b-2 border-primary" />
      </div>
    )
  }

  if (isAuthenticated) {
    return <Navigate to="/dashboard" replace />
  }

  return <>{children}</>
}

function MainLayout({ children }: { children: ReactNode }) {
  return (
    <div className="min-h-screen bg-background lg:flex lg:flex-row-reverse">
      <Sidebar />
      <div className="flex-1 lg:pr-64">
        <Header />
        <main className="p-6">{children}</main>
      </div>
    </div>
  )
}

export default function App() {
  const { checkAuth } = useAuthActions()
  const theme = useTheme()

  useEffect(() => {
    checkAuth()
  }, [checkAuth])

  useEffect(() => {
    document.documentElement.classList.toggle('dark', theme === 'dark')
  }, [theme])

  return (
    <>
      <Routes>
        <Route
          path="/login"
          element={
            <PublicRoute>
              <LoginPage />
            </PublicRoute>
          }
        />
        <Route
          path="/register"
          element={
            <PublicRoute>
              <RegisterPage />
            </PublicRoute>
          }
        />

        <Route
          path="/"
          element={
            <ProtectedRoute>
              <Navigate to="/dashboard" replace />
            </ProtectedRoute>
          }
        />
        <Route
          path="/dashboard"
          element={
            <ProtectedRoute>
              <MainLayout>
                <DashboardPage />
              </MainLayout>
            </ProtectedRoute>
          }
        />
        <Route
          path="/customers"
          element={
            <ProtectedRoute allowedRoles={['company_manager']}>
              <MainLayout>
                <CustomersPage />
              </MainLayout>
            </ProtectedRoute>
          }
        />
        <Route
          path="/supervisors"
          element={
            <ProtectedRoute allowedRoles={['company_manager']}>
              <MainLayout>
                <SupervisorsPage />
              </MainLayout>
            </ProtectedRoute>
          }
        />
        <Route
          path="/agents"
          element={
            <ProtectedRoute allowedRoles={['company_manager', 'supervisor']}>
              <MainLayout>
                <AgentsPage />
              </MainLayout>
            </ProtectedRoute>
          }
        />
        <Route
          path="/my-cards"
          element={
            <ProtectedRoute allowedRoles={['supervisor']}>
              <MainLayout>
                <MyCardsPage />
              </MainLayout>
            </ProtectedRoute>
          }
        />
        <Route
          path="/my-agents"
          element={
            <ProtectedRoute allowedRoles={['supervisor']}>
              <MainLayout>
                <MyAgentsPage />
              </MainLayout>
            </ProtectedRoute>
          }
        />
        <Route
          path="/my-customers"
          element={
            <ProtectedRoute allowedRoles={['supervisor']}>
              <MainLayout>
                <MyCustomersPage />
              </MainLayout>
            </ProtectedRoute>
          }
        />
        <Route
          path="/assigned-customers"
          element={
            <ProtectedRoute allowedRoles={['agent']}>
              <MainLayout>
                <AssignedCustomersPage />
              </MainLayout>
            </ProtectedRoute>
          }
        />
        <Route
          path="/forms"
          element={
            <ProtectedRoute>
              <MainLayout>
                <FormsPage />
              </MainLayout>
            </ProtectedRoute>
          }
        />
        <Route
          path="/schedule"
          element={
            <ProtectedRoute allowedRoles={['company_manager', 'supervisor']}>
              <MainLayout>
                <SchedulePage />
              </MainLayout>
            </ProtectedRoute>
          }
        />
        <Route
          path="/reservations"
          element={
            <ProtectedRoute allowedRoles={['company_manager', 'supervisor']}>
              <MainLayout>
                <ReservationsPage />
              </MainLayout>
            </ProtectedRoute>
          }
        />
        <Route
          path="/sms"
          element={
            <ProtectedRoute>
              <MainLayout>
                <SMSPage />
              </MainLayout>
            </ProtectedRoute>
          }
        />
        <Route
          path="/settings"
          element={
            <ProtectedRoute allowedRoles={['company_manager']}>
              <MainLayout>
                <SettingsPage />
              </MainLayout>
            </ProtectedRoute>
          }
        />

        <Route path="*" element={<Navigate to="/dashboard" replace />} />
      </Routes>

      <Notifications />
    </>
  )
}
