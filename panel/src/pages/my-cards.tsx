import { useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { cardsApi } from '@/lib/api'
import { Card as CardType, SupervisorCardsResponse, CardPricing } from '@/types'
import {
  formatDateTime,
  formatNumber,
  getErrorMessage,
} from '@/lib/utils'
import { useAuth } from '@/store/authStore'

export function MyCardsPage() {
  const { user } = useAuth()

  useEffect(() => {
    document.title = 'کارت‌های من'
  }, [])

  const query = useQuery({
    queryKey: ['my-cards', user?.id],
    enabled: Boolean(user && user.role === 'supervisor'),
    queryFn: async () => {
      if (!user?.id) {
        throw new Error('کاربر نامعتبر است')
      }
      const response = await cardsApi.getSupervisorCards(user.id)
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در دریافت کارت‌ها')
      }
      return response.data as SupervisorCardsResponse
    },
  })

  if (user?.role !== 'supervisor') {
    return (
      <Card>
        <CardHeader>
          <CardTitle>دسترسی محدود</CardTitle>
          <CardDescription>این بخش فقط برای سرپرستان در دسترس است.</CardDescription>
        </CardHeader>
      </Card>
    )
  }

  if (query.isLoading) {
    return <LoadingSkeleton />
  }

  if (query.isError) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>خطا در بارگذاری کارت‌ها</CardTitle>
          <CardDescription>{getErrorMessage(query.error)}</CardDescription>
        </CardHeader>
      </Card>
    )
  }

  const cards = query.data?.items ?? []

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">کارت‌های من</h1>
          <p className="text-muted-foreground">
            کارت‌هایی که به عنوان سرپرست مدیریت می‌کنید
          </p>
        </div>
        <Badge variant="outline">{formatNumber(cards.length)} کارت</Badge>
      </div>

      {cards.length === 0 ? (
        <Card>
          <CardContent className="p-12 text-center text-muted-foreground">
            <p>هنوز کارتی به شما تخصیص داده نشده است.</p>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4 md:grid-cols-2">
          {cards.map((card) => (
            <SupervisorCard key={card.id} card={card} />
          ))}
        </div>
      )}
    </div>
  )
}

function SupervisorCard({ card }: { card: CardType }) {
  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
        <CardTitle className="text-base font-semibold text-foreground">
          {card.title}
        </CardTitle>
        <Badge variant="secondary">#{card.id}</Badge>
      </CardHeader>
      <CardContent className="space-y-3">
        <CardDescription className="text-sm text-muted-foreground">
          {card.excerpt || 'بدون توضیح'}
        </CardDescription>
        <div className="text-xs text-muted-foreground">
          <p>ایجاد شده در: {formatDateTime(card.created_at)}</p>
          <p>آخرین بروزرسانی: {formatDateTime(card.modified_at)}</p>
        </div>
        {card.pricings?.length ? (
          <div className="space-y-2">
            <p className="text-xs font-medium text-foreground">تعرفه‌ها</p>
            <div className="space-y-1">
              {card.pricings.map((pricing: CardPricing, index: number) => (
                <div
                  key={index}
                  className="flex items-center justify-between rounded-md border px-3 py-2"
                >
                  <span className="text-xs text-muted-foreground">{pricing.label || `گزینه ${index + 1}`}</span>
                  <span className="text-sm font-medium text-foreground">
                    {pricing.amount ? pricing.amount.toLocaleString('fa-IR') : '0'}
                  </span>
                </div>
              ))}
            </div>
          </div>
        ) : (
          <p className="text-xs text-muted-foreground">تعرفه‌ای تعریف نشده است.</p>
        )}
      </CardContent>
    </Card>
  )
}

function LoadingSkeleton() {
  return (
    <div className="grid gap-4 md:grid-cols-2">
      {[...Array(4)].map((_, index) => (
        <Card key={index}>
          <CardContent className="space-y-4 p-6">
            <div className="flex items-center justify-between">
              <div className="h-4 w-1/3 animate-pulse rounded bg-muted" />
              <div className="h-4 w-10 animate-pulse rounded bg-muted" />
            </div>
            <div className="h-3 w-full animate-pulse rounded bg-muted" />
            <div className="h-3 w-2/3 animate-pulse rounded bg-muted" />
            <div className="space-y-2">
              <div className="h-3 w-full animate-pulse rounded bg-muted" />
              <div className="h-3 w-3/4 animate-pulse rounded bg-muted" />
            </div>
          </CardContent>
        </Card>
      ))}
    </div>
  )
}
