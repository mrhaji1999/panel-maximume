import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useSearchParams } from 'react-router-dom'
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
import {
  Search,
  Plus,
  Users,
  UserCheck,
  MoreHorizontal,
  Loader2,
} from 'lucide-react'
import { useDebounce } from '@/hooks/useDebounce'
import { useNotification } from '@/store/uiStore'
import { useAuth } from '@/store/authStore'
import { usersApi } from '@/lib/api'
import { Agent, AgentFilters, AgentListResponse } from '@/types'
import {
  cn,
  formatNumber,
  getErrorMessage,
} from '@/lib/utils'

const PER_PAGE = 12

export function AgentsPage() {
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const { success, error: notifyError, info, warning } = useNotification()

  const supervisorParam = searchParams.get('supervisor_id')
  const supervisorId = supervisorParam ? parseInt(supervisorParam, 10) : undefined

  const [searchTerm, setSearchTerm] = useState('')
  const [page, setPage] = useState(1)
  const debouncedSearch = useDebounce(searchTerm, 400)

  useEffect(() => {
    setPage(1)
  }, [debouncedSearch, supervisorId])

  const filters: AgentFilters = useMemo(() => {
    const params: AgentFilters = {
      page,
      per_page: PER_PAGE,
    }

    if (debouncedSearch) {
      params.search = debouncedSearch
    }

    if (supervisorId && !Number.isNaN(supervisorId)) {
      params.supervisor_id = supervisorId
    }

    return params
  }, [page, debouncedSearch, supervisorId])

  const agentsQuery = useQuery({
    queryKey: ['agents', filters],
    queryFn: async () => {
      const response = await usersApi.getAgents(filters)
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در دریافت اطلاعات کارشناسان')
      }
      return response.data as AgentListResponse
    },
    placeholderData: (previousData) => previousData,
  })

  const changeSupervisorMutation = useMutation({
    mutationFn: async ({ agentId, supervisorId }: { agentId: number; supervisorId: number }) => {
      const response = await usersApi.updateAgentSupervisor(agentId, supervisorId)
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در بروزرسانی سرپرست')
      }
      return response.data
    },
    onSuccess: () => {
      success('سرپرست بروزرسانی شد', 'تخصیص سرپرست با موفقیت انجام شد')
      queryClient.invalidateQueries({ queryKey: ['agents'] })
      queryClient.invalidateQueries({ queryKey: ['supervisors'] })
    },
    onError: (error) => {
      notifyError('خطا در بروزرسانی', getErrorMessage(error))
    },
  })

  const agents = agentsQuery.data?.items ?? []
  const pagination = agentsQuery.data?.pagination
  const totalPages = pagination?.total_pages ?? 1
  const total = pagination?.total ?? 0
  const isMutating = changeSupervisorMutation.isPending

  const handleChangeSupervisor = (agent: Agent, closeMenu: () => void) => {
    closeMenu()

    const input = window.prompt(
      'شناسه سرپرست جدید را وارد کنید (برای حذف تخصیص، مقدار 0 وارد کنید):',
      agent.supervisor_id ? String(agent.supervisor_id) : ''
    )

    if (input === null) {
      return
    }

    const supervisorValue = Number(input.trim())
    if (Number.isNaN(supervisorValue) || supervisorValue < 0) {
      notifyError('ورودی نامعتبر', 'شناسه سرپرست باید یک عدد معتبر باشد')
      return
    }

    if (supervisorValue === (agent.supervisor_id ?? 0)) {
      info('بدون تغییر', 'شناسه وارد شده با مقدار فعلی برابر است')
      return
    }

    changeSupervisorMutation.mutate({ agentId: agent.id, supervisorId: supervisorValue })
  }

  const handleViewCustomers = (agent: Agent, closeMenu: () => void) => {
    closeMenu()
    navigate(`/customers?agent_id=${agent.id}`)
  }

  const handleCustomersButton = (agent: Agent) => {
    if (agent.customers_count === 0) {
      warning('مشتری ثبت نشده است', 'برای این کارشناس مشتری فعالی وجود ندارد')
      return
    }
    navigate(`/customers?agent_id=${agent.id}`)
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div className="space-y-1">
          <h1 className="text-2xl font-bold tracking-tight">کارشناسان</h1>
          <p className="text-muted-foreground">
            لیست کارشناسان فعال سامانه و وضعیت تخصیص سرپرست و مشتریان آن‌ها
          </p>
        </div>
        <Button disabled className="gap-2">
          <Plus className="h-4 w-4" />
          کارشناس جدید (به‌زودی)
        </Button>
      </div>

      <Card>
        <CardContent className="p-6">
          <div className="grid gap-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-center">
            <div className="relative">
              <Search className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                value={searchTerm}
                onChange={(event) => setSearchTerm(event.target.value)}
                placeholder="جست‌وجو در نام، نام کاربری یا ایمیل کارشناس..."
                className="pr-9"
              />
            </div>
            {supervisorId && (
              <Badge variant="outline">
                فیلتر سرپرست: {formatNumber(supervisorId)}
              </Badge>
            )}
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <CardTitle>فهرست کارشناسان</CardTitle>
            <CardDescription>
              {agentsQuery.isLoading
                ? 'در حال دریافت اطلاعات...'
                : `مجموع ${formatNumber(total)} کارشناس یافت شد`}
            </CardDescription>
          </div>
          {user && (
            <Badge variant="outline">{user.display_name}</Badge>
          )}
        </CardHeader>
        <CardContent>
          {agentsQuery.isError && (
            <div className="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-700">
              {getErrorMessage(agentsQuery.error)}
            </div>
          )}

          {agentsQuery.isLoading ? (
            <div className="flex h-48 items-center justify-center">
              <Loader2 className="h-8 w-8 animate-spin text-primary" />
            </div>
          ) : agents.length === 0 ? (
            <div className="flex flex-col items-center justify-center gap-3 py-20 text-center text-muted-foreground">
              <Users className="h-12 w-12" />
              <h3 className="text-lg font-medium text-foreground">
                کارشناس یافت نشد
              </h3>
              <p className="max-w-md text-sm">
                فیلترهای جست‌وجو نتیجه‌ای نداشت. می‌توانید شرط‌ها را تغییر دهید یا از مدیریت برای افزودن کارشناس کمک بگیرید.
              </p>
            </div>
          ) : (
            <div className="grid gap-4 lg:grid-cols-2">
              {agents.map((agent: Agent) => (
                <AgentCard
                  key={agent.id}
                  agent={agent}
                  disabled={isMutating}
                  onChangeSupervisor={handleChangeSupervisor}
                  onViewCustomers={handleViewCustomers}
                  onOpenCustomers={handleCustomersButton}
                />
              ))}
            </div>
          )}

          {pagination && totalPages > 1 && (
            <div className="mt-6 flex items-center justify-between">
              <span className="text-sm text-muted-foreground">
                صفحه {formatNumber(page)} از {formatNumber(totalPages)}
              </span>
              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  onClick={() => setPage((prev) => Math.max(prev - 1, 1))}
                  disabled={page === 1 || agentsQuery.isFetching}
                >
                  قبلی
                </Button>
                <Button
                  variant="outline"
                  onClick={() => setPage((prev) => Math.min(prev + 1, totalPages))}
                  disabled={page === totalPages || agentsQuery.isFetching}
                >
                  بعدی
                </Button>
              </div>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}

interface AgentCardProps {
  agent: Agent
  disabled: boolean
  onChangeSupervisor: (agent: Agent, close: () => void) => void
  onViewCustomers: (agent: Agent, close: () => void) => void
  onOpenCustomers: (agent: Agent) => void
}

function AgentCard({
  agent,
  disabled,
  onChangeSupervisor,
  onViewCustomers,
  onOpenCustomers,
}: AgentCardProps) {
  const [menuOpen, setMenuOpen] = useState(false)

  const supervisorName = agent.supervisor_name ?? 'نامشخص'
  const statusLabel = agent.status === 'inactive' ? 'غیرفعال' : 'فعال'
  const statusVariant = agent.status === 'inactive' ? 'secondary' : 'default'

  return (
    <Card className="relative overflow-hidden">
      <CardContent className="space-y-4 p-6">
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div className="flex flex-1 items-start gap-3">
            <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-primary/10 text-lg font-semibold text-primary">
              {agent.display_name?.charAt(0) ?? agent.username?.charAt(0) ?? '?'}
            </div>
            <div className="space-y-2">
              <div className="flex flex-wrap items-center gap-2">
                <h3 className="text-base font-semibold text-foreground">
                  {agent.display_name || agent.username}
                </h3>
                <Badge variant="secondary">شناسه: {agent.id}</Badge>
              </div>
              <p className="text-sm text-muted-foreground">{agent.email}</p>
              <div className="flex flex-wrap gap-4 text-xs text-muted-foreground">
                <span>نام کاربری: {agent.username}</span>
                <span>{formatNumber(agent.customers_count)} مشتری</span>
                <span>سرپرست: {supervisorName}</span>
              </div>
              <Badge variant={statusVariant}>{statusLabel}</Badge>
            </div>
          </div>
          <div className="flex items-start gap-2">
            <Button
              variant="outline"
              size="sm"
              className="gap-2"
              onClick={() => onOpenCustomers(agent)}
            >
              <Users className="h-4 w-4" />
              مشتریان
            </Button>
            <div className="relative">
              <Button
                variant="ghost"
                size="icon"
                disabled={disabled}
                onClick={() => setMenuOpen((prev) => !prev)}
              >
                <MoreHorizontal className="h-4 w-4" />
              </Button>
              {menuOpen && (
                <div className="absolute left-0 top-full z-20 mt-2 w-48 rounded-md border bg-background p-1 shadow-lg">
                  <ActionMenuItem
                    label="تغییر سرپرست"
                    onClick={() => onChangeSupervisor(agent, () => setMenuOpen(false))}
                  />
                  <ActionMenuItem
                    label="مشاهده مشتریان"
                    onClick={() => onViewCustomers(agent, () => setMenuOpen(false))}
                  />
                  <ActionMenuItem
                    label="بستن"
                    onClick={() => setMenuOpen(false)}
                  />
                </div>
              )}
            </div>
          </div>
        </div>
      </CardContent>
    </Card>
  )
}

interface ActionMenuItemProps {
  label: string
  onClick: () => void
}

function ActionMenuItem({ label, onClick }: ActionMenuItemProps) {
  return (
    <button
      type="button"
      className={cn(
        'flex w-full items-center justify-between rounded-md px-3 py-2 text-right text-sm transition',
        'hover:bg-muted'
      )}
      onClick={onClick}
    >
      <span>{label}</span>
      <UserCheck className="h-4 w-4 text-muted-foreground" />
    </button>
  )
}
