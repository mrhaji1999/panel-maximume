import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { useAuth } from '@/store/authStore'
import { statsApi } from '@/lib/api'
import {
  DashboardSummary,
  DashboardCounts,
  SmsStatistics,
  UpsellStatistics,
  LogStatistics,
  ActivityLog,
} from '@/types'
import {
  cn,
  formatNumber,
  getErrorMessage,
  getRoleLabel,
  getStatusLabel,
  getTimeAgo,
} from '@/lib/utils'
import {
  Users,
  UserCheck,
  UserCog,
  Calendar,
  TrendingUp,
  MessageSquare,
  Clock,
  CheckCircle,
  AlertTriangle,
  PhoneMissed,
  PiggyBank,
} from 'lucide-react'

const STATUS_DISPLAY = [
  { key: 'normal', icon: CheckCircle, color: 'bg-green-100 text-green-800' },
  { key: 'upsell', icon: TrendingUp, color: 'bg-blue-100 text-blue-800' },
  { key: 'upsell_pending', icon: Clock, color: 'bg-orange-100 text-orange-800' },
  { key: 'upsell_paid', icon: PiggyBank, color: 'bg-purple-100 text-purple-800' },
  { key: 'no_answer', icon: PhoneMissed, color: 'bg-yellow-100 text-yellow-800' },
  { key: 'canceled', icon: AlertTriangle, color: 'bg-red-100 text-red-800' },
]

const QUICK_ACTIONS = [
  {
    title: 'مشاهده مشتریان',
    description: 'لیست تمام مشتریان',
    href: '/customers',
    icon: Users,
  },
  {
    title: 'زمان‌بندی',
    description: 'مدیریت ظرفیت و رزروها',
    href: '/schedule',
    icon: Calendar,
  },
  {
    title: 'پیامک‌ها',
    description: 'ارسال و مدیریت پیامک‌ها',
    href: '/sms',
    icon: MessageSquare,
  },
]

