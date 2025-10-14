import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
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
  Calendar,
  Layers,
  MoreHorizontal,
  Loader2,
} from 'lucide-react'
import { useDebounce } from '@/hooks/useDebounce'
import { useNotification } from '@/store/uiStore'
import { useAuth } from '@/store/authStore'
import { cardsApi, usersApi } from '@/lib/api'
import {
  Supervisor,
  SupervisorListResponse,
  SupervisorFilters,
  CardListResponse,
} from '@/types'
import {
  cn,
  formatNumber,
  getErrorMessage,
} from '@/lib/utils'

const PER_PAGE = 12

export function SupervisorsPage() {
  const [searchTerm, setSearchTerm] = useState('')
  const [page, setPage] = useState(1)
  const debouncedSearch = useDebounce(searchTerm, 400)
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const { success, error: notifyError, warning } = useNotification()
  const navigate = useNavigate()

  useEffect(() => {
    setPage(1)
  }, [debouncedSearch])

  const filters: SupervisorFilters = useMemo(() => {
    const params: SupervisorFilters = {
      page,
      per_page: PER_PAGE,
    }

    if (debouncedSearch) {
      params.search = debouncedSearch
    }

    return params
  }, [page, debouncedSearch])

  const supervisorsQuery = useQuery({
    queryKey: ['supervisors', filters],
    queryFn: async () => {
      const response = await usersApi.getSupervisors(filters)
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در دریافت سرپرستان')
      }
      return response.data as SupervisorListResponse
    },
    placeholderData: (previousData) => previousData,
  })

  const assignCardsMutation = useMutation({
    mutationFn: async ({
      supervisorId,
      cardIds,
      setDefault,
    }: { supervisorId: number; cardIds: number[]; setDefault: boolean }) => {
      const response = await usersApi.assignSupervisorCards(supervisorId, cardIds, setDefault)
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در بروزرسانی کارت‌ها')
      }
      return response.data
    },
    onSuccess: () => {
      success('کارت‌ها بروزرسانی شد', 'لیست کارت‌های سرپرست به‌روزرسانی شد')
      queryClient.invalidateQueries({ queryKey: ['supervisors'] })
    },
    onError: (err) => {
      notifyError('خطا در ذخیره کارت‌ها', getErrorMessage(err))
    },
  })

  const supervisors = supervisorsQuery.data?.items ?? []
  const pagination = supervisorsQuery.data?.pagination
  const totalPages = pagination?.total_pages ?? 1
  const total = pagination?.total ?? 0

  const handleManageCards = async (supervisor: Supervisor, closeMenu: () => void) => {
    closeMenu()
    try {
      const cardsResponse = await cardsApi.getCards({ per_page: 100 })
      if (!cardsResponse.success) {
        throw new Error(cardsResponse.error?.message || 'خطا در دریافت کارت‌ها')
      }

      const cardData: CardListResponse = cardsResponse.data
      const cardItems = cardData.items ?? []

      if (!cardItems.length) {
        warning('کارت موجود نیست', 'ابتدا کارت‌هایی در سایت اصلی ایجاد کنید')
        return
      }

      const options = cardItems
        .map((card) => `${card.id}: ${card.title}`)
        .join('\n')

      const current = supervisor.assigned_cards?.length
        ? `\nکارت‌های فعلی: ${supervisor.assigned_cards.join(', ')}`
        : ''

      const input = window.prompt(
        `شناسه کارت‌های دلخواه را با کاما جدا کنید:\n${options}${current}`,
        supervisor.assigned_cards?.join(',') ?? ''
      )

      if (input === null) {
        return
      }

      const cardIds = input
        .split(',')
        .map((id) => parseInt(id.trim(), 10))
        .filter((id) => !Number.isNaN(id) && id > 0)

      if (!cardIds.length) {
        warning('ورودی نامعتبر', 'حداقل یک شناسه کارت معتبر وارد کنید')
        return
      }

      const setDefault = window.confirm('آیا این کارت‌ها به عنوان کارت‌های پیش‌فرض تنظیم شوند؟')
      assignCardsMutation.mutate({ supervisorId: supervisor.id, cardIds, setDefault })
    } catch (err) {
      notifyError('خطا', getErrorMessage(err))
    }
  }

  const handleViewAgents = (supervisor: Supervisor, closeMenu: () => void) => {
    closeMenu()
    navigate(`/agents?supervisor_id=${supervisor.id}`)
  }

  const handleViewSchedule = (supervisor: Supervisor) => {
    navigate(`/schedule?supervisor_id=${supervisor.id}`)
  }

  const isMutating = assignCardsMutation.isPending

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">سرپرستان</h1>
          <p className="text-muted-foreground">
            مدیریت سرپرست‌ها، کارت‌های تخصیص‌یافته و آمار مربوطه
          </p>
        </div>
        <Button disabled className="gap-2">
          <Plus className="h-4 w-4" />
          سرپرست جدید (به‌زودی)
        </Button>
      </div>

      <Card>
        <CardContent className="p-6">
          <div className="relative">
            <Search className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={searchTerm}
              onChange={(event) => setSearchTerm(event.target.value)}
              placeholder="جست‌وجو در نام کاربری، نام یا ایمیل سرپرست..."
              className="pr-9"
            />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <CardTitle>فهرست سرپرستان</CardTitle>
            <CardDescription>
              {supervisorsQuery.isLoading
                ? 'در حال دریافت اطلاعات...'
                : `مجموع ${formatNumber(total)} سرپرست یافت شد`}
            </CardDescription>
          </div>
          <Badge variant="outline">{user?.display_name}</Badge>
        </CardHeader>
        <CardContent>
          {supervisorsQuery.isError && (
            <div className="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-700">
              {getErrorMessage(supervisorsQuery.error)}
            </div>
          )}

          {supervisorsQuery.isLoading ? (
            <div className="flex h-48 items-center justify-center">
              <Loader2 className="h-8 w-8 animate-spin text-primary" />
            </div>
          ) : supervisors.length === 0 ? (
            <div className="flex flex-col items-center justify-center gap-3 py-20 text-center text-muted-foreground">
              <Layers className="h-12 w-12" />
              <h3 className="text-lg font-medium text-foreground">
                سرپرستی یافت نشد
              </h3>
              <p className="max-w-md text-sm">
                برای نقش فعلی شما سرپرستی ثبت نشده است. در صورت نیاز با مدیر سیستم هماهنگ کنید.
              </p>
            </div>
          ) : (
            <div className="grid gap-4 lg:grid-cols-2">
              {supervisors.map((supervisor: Supervisor) => (
                <SupervisorCard
                  key={supervisor.id}
                  supervisor={supervisor}
                  disabled={isMutating}
                  onManageCards={handleManageCards}
                  onViewAgents={handleViewAgents}
                  onViewSchedule={handleViewSchedule}
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
                  disabled={page === 1 || supervisorsQuery.isFetching}
                >
                  قبلی
                </Button>
                <Button
                  variant="outline"
                  onClick={() => setPage((prev) => Math.min(prev + 1, totalPages))}
                  disabled={page === totalPages || supervisorsQuery.isFetching}
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

interface SupervisorCardProps {
  supervisor: Supervisor
  disabled: boolean
  onManageCards: (supervisor: Supervisor, close: () => void) => void
  onViewAgents: (supervisor: Supervisor, close: () => void) => void
  onViewSchedule: (supervisor: Supervisor) => void
}

function SupervisorCard({
  supervisor,
  disabled,
  onManageCards,
  onViewAgents,
  onViewSchedule,
}: SupervisorCardProps) {
  const [menuOpen, setMenuOpen] = useState(false)

  return (
    <Card className="relative overflow-hidden">
      <CardContent className="space-y-4 p-6">
        <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
          <div className="flex flex-1 items-start gap-3">
            <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-primary/10 text-lg font-semibold text-primary">
              {supervisor.display_name?.charAt(0) ?? '?'}
            </div>
            <div className="space-y-2">
              <div className="flex flex-wrap items-center gap-2">
                <h3 className="text-base font-semibold text-foreground">
                  {supervisor.display_name}
                </h3>
                <Badge variant="secondary">شناسه: {supervisor.id}</Badge>
              </div>
              <p className="text-sm text-muted-foreground">{supervisor.email}</p>
              <div className="flex flex-wrap gap-4 text-xs text-muted-foreground">
                <span>نام کاربری: {supervisor.username}</span>
                <span>{formatNumber(supervisor.agents_count)} کارشناس</span>
                <span>{formatNumber(supervisor.customers_count)} مشتری</span>
              </div>
              <div className="space-y-2">
                <p className="text-sm font-medium text-foreground">کارت‌های تخصیص‌یافته</p>
                {supervisor.assigned_card_titles?.length ? (
                  <div className="flex flex-wrap gap-2">
                    {supervisor.assigned_card_titles.map((title, index) => (
                      <Badge key={`${supervisor.id}-card-${index}`} variant="outline">
                        {title}
                      </Badge>
                    ))}
                  </div>
                ) : (
                  <p className="text-xs text-muted-foreground">هیچ کارتی تخصیص داده نشده است.</p>
                )}
              </div>
            </div>
          </div>
          <div className="flex items-start gap-2">
            <Button
              variant="outline"
              size="sm"
              className="gap-2"
              onClick={() => onViewSchedule(supervisor)}
            >
              <Calendar className="h-4 w-4" />
              زمان‌بندی
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
                    label="مدیریت کارت‌ها"
                    onClick={() => onManageCards(supervisor, () => setMenuOpen(false))}
                  />
                  <ActionMenuItem
                    label="مشاهده کارشناسان"
                    onClick={() => onViewAgents(supervisor, () => setMenuOpen(false))}
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
      onClick={() => onClick()}
    >
      <span>{label}</span>
    </button>
  )
}
