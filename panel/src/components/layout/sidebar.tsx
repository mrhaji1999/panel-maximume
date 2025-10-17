import { Link, useLocation } from 'react-router-dom'
import type { Location } from 'react-router-dom'
import { cn } from '@/lib/utils'
import { useAuth, useAuthActions } from '@/store/authStore'
import { useSidebar } from '@/store/uiStore'
import type { UserRole } from '@/types'
import {
  LayoutDashboard,
  Users,
  UserCheck,
  UserCog,
  Calendar,
  FileText,
  MessageSquare,
  Settings,
  LogOut,
  X,
} from 'lucide-react'
import { Button } from '@/components/ui/button'

const navigation = [
  {
    name: 'داشبورد',
    href: '/dashboard',
    icon: LayoutDashboard,
    roles: ['company_manager', 'supervisor', 'agent'] as const,
  },
  {
    name: 'سرپرست‌ها',
    href: '/supervisors',
    icon: UserCheck,
    roles: ['company_manager'] as const,
  },
  {
    name: 'کارشناس‌ها',
    href: '/agents',
    icon: UserCog,
    roles: ['company_manager', 'supervisor'] as const,
  },
  {
    name: 'کارت‌های من',
    href: '/my-cards',
    icon: FileText,
    roles: ['supervisor'] as const,
  },
  {
    name: 'کارشناس‌های من',
    href: '/my-agents',
    icon: Users,
    roles: ['supervisor'] as const,
  },
  {
    name: 'مشتریان من',
    href: '/my-customers',
    icon: Users,
    roles: ['supervisor'] as const,
  },
  {
    name: 'مشتریان تخصیص‌یافته',
    href: '/assigned-customers',
    icon: Users,
    roles: ['agent'] as const,
  },
  {
    name: 'فرم‌ها',
    href: '/forms',
    icon: FileText,
    roles: ['company_manager', 'supervisor', 'agent'] as const,
  },
  {
    name: 'مشتریان',
    href: '/customers',
    icon: Users,
    roles: ['company_manager'] as const,
  },
  {
    name: 'خرید افزایشی - در انتظار پرداخت',
    href: '/customers',
    icon: Users,
    roles: ['company_manager'] as const,
    search: '?status=upsell_pending',
    match: (location: Location) =>
      location.pathname === '/customers' && new URLSearchParams(location.search).get('status') === 'upsell_pending',
  },
  {
    name: 'خرید افزایشی - پرداخت شده',
    href: '/customers',
    icon: Users,
    roles: ['company_manager'] as const,
    search: '?status=upsell_paid',
    match: (location: Location) =>
      location.pathname === '/customers' && new URLSearchParams(location.search).get('status') === 'upsell_paid',
  },
  {
    name: 'زمان‌بندی',
    href: '/schedule',
    icon: Calendar,
    roles: ['company_manager', 'supervisor'] as const,
  },
  {
    name: 'رزروها',
    href: '/reservations',
    icon: Calendar,
    roles: ['company_manager', 'supervisor'] as const,
  },
  {
    name: 'پیامک‌ها',
    href: '/sms',
    icon: MessageSquare,
    roles: ['company_manager', 'supervisor', 'agent'] as const,
  },
  {
    name: 'تنظیمات',
    href: '/settings',
    icon: Settings,
    roles: ['company_manager'] as const,
  },
]

export function Sidebar() {
  const location = useLocation()
  const { user } = useAuth()
  const { logout } = useAuthActions()
  const { sidebarOpen, toggleSidebar } = useSidebar()

  const userRole = user?.role
  const visibleNavigation =
    userRole
      ? navigation.filter((item) => {
          if (userRole === 'company_manager') return true
          const allowedRoles = item.roles as readonly UserRole[]
          return allowedRoles.includes(userRole)
        })
      : []

  return (
    <>
      {/* Mobile backdrop */}
      {sidebarOpen && (
        <div 
          className="fixed inset-0 z-40 bg-black bg-opacity-50 lg:hidden"
          onClick={toggleSidebar}
        />
      )}

      {/* Sidebar */}
      <div className={cn(
        "fixed inset-y-0 right-0 z-50 w-64 bg-card border-l transform transition-transform duration-300 ease-in-out lg:translate-x-0 lg:static lg:inset-0",
        sidebarOpen ? "translate-x-0" : "translate-x-full"
      )}>
        <div className="flex h-full flex-col">
          {/* Header */}
          <div className="flex h-16 items-center justify-between px-4 border-b">
            <h1 className="text-lg font-semibold">پنل مدیریت</h1>
            <Button
              variant="ghost"
              size="icon"
              onClick={toggleSidebar}
              className="lg:hidden"
            >
              <X className="h-5 w-5" />
            </Button>
          </div>

          {/* User info */}
          {user && (
            <div className="p-4 border-b">
              <div className="flex items-center space-x-3 space-x-reverse">
                <div className="h-8 w-8 rounded-full bg-primary flex items-center justify-center text-primary-foreground text-sm font-medium">
                  {user.display_name.charAt(0)}
                </div>
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-medium truncate">{user.display_name}</p>
                  <p className="text-xs text-muted-foreground truncate">{user.email}</p>
                </div>
              </div>
            </div>
          )}

          {userRole ? (
            <nav className="flex-1 space-y-1 p-4">
              {visibleNavigation.map((item) => {
                  const isActive =
                    typeof item.match === 'function'
                      ? item.match(location)
                      : location.pathname === item.href
                  const Icon = item.icon

                  return (
                    <Link
                      key={item.name}
                      to={`${item.href}${item.search ?? ''}`}
                      className={cn(
                        'flex items-center space-x-3 space-x-reverse rounded-md px-3 py-2 text-sm font-medium transition-colors',
                        isActive
                          ? 'bg-primary text-primary-foreground'
                          : 'text-muted-foreground hover:bg-accent hover:text-foreground'
                      )}
                      onClick={() => {
                        if (window.innerWidth < 1024) {
                          toggleSidebar()
                        }
                      }}
                    >
                      <Icon className="h-5 w-5" />
                      <span>{item.name}</span>
                    </Link>
                  )
                })}
            </nav>
          ) : (
            <div className="flex flex-1 items-center justify-center p-6 text-sm text-muted-foreground">
              در حال بارگذاری اطلاعات کاربر...
            </div>
          )}

          {/* Footer */}
          <div className="p-4 border-t">
            <Button
              variant="ghost"
              className="w-full justify-start"
              onClick={() => {
                logout()
                toggleSidebar()
                window.location.href = '/login'
              }}
            >
              <LogOut className="h-4 w-4 ml-2" />
              خروج
            </Button>
          </div>
        </div>
      </div>
    </>
  )
}


