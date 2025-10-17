import { useEffect, useMemo, useState, type ChangeEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
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
import { StatusBadge } from '@/components/ui/status-badge'
import { NoteDialog } from '@/components/customers/note-dialog'
import { AssignmentDialog } from '@/components/customers/assignment-dialog'
import { cardsApi, customersApi } from '@/lib/api'
import { useAuth } from '@/store/authStore'
import { useNotification } from '@/store/uiStore'
import { useDebounce } from '@/hooks/useDebounce'
import {
  CardField,
  Customer,
  CustomerListResponse,
  CustomerStatus,
  CustomerTabsResponse,
} from '@/types'
import { formatDateTime, formatNumber, getErrorMessage } from '@/lib/utils'
import {
  Filter,
  Loader2,
  MessageSquare,
  Search,
  Send,
  UserCheck,
  ExternalLink,
} from 'lucide-react'

type StatusFilter = 'all' | CustomerStatus

const ALL_STATUSES: CustomerStatus[] = [
  'normal',
  'upsell',
  'upsell_pending',
  'upsell_paid',
  'no_answer',
  'canceled',
]

const STATUS_LABELS: Record<CustomerStatus, string> = {
  normal: 'عادی',
  upsell: 'خرید افزایشی',
  upsell_pending: 'خرید افزایشی در انتظار پرداخت',
  upsell_paid: 'خرید افزایشی پرداخت شده',
  no_answer: 'جواب نداد',
  canceled: 'انصراف داد',
}

interface StatusTab {
  key: StatusFilter
  label: string
  count?: number
}

export interface CustomerManagementViewProps {
  title: string
  description: string
  perPage?: number
  baseFilters?: Record<string, string | number | undefined>
  emptyStateMessage?: string
  assignmentTypes?: Array<'supervisor' | 'agent'>
  supervisorFilterForAgents?: number
  allowNotes?: boolean
  showStatusTabs?: boolean
  syncStatusWithUrl?: boolean
}

export function CustomerManagementView({
  title,
  description,
  perPage = 12,
  baseFilters = {},
  emptyStateMessage = 'مشتری مطابق با فیلترهای فعلی یافت نشد.',
  assignmentTypes = ['supervisor', 'agent'],
  supervisorFilterForAgents,
  allowNotes = true,
  showStatusTabs = true,
  syncStatusWithUrl = false,
}: CustomerManagementViewProps) {
  const { user } = useAuth()
  const { success: notifySuccess, error: notifyError, info: notifyInfo } = useNotification()
  const queryClient = useQueryClient()

  const [searchParams, setSearchParams] = useSearchParams()
  const [searchTerm, setSearchTerm] = useState('')
  const [selectedStatus, setSelectedStatus] = useState<StatusFilter>('all')
  const [page, setPage] = useState(1)

  const [noteDialogCustomer, setNoteDialogCustomer] = useState<Customer | null>(null)
  const [assignmentDialog, setAssignmentDialog] = useState<{
    type: 'supervisor' | 'agent'
    customer: Customer
  } | null>(null)

  const debouncedSearch = useDebounce(searchTerm, 450)

  useEffect(() => {
    if (!syncStatusWithUrl) {
      return
    }

    const statusParam = searchParams.get('status')

    if (!statusParam) {
      if (selectedStatus !== 'all') {
        setSelectedStatus('all')
      }
      return
    }

    if (statusParam === 'all') {
      if (selectedStatus !== 'all') {
        setSelectedStatus('all')
      }
      return
    }

    if (ALL_STATUSES.includes(statusParam as CustomerStatus) && selectedStatus !== (statusParam as StatusFilter)) {
      setSelectedStatus(statusParam as StatusFilter)
    }
  }, [searchParams, selectedStatus, syncStatusWithUrl])

  useEffect(() => {
    setPage(1)
  }, [selectedStatus, debouncedSearch, baseFilters])

  const normalizedBaseFilters = useMemo(() => {
    const entries = Object.entries(baseFilters ?? {})
    return entries.reduce<Record<string, string | number>>((accumulator, [key, value]) => {
      if (value !== undefined && value !== null && value !== '') {
        accumulator[key] = value as string | number
      }
      return accumulator
    }, {})
  }, [baseFilters])

  const apiFilters = useMemo(() => {
    const params: Record<string, string | number> = {
      page,
      per_page: perPage,
      ...normalizedBaseFilters,
    }

    if (selectedStatus !== 'all') {
      params.status = selectedStatus
    }

    if (debouncedSearch) {
      params.search = debouncedSearch
    }

    return params
  }, [page, perPage, selectedStatus, debouncedSearch, normalizedBaseFilters])

  const customersQuery = useQuery({
    queryKey: ['customers', apiFilters],
    queryFn: async () => {
      const response = await customersApi.getCustomers(apiFilters)
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در دریافت مشتریان')
      }
      return response.data as CustomerListResponse
    },
    placeholderData: (previousData) => previousData,
  })

  const tabsQuery = useQuery({
    queryKey: ['customers', 'tabs'],
    queryFn: async () => {
      const response = await customersApi.getCustomerTabs()
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در دریافت تب‌ها')
      }
      return response.data as CustomerTabsResponse
    },
    staleTime: 1000 * 60,
    enabled: showStatusTabs,
  })

  const customers = customersQuery.data?.items ?? []
  const pagination = customersQuery.data?.pagination
  const totalPages = pagination?.total_pages ?? 1
  const totalCustomers = pagination?.total ?? 0

  const statusTabs: StatusTab[] = useMemo(() => {
    const tabData = tabsQuery.data?.tabs ?? {}
    return [
      { key: 'all', label: 'همه', count: totalCustomers },
      { key: 'upsell_pending', label: 'در انتظار پرداخت', count: tabData['upsell_pending']?.total },
      { key: 'upsell_paid', label: 'پرداخت شده', count: tabData['upsell_paid']?.total },
      { key: 'upsell', label: 'فروش افزایشی' },
      { key: 'normal', label: 'عادی' },
      { key: 'no_answer', label: 'جواب نداد' },
      { key: 'canceled', label: 'انصراف داد' },
    ]
  }, [tabsQuery.data, totalCustomers])

  const handleChangeStatusFilter = (status: StatusFilter) => {
    setSelectedStatus(status)
    if (syncStatusWithUrl) {
      const params = new URLSearchParams(searchParams)
      if (status === 'all') {
        params.delete('status')
      } else {
        params.set('status', status)
      }
      params.delete('page')
      setSearchParams(params)
    }
  }

  const updateStatusMutation = useMutation<
    unknown,
    unknown,
    { customerId: number; status: CustomerStatus; reason?: string; meta?: Record<string, unknown> }
  >({
    mutationFn: async ({
      customerId,
      status,
      reason,
      meta,
    }: {
      customerId: number
      status: CustomerStatus
      reason?: string
      meta?: Record<string, unknown>
    }) => {
      const response = await customersApi.updateCustomerStatus(customerId, status, { reason, meta })
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در تغییر وضعیت')
      }
      return response.data
    },
    onSuccess: () => {
      notifySuccess('موفق', 'وضعیت مشتری بروزرسانی شد')
      queryClient.invalidateQueries({ queryKey: ['customers'] })
      queryClient.invalidateQueries({ queryKey: ['customers', 'tabs'] })
    },
    onError: (error) => {
      notifyError('خطا در تغییر وضعیت', getErrorMessage(error))
    },
  })

  const sendNormalCodeMutation = useMutation<unknown, unknown, number>({
    mutationFn: async (customerId: number) => {
      const response = await customersApi.sendNormalCode(customerId)
      if (!response.success) {
        throw new Error(response.error?.message || 'ارسال پیامک ناموفق بود')
      }
      return response.data
    },
    onSuccess: () => {
      notifySuccess('پیامک ارسال شد', 'کد عادی برای مشتری ارسال گردید')
    },
    onError: (error) => {
      notifyError('خطا در ارسال پیامک', getErrorMessage(error))
    },
  })

  const addNoteMutation = useMutation({
    mutationFn: async ({ customerId, note }: { customerId: number; note: string }) => {
      const response = await customersApi.addCustomerNote(customerId, note)
      if (!response.success) {
        throw new Error(response.error?.message || 'ثبت یادداشت ناموفق بود')
      }
      return response.data
    },
    onSuccess: () => {
      notifySuccess('یادداشت ثبت شد', 'یادداشت شما ذخیره شد')
      queryClient.invalidateQueries({ queryKey: ['customers'] })
    },
    onError: (error) => {
      notifyError('خطا در ثبت یادداشت', getErrorMessage(error))
    },
  })

  const assignSupervisorMutation = useMutation({
    mutationFn: async ({ customerId, supervisorId }: { customerId: number; supervisorId: number }) => {
      const response = await customersApi.assignSupervisor(customerId, supervisorId)
      if (!response.success) {
        throw new Error(response.error?.message || 'تخصیص سرپرست ناموفق بود')
      }
      return response.data
    },
    onSuccess: () => {
      notifySuccess('سرپرست بروزرسانی شد', 'سرپرست مشتری تغییر یافت')
      queryClient.invalidateQueries({ queryKey: ['customers'] })
    },
    onError: (error) => {
      notifyError('خطا در تغییر سرپرست', getErrorMessage(error))
    },
  })

  const assignAgentMutation = useMutation({
    mutationFn: async ({ customerId, agentId }: { customerId: number; agentId: number }) => {
      const response = await customersApi.assignAgent(customerId, agentId)
      if (!response.success) {
        throw new Error(response.error?.message || 'تخصیص کارشناس ناموفق بود')
      }
      return response.data
    },
    onSuccess: () => {
      notifySuccess('کارشناس بروزرسانی شد', 'کارشناس مشتری تغییر یافت')
      queryClient.invalidateQueries({ queryKey: ['customers'] })
    },
    onError: (error) => {
      notifyError('خطا در تغییر کارشناس', getErrorMessage(error))
    },
  })

  const initUpsellMutation = useMutation<
    { pay_link?: string },
    unknown,
    { customerId: number; cardId: number; fieldKey: string }
  >({
    mutationFn: async ({ customerId, cardId, fieldKey }) => {
      const response = await customersApi.initUpsell(customerId, cardId, fieldKey)
      if (!response.success) {
        throw new Error(response.error?.message || 'ایجاد فروش افزایشی ناموفق بود')
      }
      return response.data
    },
    onSuccess: (data) => {
      notifySuccess('سفارش ایجاد شد', 'لینک پرداخت ایجاد و پیامک ارسال شد')
      if (data?.pay_link) {
        notifyInfo('لینک پرداخت', data.pay_link)
      }
      queryClient.invalidateQueries({ queryKey: ['customers'] })
      queryClient.invalidateQueries({ queryKey: ['customers', 'tabs'] })
    },
    onError: (error) => {
      notifyError('خطا در فروش افزایشی', getErrorMessage(error))
    },
  })

  const assignmentEnabled = useMemo(
    () => ({
      supervisor: assignmentTypes.includes('supervisor'),
      agent: assignmentTypes.includes('agent'),
    }),
    [assignmentTypes]
  )

  const isMutating =
    updateStatusMutation.isPending ||
    sendNormalCodeMutation.isPending ||
    initUpsellMutation.isPending ||
    assignAgentMutation.isPending ||
    assignSupervisorMutation.isPending ||
    addNoteMutation.isPending

  const getCustomerName = (customer: Customer) =>
    customer.display_name || customer.email || `مشتری #${customer.id}`

  const handleStatusChange = async (customer: Customer, status: CustomerStatus) => {
    if (status === 'upsell' && !customer.card_id) {
      notifyError('کارت نامشخص', 'برای این مشتری کارت تعریف نشده است')
      throw new Error('missing_card')
    }

    await updateStatusMutation.mutateAsync({
      customerId: customer.id,
      status,
    })
  }

  const handleOpenNoteDialog = (customer: Customer) => {
    if (!allowNotes) return
    setNoteDialogCustomer(customer)
  }

  const handleNoteSubmit = async ({ note }: { note: string }) => {
    if (!noteDialogCustomer) return
    await addNoteMutation.mutateAsync({ customerId: noteDialogCustomer.id, note })
    setNoteDialogCustomer(null)
  }

  const handleOpenAssignment = (type: 'supervisor' | 'agent', customer: Customer) => {
    if (!assignmentEnabled[type]) return
    setAssignmentDialog({ type, customer })
  }

  const handleAssignmentSubmit = async (selectedId: number) => {
    if (!assignmentDialog) return

    if (assignmentDialog.type === 'supervisor') {
      await assignSupervisorMutation.mutateAsync({
        customerId: assignmentDialog.customer.id,
        supervisorId: selectedId,
      })
    } else {
      await assignAgentMutation.mutateAsync({
        customerId: assignmentDialog.customer.id,
        agentId: selectedId,
      })
    }
    setAssignmentDialog(null)
  }

  const handleSendNormalCode = async (customer: Customer) => {
    await sendNormalCodeMutation.mutateAsync(customer.id)
  }

  const handleStartUpsell = async (customer: Customer, fieldKey: string) => {
    if (!customer.card_id) {
      notifyError('کار نامشخص', 'برای این مشتری کارت تعریف نشده است')
      throw new Error('missing_card')
    }

    await initUpsellMutation.mutateAsync({
      customerId: customer.id,
      cardId: customer.card_id,
      fieldKey,
    })
  }

  const selectedStatusUpdatingId = updateStatusMutation.isPending
    ? updateStatusMutation.variables?.customerId ?? null
    : null

  const normalSmsCustomerId = sendNormalCodeMutation.isPending ? sendNormalCodeMutation.variables ?? null : null

  const upsellCustomerId = initUpsellMutation.isPending ? initUpsellMutation.variables?.customerId ?? null : null

  return (
    <div className="space-y-6">
      <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">{title}</h1>
          <p className="text-muted-foreground">{description}</p>
        </div>

        <div className="flex flex-col sm:flex-row sm:items-center gap-3">
          <div className="relative sm:w-64">
            <Search className="absolute right-3 top-2.5 h-4 w-4 text-muted-foreground" />
            <Input
              placeholder="جست‌وجو در مشتریان..."
              className="pr-9"
              value={searchTerm}
              onChange={(event) => setSearchTerm(event.target.value)}
            />
          </div>
          <Button variant="outline" className="gap-2" disabled>
            <Filter className="h-4 w-4" />
            فیلتر پیشرفته (به‌زودی)
          </Button>
        </div>
      </div>

      <div className="flex flex-wrap gap-2">
        {statusTabs.map((tab) => (
          <Button
            key={tab.key}
            variant={selectedStatus === tab.key ? 'default' : 'outline'}
            onClick={() => handleChangeStatusFilter(tab.key)}
            className="gap-2"
          >
            <span>{tab.label}</span>
            {typeof tab.count === 'number' && (
              <Badge variant={selectedStatus === tab.key ? 'secondary' : 'outline'}>
                {formatNumber(tab.count)}
              </Badge>
            )}
          </Button>
        ))}
      </div>

      <Card>
        <CardHeader>
          <CardTitle>لیست مشتریان</CardTitle>
          <CardDescription>
            مجموع {formatNumber(totalCustomers)} مشتری
          </CardDescription>
        </CardHeader>
        <CardContent>
          {customersQuery.isLoading ? (
            <div className="space-y-3">
              {Array.from({ length: 6 }).map((_, index) => (
                <div key={index} className="animate-pulse rounded-lg border border-dashed bg-muted/20 p-6">
                  <div className="flex flex-col gap-4">
                    <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                      <div className="flex items-center gap-3">
                        <div className="h-10 w-10 rounded-full bg-muted" />
                        <div className="space-y-2">
                          <div className="h-4 w-32 rounded bg-muted" />
                          <div className="h-3 w-24 rounded bg-muted" />
                        </div>
                      </div>
                      <div className="h-9 w-48 rounded bg-muted" />
                    </div>
                    <div className="grid gap-2 md:grid-cols-3">
                      <div className="h-3 rounded bg-muted" />
                      <div className="h-3 rounded bg-muted" />
                      <div className="h-3 rounded bg-muted" />
                    </div>
                  </div>
                </div>
              ))}
            </div>
          ) : customersQuery.isError ? (
            <div className="rounded-lg border border-dashed p-8 text-center text-sm text-destructive">
              {getErrorMessage(customersQuery.error)}
            </div>
          ) : customers.length === 0 ? (
            <div className="rounded-lg border border-dashed p-12 text-center text-muted-foreground">
              {emptyStateMessage}
            </div>
          ) : (
            <div className="space-y-3">
              {customers.map((customer: Customer) => (
                <CustomerRow
                  key={customer.id}
                  customer={customer}
                  disabled={isMutating}
                  onStatusChange={handleStatusChange}
                  onSendNormalCode={handleSendNormalCode}
                  onStartUpsell={handleStartUpsell}
                  onOpenNoteDialog={allowNotes ? handleOpenNoteDialog : undefined}
                  onOpenAssignSupervisor={
                    assignmentEnabled.supervisor ? (c) => handleOpenAssignment('supervisor', c) : undefined
                  }
                  onOpenAssignAgent={assignmentEnabled.agent ? (c) => handleOpenAssignment('agent', c) : undefined}
                  statusUpdatingId={selectedStatusUpdatingId}
                  normalSmsCustomerId={normalSmsCustomerId}
                  upsellCustomerId={upsellCustomerId}
                />
              ))}
            </div>
          )}

          {pagination && customers.length > 0 && (
            <div className="mt-6 flex items-center justify-between border-t pt-6">
              <span className="text-sm text-muted-foreground">
                صفحه {formatNumber(page)} از {formatNumber(totalPages)}
              </span>
              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  onClick={() => setPage((prev) => Math.max(prev - 1, 1))}
                  disabled={page === 1 || customersQuery.isFetching}
                >
                  قبلی
                </Button>
                <Button
                  variant="outline"
                  onClick={() => setPage((prev) => Math.min(prev + 1, totalPages))}
                  disabled={page === totalPages || customersQuery.isFetching}
                >
                  بعدی
                </Button>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      {allowNotes && (
        <NoteDialog
          open={Boolean(noteDialogCustomer)}
          onOpenChange={(open) => {
            if (!open) setNoteDialogCustomer(null)
          }}
          customerName={noteDialogCustomer ? getCustomerName(noteDialogCustomer) : ''}
          onSubmit={handleNoteSubmit}
          isSubmitting={addNoteMutation.isPending}
        />
      )}

      {(assignmentEnabled.supervisor || assignmentEnabled.agent) && (
        <AssignmentDialog
          open={Boolean(assignmentDialog)}
          onOpenChange={(open) => {
            if (!open) setAssignmentDialog(null)
          }}
          type={assignmentDialog?.type ?? 'supervisor'}
          customerName={assignmentDialog ? getCustomerName(assignmentDialog.customer) : ''}
          isSubmitting={
            assignmentDialog?.type === 'supervisor'
              ? assignSupervisorMutation.isPending
              : assignAgentMutation.isPending
          }
          onSubmit={handleAssignmentSubmit}
          supervisorFilter={
            assignmentDialog?.type === 'agent'
              ? supervisorFilterForAgents ?? (user?.role === 'supervisor' ? user.id : undefined)
              : undefined
          }
        />
      )}
    </div>
  )
}

interface CustomerRowProps {
  customer: Customer
  disabled: boolean
  onStatusChange: (customer: Customer, status: CustomerStatus) => Promise<void>
  onSendNormalCode: (customer: Customer) => Promise<void>
  onStartUpsell: (customer: Customer, fieldKey: string) => Promise<void>
  onOpenNoteDialog?: (customer: Customer) => void
  onOpenAssignSupervisor?: (customer: Customer) => void
  onOpenAssignAgent?: (customer: Customer) => void
  statusUpdatingId: number | null
  normalSmsCustomerId: number | null
  upsellCustomerId: number | null
}

function CustomerRow({
  customer,
  disabled,
  onStatusChange,
  onSendNormalCode,
  onStartUpsell,
  onOpenNoteDialog,
  onOpenAssignSupervisor,
  onOpenAssignAgent,
  statusUpdatingId,
  normalSmsCustomerId,
  upsellCustomerId,
}: CustomerRowProps) {
  const [selectedStatus, setSelectedStatus] = useState<CustomerStatus>(customer.status)
  const [selectedField, setSelectedField] = useState<string>(customer.upsell_field_key ?? '')

  const isStatusUpdating = statusUpdatingId === customer.id
  const isNormalSending = normalSmsCustomerId === customer.id
  const isUpsellSubmitting = upsellCustomerId === customer.id
  const rowDisabled = disabled || isStatusUpdating

  const showUpsellControls = selectedStatus === 'upsell'
  const showNoteButton = typeof onOpenNoteDialog === 'function'
  const showAssignSupervisorButton = typeof onOpenAssignSupervisor === 'function'
  const showAssignAgentButton = typeof onOpenAssignAgent === 'function'

  const {
    data: cardFields = [],
    isLoading: fieldsLoading,
    isError: fieldsError,
    error: fieldsErrorObject,
  } = useQuery<CardField[]>({
    queryKey: ['card-fields', customer.card_id],
    enabled: showUpsellControls && customer.card_id > 0,
    queryFn: async () => {
      const response = await cardsApi.getCardFields(customer.card_id)
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در دریافت فیلدهای کارت')
      }
      return response.data?.fields ?? []
    },
  })

  const fields = cardFields
  const selectedFieldMeta = fields.find((field) => field.key === selectedField)

  useEffect(() => {
    setSelectedStatus(customer.status)
    if (customer.status !== 'upsell') {
      setSelectedField('')
    }
  }, [customer.status])

  const handleStatusSelect = async (event: ChangeEvent<HTMLSelectElement>) => {
    const nextStatus = event.target.value as CustomerStatus
    if (nextStatus === selectedStatus) {
      return
    }

    setSelectedStatus(nextStatus)

    try {
      await onStatusChange(customer, nextStatus)
      if (nextStatus !== 'upsell') {
        setSelectedField('')
      }
    } catch (error) {
      setSelectedStatus(customer.status)
      if (customer.status !== 'upsell') {
        setSelectedField('')
      }
    }
  }

  const handleNormalSms = async () => {
    await onSendNormalCode(customer)
  }

  const handleUpsellSubmit = async () => {
    if (!selectedField) {
      return
    }

    try {
      await onStartUpsell(customer, selectedField)
      setSelectedField('')
      setSelectedStatus('upsell_pending')
    } catch (error) {
      // handled upstream
    }
  }

  return (
    <div className="rounded-lg border border-border bg-card/40 p-4 shadow-sm">
      <div className="flex flex-col gap-4 md:flex-row md:items-start md:gap-6">
        <div className="w-full md:w-72">
          <span className="mb-2 block text-xs font-semibold text-muted-foreground">تغییر وضعیت</span>
          <div className="flex flex-col gap-2">
            <div className="flex flex-col items-stretch gap-2 sm:flex-row">
              <select
                className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary sm:w-auto"
                value={selectedStatus}
                onChange={handleStatusSelect}
                disabled={rowDisabled}
              >
                {ALL_STATUSES.map((status) => (
                  <option key={status} value={status}>
                    {STATUS_LABELS[status]}
                  </option>
                ))}
              </select>

              {selectedStatus === 'normal' && (
                <Button
                  variant="outline"
                  size="sm"
                  className="sm:w-auto"
                  disabled={rowDisabled || isNormalSending}
                  onClick={handleNormalSms}
                >
                  {isNormalSending ? (
                    <Loader2 className="h-4 w-4 animate-spin" />
                  ) : (
                    <Send className="h-4 w-4" />
                  )}
                  <span className="ml-2">ارسال پیامک</span>
                </Button>
              )}
            </div>
          </div>
        </div>

        <div className="flex-1 space-y-2">
          <div className="flex flex-wrap items-center gap-2">
            <h3 className="text-base font-semibold text-foreground">
              {customer.display_name || customer.email}
            </h3>
            <StatusBadge status={customer.status} />
          </div>
          <p className="text-sm text-muted-foreground">{customer.email}</p>
          <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
            {customer.card_title && <Badge variant="secondary">کارت: {customer.card_title}</Badge>}
            {customer.assigned_supervisor_name && (
              <Badge variant="outline">سرپرست: {customer.assigned_supervisor_name}</Badge>
            )}
            {customer.assigned_agent_name && (
              <Badge variant="outline">کارشناس: {customer.assigned_agent_name}</Badge>
            )}
            {customer.upsell_order_id ? (
              <Badge variant="outline">سفارش #{formatNumber(customer.upsell_order_id)}</Badge>
            ) : null}
          </div>
        </div>
      </div>

      {showUpsellControls && (
        <div className="mt-4 space-y-3 rounded-lg border border-dashed bg-muted/20 p-4">
          {customer.card_id ? (
            fieldsLoading ? (
              <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Loader2 className="h-4 w-4 animate-spin" />
                <span>در حال دریافت فیلدها...</span>
              </div>
            ) : fieldsError ? (
              <p className="text-sm text-destructive">{getErrorMessage(fieldsErrorObject)}</p>
            ) : fields.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                فیلدی برای این کارت ثبت نشده است. ابتدا گزینه‌های فروش افزایشی کارت را تعریف کنید.
              </p>
            ) : (
              <div className="flex flex-col gap-2 md:flex-row md:items-center md:gap-4">
                <select
                  className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary md:w-80"
                  value={selectedField}
                  onChange={(event) => setSelectedField(event.target.value)}
                  disabled={isUpsellSubmitting}
                >
                  <option value="">یکی از گزینه‌ها را انتخاب کنید</option>
                  {fields.map((field) => (
                    <option key={field.key} value={field.key}>
                      {field.label} - {formatNumber(field.amount)} تومان
                    </option>
                  ))}
                </select>
                <Button
                  size="sm"
                  disabled={isUpsellSubmitting || !selectedField}
                  onClick={handleUpsellSubmit}
                >
                  {isUpsellSubmitting ? (
                    <Loader2 className="h-4 w-4 animate-spin" />
                  ) : (
                    <Send className="h-4 w-4" />
                  )}
                  <span className="ml-2">ارسال پیامک پرداخت</span>
                </Button>
              </div>
            )
          ) : (
            <p className="text-sm text-destructive">برای این مشتری کارت ثبت نشده است.</p>
          )}

          {selectedFieldMeta && (
            <div className="flex flex-col gap-1 rounded-md border border-primary/20 bg-primary/5 px-3 py-2 text-sm text-primary">
              <span>گزینه انتخاب‌شده: {selectedFieldMeta.label}</span>
              <span>مبلغ: {formatNumber(selectedFieldMeta.amount)} تومان</span>
            </div>
          )}
        </div>
      )}

      <div className="mt-4 grid gap-2 text-xs text-muted-foreground md:grid-cols-3">
        {customer.phone && (
          <div className="flex items-center justify-between">
            <span>تلفن:</span>
            <span className="font-medium text-foreground">{customer.phone}</span>
          </div>
        )}
        {customer.registered_at && (
          <div className="flex items-center justify-between">
            <span>تاریخ ثبت‌نام:</span>
            <span className="font-medium text-foreground">{formatDateTime(customer.registered_at)}</span>
          </div>
        )}
        {customer.upsell_pay_link && (
          <div className="col-span-1 md:col-span-3">
            <span className="text-xs">آخرین لینک پرداخت:</span>
            <div className="mt-1 truncate text-[0.75rem] text-primary">
              <a
                href={customer.upsell_pay_link}
                target="_blank"
                rel="noreferrer"
                className="inline-flex items-center gap-1"
              >
                <ExternalLink className="h-3 w-3" />
                <span className="truncate">{customer.upsell_pay_link}</span>
              </a>
            </div>
          </div>
        )}
      </div>

      {(showNoteButton || showAssignSupervisorButton || showAssignAgentButton) && (
        <div className="mt-4 flex flex-wrap justify-end gap-2">
          {showNoteButton && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => onOpenNoteDialog?.(customer)}
              disabled={disabled}
            >
              <MessageSquare className="h-4 w-4" />
              <span className="ml-2">یادداشت</span>
            </Button>
          )}
          {showAssignSupervisorButton && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => onOpenAssignSupervisor?.(customer)}
              disabled={disabled}
            >
              <UserCheck className="h-4 w-4" />
              <span className="ml-2">سرپرست</span>
            </Button>
          )}
          {showAssignAgentButton && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => onOpenAssignAgent?.(customer)}
              disabled={disabled}
            >
              <UserCheck className="h-4 w-4" />
              <span className="ml-2">کارشناس</span>
            </Button>
          )}
        </div>
      )}
    </div>
  )
}