export function DashboardPage() {
  const { user } = useAuth()

  const summaryQuery = useQuery({
    queryKey: ['dashboard-summary'],
    queryFn: async () => {
      const response = await statsApi.getSummary()
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در دریافت اطلاعات داشبورد')
      }
      return response.data as DashboardSummary
    },
  })

  const { data: summary, isLoading, isError, error } = summaryQuery

  const counts: DashboardCounts | undefined = summary?.counts
  const smsStats: SmsStatistics | null | undefined = summary?.sms
  const upsellStats: UpsellStatistics | null | undefined = summary?.upsell
  const logStats: LogStatistics | null | undefined = summary?.logs
  const recentActivity: ActivityLog[] = summary?.recent_activity ?? []

  const statusRows = useMemo(() => {
    const rows = STATUS_DISPLAY.map((status) => {
      const value = summary?.status_counts?.[status.key] ?? 0
      return {
        key: status.key,
        title: getStatusLabel(status.key),
        value,
        icon: status.icon,
        color: status.color,
      }
    })

    if (summary) {
      Object.keys(summary.status_counts).forEach((statusKey) => {
        if (!rows.some((row) => row.key === statusKey)) {
          rows.push({
            key: statusKey,
            title: getStatusLabel(statusKey),
            value: summary.status_counts[statusKey],
            icon: TrendingUp,
            color: 'bg-slate-100 text-slate-800',
          })
        }
      })
    }

    return rows
  }, [summary])

  if (isLoading) {
    return (
      <div className="space-y-6">
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          {[...Array(4)].map((_, index) => (
            <Card key={index}>
              <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <div className="h-4 w-24 animate-pulse rounded bg-muted" />
                <div className="h-4 w-4 animate-pulse rounded bg-muted" />
              </CardHeader>
              <CardContent>
                <div className="mb-2 h-8 w-16 animate-pulse rounded bg-muted" />
                <div className="h-3 w-32 animate-pulse rounded bg-muted" />
              </CardContent>
            </Card>
          ))}
        </div>
      </div>
    )
  }

  if (isError) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>خطا در بارگذاری داشبورد</CardTitle>
          <CardDescription>{getErrorMessage(error)}</CardDescription>
        </CardHeader>
      </Card>
    )
  }

  if (!summary || !counts) {
    return null
  }

  const statCards = [
    {
      title: 'سرپرست‌ها',
      value: counts.supervisors,
      icon: UserCheck,
      description: 'تعداد سرپرست‌های فعال',
      color: 'text-blue-600',
    },
    {
      title: 'کارشناس‌ها',
      value: counts.agents,
      icon: UserCog,
      description: 'تعداد کارشناس‌های فعال',
      color: 'text-green-600',
    },
    {
      title: 'مشتریان',
      value: counts.customers,
      icon: Users,
      description: 'تعداد کل مشتریان',
      color: 'text-purple-600',
    },
    {
      title: 'رزرو امروز',
      value: counts.reservations_today,
      icon: Calendar,
      description: 'رزروهای امروز ثبت‌شده',
      color: 'text-orange-600',
    },
  ]

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">داشبورد</h1>
          <p className="text-muted-foreground">
            خوش آمدید {user?.display_name} ({user?.role ? getRoleLabel(user.role) : ''})
          </p>
        </div>
      </div>

      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        {statCards.map((stat) => {
          const Icon = stat.icon
          return (
            <Card key={stat.title}>
              <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle className="text-sm font-medium">{stat.title}</CardTitle>
                <Icon className={cn('h-4 w-4', stat.color)} />
              </CardHeader>
              <CardContent>
                <div className="text-2xl font-bold text-foreground">{formatNumber(stat.value)}</div>
                <p className="text-xs text-muted-foreground">{stat.description}</p>
              </CardContent>
            </Card>
          )
        })}
      </div>

      <div className="grid gap-6 md:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>وضعیت مشتریان</CardTitle>
            <CardDescription>توزیع مشتریان بر اساس وضعیت فعلی</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="space-y-4">
              {statusRows.map((status) => {
                const Icon = status.icon
                return (
                  <div key={status.key} className="flex items-center justify-between">
                    <div className="flex items-center space-x-3 space-x-reverse">
                      <Icon className="h-4 w-4" />
                      <span className="text-sm font-medium">{status.title}</span>
                    </div>
                    <Badge className={status.color}>{formatNumber(status.value)}</Badge>
                  </div>
                )
              })}
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>فعالیت‌های اخیر</CardTitle>
            <CardDescription>آخرین رخدادهای ثبت‌شده در سامانه</CardDescription>
          </CardHeader>
          <CardContent>
            {recentActivity.length === 0 ? (
              <p className="text-sm text-muted-foreground">فعلاً فعالیتی ثبت نشده است.</p>
            ) : (
              <div className="space-y-4">
                {recentActivity.map((activity) => (
                  <div key={activity.id ?? activity.created_at} className="flex items-start space-x-3 space-x-reverse">
                    <div className="mt-2 h-2 w-2 flex-shrink-0 rounded-full bg-blue-500" />
                    <div className="min-w-0 flex-1">
                      <p className="text-sm text-foreground">{activity.message}</p>
                      <div className="mt-1 flex items-center space-x-2 space-x-reverse text-xs text-muted-foreground">
                        {activity.user_id && <span>کاربر #{activity.user_id}</span>}
                        <span>{getTimeAgo(activity.created_at)}</span>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {user?.role === 'company_manager' && (
        <div className="grid gap-6 md:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle>آمار پیامک</CardTitle>
              <CardDescription>ارسال پیامک در بازه اخیر</CardDescription>
            </CardHeader>
            <CardContent>
              {smsStats ? (
                <div className="grid grid-cols-2 gap-4 text-sm">
                  <StatRow label="کل پیامک‌ها" value={smsStats.total_sent} />
                  <StatRow label="موفق" value={smsStats.successful} />
                  <StatRow label="ناموفق" value={smsStats.failed} />
                  <StatRow label="نرخ موفقیت" value={`${smsStats.success_rate}%`} />
                </div>
              ) : (
                <p className="text-sm text-muted-foreground">اطلاعاتی برای پیامک در دسترس نیست.</p>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>آمار فروش افزایشی</CardTitle>
              <CardDescription>گزارش ثبت و پرداخت سفارش‌های افزایشی</CardDescription>
            </CardHeader>
            <CardContent>
              {upsellStats ? (
                <div className="grid grid-cols-2 gap-4 text-sm">
                  <StatRow label="کل سفارش‌ها" value={upsellStats.total_orders} />
                  <StatRow label="تکمیل‌شده" value={upsellStats.completed_orders} />
                  <StatRow label="ناموفق" value={upsellStats.failed_orders} />
                  <StatRow label="درآمد کل" value={upsellStats.total_revenue.toLocaleString('fa-IR')} />
                </div>
              ) : (
                <p className="text-sm text-muted-foreground">اطلاعاتی برای فروش افزایشی در دسترس نیست.</p>
              )}
            </CardContent>
          </Card>
        </div>
      )}

      {user?.role === 'company_manager' && logStats && (
        <Card>
          <CardHeader>
            <CardTitle>گزارش لاگ‌ها</CardTitle>
            <CardDescription>خلاصه‌ای از وقایع ثبت‌شده در سیستم</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="grid gap-4 md:grid-cols-2 text-sm">
              <StatRow label="تعداد لاگ‌ها" value={logStats.total_logs} />
              <div>
                <p className="text-xs font-medium text-muted-foreground mb-2">تغییر وضعیت مشتریان</p>
                <div className="space-y-1">
                  {Object.entries(logStats.status_changes).map(([status, value]) => (
                    <div key={status} className="flex items-center justify-between">
                      <span className="text-xs text-muted-foreground">{getStatusLabel(status)}</span>
                      <span className="text-sm font-medium text-foreground">{formatNumber(value)}</span>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader>
          <CardTitle>عملیات سریع</CardTitle>
          <CardDescription>دسترسی سریع به بخش‌های پرکاربرد</CardDescription>
        </CardHeader>
        <CardContent>
          <div className="grid gap-4 md:grid-cols-3">
            {QUICK_ACTIONS.map((action) => (
              <QuickActionCard
                key={action.href}
                title={action.title}
                description={action.description}
                href={action.href}
                icon={action.icon}
              />
            ))}
          </div>
        </CardContent>
      </Card>
    </div>
  )
}

function StatRow({ label, value }: { label: string; value: number | string }) {
  return (
    <div className="flex items-center justify-between">
      <span className="text-xs text-muted-foreground">{label}</span>
      <span className="text-sm font-medium text-foreground">{typeof value === 'number' ? formatNumber(value) : value}</span>
    </div>
  )
}

function QuickActionCard({
  title,
  description,
  href,
  icon: Icon,
}: {
  title: string
  description: string
  href: string
  icon: typeof Users
}) {
  return (
    <Link
      to={href}
      className="block rounded-lg border p-4 transition-colors hover:bg-muted"
    >
      <div className="flex items-center space-x-3 space-x-reverse">
        <Icon className="h-5 w-5 text-blue-600" />
        <div>
          <h3 className="font-medium text-foreground">{title}</h3>
          <p className="text-sm text-muted-foreground">{description}</p>
        </div>
      </div>
    </Link>
  )
}
