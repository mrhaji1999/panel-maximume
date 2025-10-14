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
import { usersApi } from '@/lib/api'
import { Agent, AgentListResponse, AgentFilters } from '@/types'
import {

  formatNumber,
  getErrorMessage,
} from '@/lib/utils'
import { useAuth } from '@/store/authStore'
import { useDebounce } from '@/hooks/useDebounce'
import { Search } from 'lucide-react'

const PER_PAGE = 20

type FilterState = {
  search: string
  page: number
}

export function MyAgentsPage() {
  const { user } = useAuth()
  const [filters, setFilters] = useState<FilterState>({ search: '', page: 1 })
  const debouncedSearch = useDebounce(filters.search, 400)

  useEffect(() => {
    setFilters((prev) => ({ ...prev, page: 1 }))
  }, [debouncedSearch])

  const query = useQuery({
    queryKey: ['my-agents', user?.id, { page: filters.page, search: debouncedSearch }],
    enabled: Boolean(user?.id && user.role === 'supervisor'),
    queryFn: async () => {
      if (!user?.id) throw new Error('کاربر نامعتبر است')
      if (user.role !== 'supervisor') throw new Error('دسترسی ندارید')

      const params: AgentFilters = {
        page: filters.page,
        per_page: PER_PAGE,
        supervisor_id: user.id,
        search: debouncedSearch || undefined,
      }

      const response = await usersApi.getAgents(params)
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در دریافت کارشناسان')
      }
      return response.data as AgentListResponse
    },
    placeholderData: (previousData) => previousData,
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
          <CardTitle>خطا در بارگذاری کارشناسان</CardTitle>
          <CardDescription>{getErrorMessage(query.error)}</CardDescription>
        </CardHeader>
      </Card>
    )
  }

  const agents = query.data?.items ?? []
  const pagination = query.data?.pagination
  const totalPages = pagination?.total_pages ?? 1
  const total = pagination?.total ?? 0

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">کارشناس‌های من</h1>
          <p className="text-muted-foreground">کارشناسانی که زیرمجموعه شما هستند</p>
        </div>
        <Badge variant="outline">{formatNumber(total)} کارشناس</Badge>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>جست‌وجو</CardTitle>
          <CardDescription>نام، نام کاربری یا ایمیل کارشناس را جست‌وجو کنید</CardDescription>
        </CardHeader>
        <CardContent>
          <div className="relative">
            <Search className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={filters.search}
              onChange={(event) => setFilters((prev) => ({ ...prev, search: event.target.value }))}
              placeholder="جست‌وجو در کارشناسان"
              className="pr-10"
            />
          </div>
        </CardContent>
      </Card>

      {agents.length === 0 ? (
        <Card>
          <CardContent className="p-12 text-center text-muted-foreground">
            <p>هنوز کارشناس فعالی برای شما ثبت نشده است.</p>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4 md:grid-cols-2">
          {agents.map((agent: Agent) => (
            <AgentCard key={agent.id} agent={agent} />
          ))}
        </div>
      )}

      {pagination && agents.length > 0 && (
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

function AgentCard({ agent }: { agent: Agent }) {
  return (
    <Card>
      <CardContent className="space-y-3 p-6">
        <div className="flex items-start justify-between gap-4">
          <div>
            <h3 className="text-base font-semibold text-foreground">{agent.display_name}</h3>
            <p className="text-sm text-muted-foreground">{agent.email}</p>
          </div>
          <Badge variant={agent.status === 'inactive' ? 'secondary' : 'success'}>
            {agent.status === 'inactive' ? 'غیرفعال' : 'فعال'}
          </Badge>
        </div>
        <div className="grid gap-2 text-xs text-muted-foreground sm:grid-cols-2">
          <span>شناسه کارشناس: #{agent.id}</span>
          <span>نام کاربری: {agent.username}</span>
          <span>مشتریان فعال: {formatNumber(agent.customers_count)}</span>
          <span>سرپرست: {agent.supervisor_name || '-'}</span>
        </div>
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
