import { Button } from '@/components/ui/button'
import { useSidebar, useMobileMenu, useTheme, useUIStore } from '@/store/uiStore'
import { useAuth } from '@/store/authStore'
import { Menu, Sun, Moon, Bell } from 'lucide-react'

export function Header() {
  const { toggleSidebar } = useSidebar()
  const { toggleMobileMenu } = useMobileMenu()
  const theme = useTheme()
  const toggleTheme = useUIStore((state) => state.toggleTheme)
  const { user } = useAuth()

  return (
    <header className="h-16 border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60">
      <div className="flex h-full items-center justify-between px-4">
        {/* Left side */}
        <div className="flex items-center space-x-4 space-x-reverse">
          {/* Mobile menu button */}
          <Button
            variant="ghost"
            size="icon"
            onClick={toggleMobileMenu}
            className="lg:hidden"
          >
            <Menu className="h-5 w-5" />
          </Button>

          {/* Desktop sidebar toggle */}
          <Button
            variant="ghost"
            size="icon"
            onClick={toggleSidebar}
            className="hidden lg:flex"
          >
            <Menu className="h-5 w-5" />
          </Button>

          {/* Page title */}
          <h1 className="text-lg font-semibold">
            {getPageTitle()}
          </h1>
        </div>

        {/* Right side */}
        <div className="flex items-center space-x-2 space-x-reverse">
          {/* Theme toggle */}
          <Button
            variant="ghost"
            size="icon"
            onClick={toggleTheme}
          >
            {theme === 'light' ? (
              <Moon className="h-5 w-5" />
            ) : (
              <Sun className="h-5 w-5" />
            )}
          </Button>

          {/* Notifications */}
          <Button
            variant="ghost"
            size="icon"
            className="relative"
          >
            <Bell className="h-5 w-5" />
            <span className="absolute -top-1 -right-1 h-3 w-3 bg-red-500 rounded-full text-xs text-white flex items-center justify-center">
              3
            </span>
          </Button>

          {/* User menu */}
          {user && (
            <div className="flex items-center space-x-2 space-x-reverse">
              <div className="text-sm">
                <p className="font-medium">{user.display_name}</p>
                <p className="text-muted-foreground text-xs">{getRoleLabel(user.role)}</p>
              </div>
              <div className="h-8 w-8 rounded-full bg-primary flex items-center justify-center text-primary-foreground text-sm font-medium">
                {user.display_name.charAt(0)}
              </div>
            </div>
          )}
        </div>
      </div>
    </header>
  )
}

function getPageTitle(): string {
  const path = window.location.pathname
  
  const titles: Record<string, string> = {
    '/dashboard': 'داشبورد',
    '/supervisors': 'سرپرست‌ها',
    '/agents': 'کارشناس‌ها',
    '/my-cards': 'کارت‌های من',
    '/my-agents': 'کارشناس‌های من',
    '/my-customers': 'مشتریان من',
    '/assigned-customers': 'مشتریان تخصیص‌یافته',
    '/customers': 'مشتریان',
    '/schedule': 'زمان‌بندی',
    '/reservations': 'رزروها',
    '/sms': 'پیامک‌ها',
    '/settings': 'تنظیمات',
    '/profile': 'پروفایل',
  }
  
  return titles[path] || 'پنل مدیریت'
}

function getRoleLabel(role: string): string {
  const roleLabels: Record<string, string> = {
    'company_manager': 'مدیر شرکت',
    'supervisor': 'سرپرست',
    'agent': 'کارشناس',
  }
  return roleLabels[role] || role
}
