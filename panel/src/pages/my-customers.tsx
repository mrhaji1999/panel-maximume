import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { customersApi } from '@/lib/api'
import { Customer, CustomerListResponse } from '@/types'
import {
  formatDateTime,
  formatNumber,
  getErrorMessage,
  getStatusLabel,
} from '@/lib/utils'
import { useAuth } from '@/store/authStore'
import { useDebounce } from '@/hooks/useDebounce'
import { Search } from 'lucide-react'

const PER_PAGE = 20

type FilterState = {
  search: string
  page: number
}

export function MyCustomersPage() {
  const { user } = useAuth()
  const [filters, setFilters] = useState<FilterState>({ search: '', page: 1 })
  const debouncedSearch = useDebounce(filters.search, 400)

  useEffect(() => {
    setFilters((prev) => ({ ...prev, page: 1 }))
  }, [debouncedSearch])

  const query = useQuery({
    queryKey: ['my-customers', user?.id, { page: filters.page, search: debouncedSearch }],
    enabled: Boolean(user?.id),
    queryFn: async () => {
      if (!user?.id) throw new Error('کاربر نامعتبر است')
      const response = await customersApi.getCustomers({
        page: filters.page,
        per_page: PER_PAGE,
        supervisor_id: user.id,
        search: debouncedSearch || undefined,
      })
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در دریافت مشتریان')
      }
      return response.data as CustomerListResponse
    },
    placeholderData: (previousData) => previousData,
  })

  const customers = query.data?.items ?? []
  const pagination = query.data?.pagination
  const totalPages = pagination?.total_pages ?? 1
  const total = pagination?.total ?? 0

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">مشتریان من</h1>
          <p className="text-muted-foreground">مشتریانی که به شما تخصیص داده شده‌اند</p>
        </div>
        <Badge variant="outline">{formatNumber(total)} مشتری</Badge>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>جست‌وجو</CardTitle>
          <CardDescription>نام یا ایمیل مشتری را جست‌وجو کنید</CardDescription>
        </CardHeader>
        <CardContent>
          <div className="relative">
            <Search className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={filters.search}
              onChange={(event) => setFilters((prev) => ({ ...prev, search: event.target.value }))}
              placeholder="جست‌وجو در مشتریان"
              className="pr-10"
            />
          </div>
        </CardContent>
      </Card>

      {query.isLoading ? (
        <LoadingSkeleton />
      ) : query.isError ? (
        <Card>
          <CardHeader>
            <CardTitle>خطا در بارگذاری</CardTitle>
            <CardDescription>{getErrorMessage(query.error)}</CardDescription>
          </CardHeader>
        </Card>
      ) : customers.length === 0 ? (
        <Card>
          <CardContent className="p-12 text-center text-muted-foreground">
            <p>هیچ مشتری برای شما ثبت نشده است.</p>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4">
          {customers.map((customer: Customer) => (
            <CustomerCard key={customer.id} customer={customer} />
          ))}
        </div>
      )}

      {pagination && customers.length > 0 && (
        <div className="flex items-center justify-between">
          <span className="text-sm text-muted-foreground">
            صفحه {formatNumber(filters.page)} از {formatNumber(totalPages)}
          </span>
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              onClick={() => setFilters((prev) => ({ ...prev, page: Math.max(prev.page - 1, 1) }))}
              disabled={filters.page === 1 || query.isFetching}
            >
              قبلی
            </Button>
            <Button
              variant="outline"
              onClick={() => setFilters((prev) => ({ ...prev, page: Math.min(prev.page + 1, totalPages) }))}
              disabled={filters.page === totalPages || query.isFetching}
            >
              بعدی
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}

function CustomerCard({ customer }: { customer: Customer }) {
  return (
    <Card>
      <CardContent className="space-y-3 p-6">
        <div className="flex items-start justify-between gap-4">
          <div>
            <h3 className="text-base font-semibold text-foreground">{customer.display_name}</h3>
            <p className="text-sm text-muted-foreground">{customer.email}</p>
          </div>
          <Badge>{getStatusLabel(customer.status)}</Badge>
        </div>
        <div className="grid gap-2 text-xs text-muted-foreground sm:grid-cols-2">
          <span>شناسه مشتری: #{customer.id}</span>
          <span>کارت: {customer.card_title || '-'}</span>
          <span>کارشناس: {customer.assigned_agent_name || '-'}</span>
          <span>ثبت‌نام: {formatDateTime(customer.registered_at ?? '')}</span>
        </div>
      </CardContent>
    </Card>
  )
}

function LoadingSkeleton() {
  return (
    <div className="space-y-4">
      {[...Array(4)].map((_, index) => (
        <Card key={index}>
          <CardContent className="p-6">
            <div className="flex items-center gap-4">
              <div className="h-12 w-12 animate-pulse rounded-full bg-muted" />
              <div className="flex-1 space-y-2">
                <div className="h-4 w-1/3 animate-pulse rounded bg-muted" />
                <div className="h-3 w-1/2 animate-pulse rounded bg-muted" />
              </div>
            </div>
          </CardContent>
        </Card>
      ))}
    </div>
  )
}
