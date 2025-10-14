import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { reservationsApi } from '@/lib/api'
import { Reservation, ReservationListResponse } from '@/types'
import {
  formatDateTime,
  formatNumber,
  getErrorMessage,
  getWeekdayName,
} from '@/lib/utils'
import { Search, Calendar } from 'lucide-react'

const PER_PAGE = 20

export function ReservationsPage() {
  const [searchTerm, setSearchTerm] = useState('')
  const [page, setPage] = useState(1)

  useEffect(() => {
    setPage(1)
  }, [searchTerm])

  const { data, isLoading, isError, error, isFetching } = useQuery({
    queryKey: ['reservations', { page, per_page: PER_PAGE }],
    queryFn: async () => {
      const response = await reservationsApi.getReservations({ page, per_page: PER_PAGE })
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در دریافت رزروها')
      }
      return response.data as ReservationListResponse
    },
    placeholderData: (previousData) => previousData,
  })

  const reservations = data?.items ?? []
  const pagination = data?.pagination
  const totalPages = pagination?.total_pages ?? 1

  const filteredReservations = useMemo(() => {
    if (!searchTerm) {
      return reservations
    }
    const keyword = searchTerm.trim().toLowerCase()
    return reservations.filter((reservation: Reservation) => {
      return (
        reservation.customer_name?.toLowerCase().includes(keyword) ||
        reservation.customer_email?.toLowerCase().includes(keyword) ||
        reservation.card_title?.toLowerCase().includes(keyword) ||
        reservation.supervisor_name?.toLowerCase().includes(keyword)
      )
    })
  }, [reservations, searchTerm])

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">رزروها</h1>
          <p className="text-muted-foreground">
            مدیریت و پیگیری رزروهای ثبت‌شده برای کارت‌ها و سرپرستان
          </p>
        </div>
        {pagination && (
          <Badge variant="outline">
            {formatNumber(pagination.total)} رزرو ثبت شده
          </Badge>
        )}
      </div>

      <Card>
        <CardHeader>
          <CardTitle>فیلترها</CardTitle>
          <CardDescription>نام مشتری، ایمیل، کارت یا سرپرست را جست‌وجو کنید</CardDescription>
        </CardHeader>
        <CardContent>
          <div className="relative">
            <Search className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={searchTerm}
              onChange={(event) => setSearchTerm(event.target.value)}
              placeholder="جست‌وجو..."
              className="pr-10"
            />
          </div>
        </CardContent>
      </Card>

      {isLoading ? (
        <div className="space-y-4">
          {[...Array(4)].map((_, index) => (
            <Card key={index}>
              <CardContent className="p-6">
                <div className="flex items-center gap-4">
                  <div className="h-12 w-12 animate-pulse rounded-full bg-muted" />
                  <div className="flex-1 space-y-2">
                    <div className="h-4 w-1/4 animate-pulse rounded bg-muted" />
                    <div className="h-3 w-1/2 animate-pulse rounded bg-muted" />
                  </div>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      ) : isError ? (
        <Card>
          <CardHeader>
            <CardTitle>خطا در بارگذاری رزروها</CardTitle>
            <CardDescription>{getErrorMessage(error)}</CardDescription>
          </CardHeader>
        </Card>
      ) : filteredReservations.length === 0 ? (
        <Card>
          <CardContent className="p-12 text-center text-muted-foreground">
            <Calendar className="mx-auto mb-3 h-10 w-10" />
            <p>رزروی مطابق با فیلترهای فعلی یافت نشد.</p>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4">
          {filteredReservations.map((reservation: Reservation) => (
            <ReservationCard key={reservation.id} reservation={reservation} />
          ))}
        </div>
      )}

      {pagination && filteredReservations.length > 0 && (
        <div className="flex items-center justify-between">
          <span className="text-sm text-muted-foreground">
            صفحه {formatNumber(page)} از {formatNumber(totalPages)}
          </span>
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              onClick={() => setPage((prev) => Math.max(prev - 1, 1))}
              disabled={page === 1 || isFetching}
            >
              قبلی
            </Button>
            <Button
              variant="outline"
              onClick={() => setPage((prev) => Math.min(prev + 1, totalPages))}
              disabled={page === totalPages || isFetching}
            >
              بعدی
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}

function ReservationCard({ reservation }: { reservation: Reservation }) {
  return (
    <Card>
      <CardContent className="p-6">
        <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
          <div className="flex flex-1 items-start gap-3">
            <div className="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-lg font-semibold text-primary">
              {reservation.customer_name?.charAt(0) ?? '?'}
            </div>
            <div className="space-y-2">
              <div className="flex flex-wrap items-center gap-2">
                <h3 className="text-base font-semibold text-foreground">
                  {reservation.customer_name}
                </h3>
                <Badge variant="secondary">شناسه: {reservation.id}</Badge>
              </div>
              <p className="text-sm text-muted-foreground">{reservation.customer_email}</p>
              <div className="grid gap-2 text-xs text-muted-foreground sm:grid-cols-2">
                <InfoRow label="کارت" value={reservation.card_title ?? '-'} />
                <InfoRow label="سرپرست" value={reservation.supervisor_name ?? '-'} />
                <InfoRow label="روز" value={getWeekdayName(reservation.weekday)} />
                <InfoRow
                  label="ساعت"
                  value={`${reservation.hour.toString().padStart(2, '0')}:00`}
                />
              </div>
            </div>
          </div>
          <div className="w-full text-sm text-muted-foreground md:w-40 md:text-right">
            <p>ثبت شده در:</p>
            <p className="font-medium text-foreground">
              {formatDateTime(reservation.created_at)}
            </p>
          </div>
        </div>
      </CardContent>
    </Card>
  )
}

function InfoRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between gap-2">
      <span>{label}</span>
      <span className="font-medium text-foreground">{value}</span>
    </div>
  )
}
