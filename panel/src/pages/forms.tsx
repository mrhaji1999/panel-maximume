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
import { formsApi, cardsApi } from '@/lib/api'
import {
  FormSubmission,
  FormListResponse,
  FormFilters,
  CardListResponse,
} from '@/types'
import {
  formatDateTime,
  formatNumber,
  getErrorMessage,
} from '@/lib/utils'
import { useDebounce } from '@/hooks/useDebounce'
import { FileText } from 'lucide-react'
import { useAuth } from '@/store/authStore'

const PER_PAGE = 20

type FilterState = {
  search: string
  cardId?: number
  page: number
}

export function FormsPage() {
  const { user } = useAuth()
  const [filters, setFilters] = useState<FilterState>({ search: '', page: 1 })
  const debouncedSearch = useDebounce(filters.search, 400)

  const supervisorId = user?.role === 'supervisor' ? user.id : undefined
  const agentId = user?.role === 'agent' ? user.id : undefined
  const assignedCardIds = useMemo(
    () => (user?.assigned_cards ? [...user.assigned_cards] : []),
    [user?.assigned_cards]
  )

  useEffect(() => {
    setFilters((prev) => ({ ...prev, page: 1 }))
  }, [debouncedSearch, filters.cardId])

  useEffect(() => {
    if (user?.role !== 'supervisor' && user?.role !== 'agent') {
      return
    }

    if (filters.cardId && !assignedCardIds.length) {
      setFilters((prev) => ({ ...prev, cardId: undefined }))
      return
    }

    if (filters.cardId && !assignedCardIds.includes(filters.cardId)) {
      setFilters((prev) => ({ ...prev, cardId: undefined }))
    }
  }, [assignedCardIds, filters.cardId, user?.role])

  const cardsQuery = useQuery({
    queryKey: ['cards', { per_page: 100 }],
    queryFn: async () => {
      const response = await cardsApi.getCards({ per_page: 100 })
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در دریافت کارت‌ها')
      }
      return response.data as CardListResponse
    },
  })

  const formsQuery = useQuery({
    queryKey: [
      'forms',
      {
        page: filters.page,
        per_page: PER_PAGE,
        card_id: filters.cardId,
        supervisor_id: supervisorId,
        agent_id: agentId,
      },
    ],
    queryFn: async () => {
      const params: FormFilters = {
        page: filters.page,
        per_page: PER_PAGE,
      }
      if (filters.cardId) {
        params.card_id = filters.cardId
      }
      if (supervisorId) {
        params.supervisor_id = supervisorId
      }
      if (agentId) {
        params.agent_id = agentId
      }

      const response = await formsApi.getForms(params)
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در دریافت فرم‌ها')
      }
      return response.data as FormListResponse
    },
    placeholderData: (previousData) => previousData,
  })

  const cards = cardsQuery.data?.items ?? []
  const availableCards = useMemo(() => {
    if (user?.role === 'supervisor') {
      if (!assignedCardIds.length) {
        return []
      }
      return cards.filter((card) => assignedCardIds.includes(card.id))
    }
    if (user?.role === 'agent' && user?.supervisor_id) {
      // Agents should also only see cards related to their supervisor assignments if available
      if (!assignedCardIds.length) {
        return cards
      }
      return cards.filter((card) => assignedCardIds.includes(card.id))
    }
    return cards
  }, [assignedCardIds, cards, user?.role, user?.supervisor_id])

  const forms = formsQuery.data?.items ?? []
  const accessibleForms = useMemo(() => {
    return forms.filter((form: FormSubmission) => {
      if (supervisorId) {
        if (form.meta.supervisor_id && form.meta.supervisor_id !== supervisorId) {
          return false
        }
        if (assignedCardIds.length && !assignedCardIds.includes(form.meta.card_id)) {
          return false
        }
      }
      if (agentId) {
        return form.meta.agent_id === agentId
      }
      return true
    })
  }, [agentId, assignedCardIds, forms, supervisorId])
  const pagination = formsQuery.data?.pagination
  const totalPages = pagination?.total_pages ?? 1
  const totalForms = pagination?.total ?? 0

  const filteredForms = useMemo(() => {
    if (!debouncedSearch) {
      return accessibleForms
    }
    const keyword = debouncedSearch.toLowerCase()
    return accessibleForms.filter((form: FormSubmission) => {
      if (form.title?.toLowerCase().includes(keyword)) {
        return true
      }
      if (form.meta?.meta) {
        const values = Object.values(form.meta.meta)
        if (values.some((value) => String(value).toLowerCase().includes(keyword))) {
          return true
        }
      }
      return false
    })
  }, [accessibleForms, debouncedSearch])

  const isLoading = formsQuery.isLoading
  const isError = formsQuery.isError

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">فرم‌ها</h1>
          <p className="text-muted-foreground">
            لیست فرم‌های ارسال‌شده از مشتریان و جزئیات مربوط به کارت‌ها
          </p>
        </div>
        <Badge variant="outline">{formatNumber(totalForms)} فرم ثبت شده</Badge>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>فیلترها</CardTitle>
          <CardDescription>بر اساس کارت یا متن فرم جست‌وجو کنید</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-4 md:grid-cols-2">
          <div className="relative">
            <Input
              value={filters.search}
              onChange={(event) => setFilters((prev) => ({ ...prev, search: event.target.value }))}
              placeholder="جست‌وجو در عنوان یا محتوا"
            />
          </div>
          <div>
            <select
              className="h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring"
              value={filters.cardId ?? ''}
              onChange={(event) =>
                setFilters((prev) => ({
                  ...prev,
                  cardId: event.target.value ? Number(event.target.value) : undefined,
                }))
              }
            >
              <option value="">همه کارت‌ها</option>
              {availableCards.map((card) => (
                <option key={card.id} value={card.id}>
                  {card.title}
                </option>
              ))}
            </select>
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
                    <div className="h-4 w-1/3 animate-pulse rounded bg-muted" />
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
            <CardTitle>خطا در بارگذاری فرم‌ها</CardTitle>
            <CardDescription>{getErrorMessage(formsQuery.error)}</CardDescription>
          </CardHeader>
        </Card>
      ) : filteredForms.length === 0 ? (
        <Card>
          <CardContent className="p-12 text-center text-muted-foreground">
            <FileText className="mx-auto mb-3 h-10 w-10" />
            <p>فرمی مطابق با فیلترهای فعلی یافت نشد.</p>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4">
          {filteredForms.map((form: FormSubmission) => (
            <FormCard key={form.id} form={form} cardMap={cards} />
          ))}
        </div>
      )}

      {pagination && filteredForms.length > 0 && (
        <div className="flex items-center justify-between">
          <span className="text-sm text-muted-foreground">
            صفحه {formatNumber(filters.page)} از {formatNumber(totalPages)}
          </span>
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              onClick={() => setFilters((prev) => ({ ...prev, page: Math.max(prev.page - 1, 1) }))}
              disabled={filters.page === 1 || formsQuery.isFetching}
            >
              قبلی
            </Button>
            <Button
              variant="outline"
              onClick={() =>
                setFilters((prev) => ({
                  ...prev,
                  page: Math.min(prev.page + 1, totalPages),
                }))
              }
              disabled={filters.page === totalPages || formsQuery.isFetching}
            >
              بعدی
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}

function FormCard({
  form,
  cardMap,
}: {
  form: FormSubmission
  cardMap: CardListResponse['items']
}) {
  const cardTitle = useMemo(() => {
    const card = cardMap.find((item) => item.id === form.meta.card_id)
    return card ? card.title : `کارت #${form.meta.card_id}`
  }, [cardMap, form.meta.card_id])

  return (
    <Card>
      <CardContent className="space-y-4 p-6">
        <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
          <div className="flex items-center gap-3">
            <div className="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary">
              <FileText className="h-5 w-5" />
            </div>
            <div>
              <h3 className="text-base font-semibold text-foreground">{form.title || `فرم #${form.id}`}</h3>
              <p className="text-sm text-muted-foreground">کارت: {cardTitle}</p>
            </div>
          </div>
          <div className="text-sm text-muted-foreground">
            <p>ارسال شده در:</p>
            <p className="font-medium text-foreground">{formatDateTime(form.created_at)}</p>
          </div>
        </div>

        <div className="grid gap-3 md:grid-cols-2">
          <MetaRow label="کد" value={form.meta.code || '-'} />
          <MetaRow label="تاریخ" value={form.meta.date || '-'} />
          <MetaRow label="زمان" value={form.meta.time || '-'} />
          <MetaRow label="شگفتانه" value={form.meta.surprise || '-'} />
          <MetaRow label="شناسه مشتری" value={form.meta.user_id ? `#${form.meta.user_id}` : '-'} />
          <MetaRow label="شناسه سرپرست" value={form.meta.supervisor_id ? `#${form.meta.supervisor_id}` : '-'} />
          <MetaRow label="شناسه کارشناس" value={form.meta.agent_id ? `#${form.meta.agent_id}` : '-'} />
        </div>

        {form.meta.meta && Object.keys(form.meta.meta).length > 0 && (
          <div className="space-y-2">
            <h4 className="text-sm font-medium text-foreground">فیلدهای فرم</h4>
            <div className="grid gap-3 md:grid-cols-2">
              {Object.entries(form.meta.meta).map(([key, value]) => (
                <MetaRow key={key} label={key} value={String(value)} />
              ))}
            </div>
          </div>
        )}
      </CardContent>
    </Card>
  )
}

function MetaRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between gap-2 rounded-md border px-3 py-2">
      <span className="text-xs text-muted-foreground">{label}</span>
      <span className="text-sm font-medium text-foreground">{value}</span>
    </div>
  )
}
