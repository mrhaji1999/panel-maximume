import { useEffect, useMemo, useState, type ComponentType } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
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
import { StatusDialog } from '@/components/customers/status-dialog'
import { AssignmentDialog } from '@/components/customers/assignment-dialog'
import { UpsellDrawer } from '@/components/customers/upsell-drawer'
import {
  customersApi,
} from '@/lib/api'
import { useAuth } from '@/store/authStore'
import { useNotification } from '@/store/uiStore'
import { useDebounce } from '@/hooks/useDebounce'
import {
  Customer,
  CustomerListResponse,
  CustomerStatus,
  CustomerTabsResponse,
} from '@/types'
import {
  cn,
  formatDateTime,
  formatNumber,
  getErrorMessage,
} from '@/lib/utils'
import {
  Search,
  Filter,
  MoreHorizontal,
  Send,
  TrendingUp,
  UserCheck,
  MessageSquare,
  PenTool,
} from 'lucide-react'

const PER_PAGE = 12
type StatusFilter = 'all' | CustomerStatus

const ALL_STATUSES: CustomerStatus[] = [
  'normal',
  'upsell',
  'upsell_pending',
  'upsell_paid',
  'no_answer',
  'canceled',
]

interface StatusTab {
  key: StatusFilter
  label: string
  count?: number
}

export function CustomersPage() {
  const { user } = useAuth()
  const { success: notifySuccess, error: notifyError, info: notifyInfo } = useNotification()
  const queryClient = useQueryClient()

  const [searchTerm, setSearchTerm] = useState('')
  const [selectedStatus, setSelectedStatus] = useState<StatusFilter>('all')
  const [page, setPage] = useState(1)

  const [statusDialog, setStatusDialog] = useState<{
    customer: Customer
    statuses: CustomerStatus[]
  } | null>(null)
  const [noteDialogCustomer, setNoteDialogCustomer] = useState<Customer | null>(null)
  const [assignmentDialog, setAssignmentDialog] = useState<{
    type: 'supervisor' | 'agent'
    customer: Customer
  } | null>(null)
  const [upsellCustomer, setUpsellCustomer] = useState<Customer | null>(null)

  const debouncedSearch = useDebounce(searchTerm, 450)

  useEffect(() => {
    setPage(1)
  }, [selectedStatus, debouncedSearch])

  const apiFilters = useMemo(() => {
    const params: Record<string, string | number> = {
      page,
      per_page: PER_PAGE,
    }

    if (selectedStatus !== 'all') {
      params.status = selectedStatus
    }

    if (debouncedSearch) {
      params.search = debouncedSearch
    }

    return params
  }, [page, selectedStatus, debouncedSearch])

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

  const updateStatusMutation = useMutation({
    mutationFn: async ({
      customerId,
      status,
      reason,
    }: {
      customerId: number
      status: CustomerStatus
      reason?: string
    }) => {
      const response = await customersApi.updateCustomerStatus(customerId, status, reason)
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

  const sendNormalCodeMutation = useMutation({
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

  const initUpsellMutation = useMutation({
    mutationFn: async ({ customerId, cardId, fieldKey }: { customerId: number; cardId: number; fieldKey: string }) => {
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

  const getCustomerName = (customer: Customer) =>
    customer.display_name || customer.email || `مشتری #${customer.id}`

  const handleOpenStatusDialog = (customer: Customer) => {
    const statuses = ALL_STATUSES.filter((status) => status !== customer.status)
    setStatusDialog({
      customer,
      statuses: statuses.length > 0 ? statuses : ALL_STATUSES,
    })
  }

  const handleStatusDialogSubmit = async ({ status, reason }: { status: string; reason?: string }) => {
    if (!statusDialog?.customer) return
    await updateStatusMutation.mutateAsync({
      customerId: statusDialog.customer.id,
      status: status as CustomerStatus,
      reason: reason || undefined,
    })
    setStatusDialog(null)
  }

  const handleOpenNoteDialog = (customer: Customer) => {
    setNoteDialogCustomer(customer)
  }

  const handleNoteSubmit = async ({ note }: { note: string }) => {
    if (!noteDialogCustomer) return
    await addNoteMutation.mutateAsync({ customerId: noteDialogCustomer.id, note })
    setNoteDialogCustomer(null)
  }

  const handleOpenAssignment = (type: 'supervisor' | 'agent', customer: Customer) => {
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

  const handleOpenUpsell = (customer: Customer) => {
    if (!customer.card_id) {
      notifyError('کارت نامشخص', 'برای این مشتری کارت تعریف نشده است')
      return
    }
    setUpsellCustomer(customer)
  }

  const handleUpsellSubmit = async ({ field_key }: { field_key: string }) => {
    if (!upsellCustomer?.card_id) return
    await initUpsellMutation.mutateAsync({
      customerId: upsellCustomer.id,
      cardId: upsellCustomer.card_id,
      fieldKey: field_key,
    })
    setUpsellCustomer(null)
  }

  const handleSendNormalCode = (customer: Customer) => {
    sendNormalCodeMutation.mutate(customer.id)
  }

  const isMutating =
    updateStatusMutation.isPending ||
    sendNormalCodeMutation.isPending ||
    initUpsellMutation.isPending ||
    assignAgentMutation.isPending ||
    assignSupervisorMutation.isPending ||
    addNoteMutation.isPending

  return (
    <div className="space-y-6">
      <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">مدیریت مشتریان</h1>
          <p className="text-muted-foreground">
            مشاهده و مدیریت مشتریان بر اساس نقش و وضعیت
          </p>
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
            onClick={() => setSelectedStatus(tab.key)}
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
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
              {Array.from({ length: 6 }).map((_, index) => (
                <Card key={index} className="animate-pulse">
                  <CardContent className="space-y-4 p-6">
                    <div className="flex items-center gap-3">
                      <div className="h-12 w-12 rounded-full bg-muted" />
                      <div className="flex-1 space-y-2">
                        <div className="h-4 w-3/4 rounded bg-muted" />
                        <div className="h-3 w-1/2 rounded bg-muted" />
                      </div>
                    </div>
                    <div className="space-y-2">
                      <div className="h-3 w-full rounded bg-muted" />
                      <div className="h-3 w-5/6 rounded bg-muted" />
                    </div>
                  </CardContent>
                </Card>
              ))}
            </div>
          ) : customersQuery.isError ? (
            <div className="rounded-lg border border-dashed p-8 text-center text-sm text-destructive">
              {getErrorMessage(customersQuery.error)}
            </div>
          ) : customers.length === 0 ? (
            <div className="rounded-lg border border-dashed p-12 text-center text-muted-foreground">
              مشتری مطابق با فیلترهای فعلی یافت نشد.
            </div>
          ) : (
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
              {customers.map((customer: Customer) => (
                <CustomerCard
                  key={customer.id}
                  customer={customer}
                  disabled={isMutating}
                  onOpenStatusDialog={handleOpenStatusDialog}
                  onSendNormalCode={handleSendNormalCode}
                  onOpenNoteDialog={handleOpenNoteDialog}
                  onOpenAssignSupervisor={(c) => handleOpenAssignment('supervisor', c)}
                  onOpenAssignAgent={(c) => handleOpenAssignment('agent', c)}
                  onOpenUpsell={handleOpenUpsell}
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

      <StatusDialog
        open={Boolean(statusDialog)}
        onOpenChange={(open) => {
          if (!open) setStatusDialog(null)
        }}
        customerName={statusDialog ? getCustomerName(statusDialog.customer) : ''}
        currentStatus={statusDialog?.customer.status ?? 'normal'}
        selectableStatuses={statusDialog?.statuses ?? ALL_STATUSES}
        isSubmitting={updateStatusMutation.isPending}
        onSubmit={handleStatusDialogSubmit}
      />

      <NoteDialog
        open={Boolean(noteDialogCustomer)}
        onOpenChange={(open) => {
          if (!open) setNoteDialogCustomer(null)
        }}
        customerName={noteDialogCustomer ? getCustomerName(noteDialogCustomer) : ''}
        onSubmit={handleNoteSubmit}
        isSubmitting={addNoteMutation.isPending}
      />

      <AssignmentDialog
        open={Boolean(assignmentDialog)}
        onOpenChange={(open) => {
          if (!open) setAssignmentDialog(null)
        }}
        type={assignmentDialog?.type ?? 'supervisor'}
        customerName={
          assignmentDialog ? getCustomerName(assignmentDialog.customer) : ''
        }
        isSubmitting={
          assignmentDialog?.type === 'supervisor'
            ? assignSupervisorMutation.isPending
            : assignAgentMutation.isPending
        }
        onSubmit={handleAssignmentSubmit}
        supervisorFilter={user?.role === 'supervisor' ? user.id : undefined}
      />

      <UpsellDrawer
        open={Boolean(upsellCustomer)}
        onOpenChange={(open) => {
          if (!open) setUpsellCustomer(null)
        }}
        customerName={upsellCustomer ? getCustomerName(upsellCustomer) : ''}
        cardId={upsellCustomer?.card_id ?? 0}
        isSubmitting={initUpsellMutation.isPending}
        onSubmit={handleUpsellSubmit}
      />
    </div>
  )
}

interface CustomerCardProps {
  customer: Customer
  disabled: boolean
  onOpenStatusDialog: (customer: Customer) => void
  onSendNormalCode: (customer: Customer) => void
  onOpenNoteDialog: (customer: Customer) => void
  onOpenAssignSupervisor: (customer: Customer) => void
  onOpenAssignAgent: (customer: Customer) => void
  onOpenUpsell: (customer: Customer) => void
}

function CustomerCard({
  customer,
  disabled,
  onOpenStatusDialog,
  onSendNormalCode,
  onOpenNoteDialog,
  onOpenAssignSupervisor,
  onOpenAssignAgent,
  onOpenUpsell,
}: CustomerCardProps) {
  const [menuOpen, setMenuOpen] = useState(false)

  return (
    <Card className="relative overflow-hidden">
      <CardContent className="space-y-4 p-6">
        <div className="flex items-start justify-between gap-3">
          <div className="flex flex-1 items-center gap-3">
            <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-primary/10 text-lg font-semibold text-primary">
              {customer.display_name?.charAt(0) ?? '?'}
            </div>
            <div className="space-y-1">
              <div className="flex items-center gap-2">
                <h3 className="text-base font-semibold text-foreground">
                  {customer.display_name || customer.email}
                </h3>
                <StatusBadge status={customer.status} />
              </div>
              <p className="text-sm text-muted-foreground">{customer.email}</p>
              <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                {customer.card_title && (
                  <Badge variant="secondary">کارت: {customer.card_title}</Badge>
                )}
                {customer.assigned_supervisor_name && (
                  <Badge variant="outline">سرپرست: {customer.assigned_supervisor_name}</Badge>
                )}
                {customer.assigned_agent_name && (
                  <Badge variant="outline">کارشناس: {customer.assigned_agent_name}</Badge>
                )}
              </div>
            </div>
          </div>
          <div className="relative">
            <Button
              variant="ghost"
              size="icon"
              className="h-8 w-8"
              onClick={() => setMenuOpen((prev) => !prev)}
              disabled={disabled}
            >
              <MoreHorizontal className="h-4 w-4" />
            </Button>
            {menuOpen && (
              <div className="absolute left-0 top-full z-20 mt-2 w-56 rounded-md border bg-background p-1 shadow-lg">
                <ActionMenuItem
                  icon={TrendingUp}
                  label="تغییر وضعیت"
                  onClick={() => {
                    onOpenStatusDialog(customer)
                    setMenuOpen(false)
                  }}
                />
                <ActionMenuItem
                  icon={Send}
                  label="ارسال پیامک کد عادی"
                  onClick={() => {
                    onSendNormalCode(customer)
                    setMenuOpen(false)
                  }}
                />
                <ActionMenuItem
                  icon={MessageSquare}
                  label="افزودن یادداشت"
                  onClick={() => {
                    onOpenNoteDialog(customer)
                    setMenuOpen(false)
                  }}
                />
                <ActionMenuItem
                  icon={PenTool}
                  label="خرید افزایشی"
                  onClick={() => {
                    onOpenUpsell(customer)
                    setMenuOpen(false)
                  }}
                />
                <ActionMenuItem
                  icon={UserCheck}
                  label="تخصیص سرپرست"
                  onClick={() => {
                    onOpenAssignSupervisor(customer)
                    setMenuOpen(false)
                  }}
                />
                <ActionMenuItem
                  icon={UserCheck}
                  label="تخصیص کارشناس"
                  onClick={() => {
                    onOpenAssignAgent(customer)
                    setMenuOpen(false)
                  }}
                />
              </div>
            )}
          </div>
        </div>

        <div className="grid gap-2 text-xs text-muted-foreground">
          {customer.phone && (
            <div className="flex items-center justify-between">
              <span>تلفن:</span>
              <span className="font-medium text-foreground">{customer.phone}</span>
            </div>
          )}
          {customer.registered_at && (
            <div className="flex items-center justify-between">
              <span>تاریخ ثبت‌نام:</span>
              <span className="font-medium text-foreground">
                {formatDateTime(customer.registered_at)}
              </span>
            </div>
          )}
        </div>
      </CardContent>
    </Card>
  )
}

interface ActionMenuItemProps {
  icon: ComponentType<{ className?: string }>
  label: string
  onClick: () => void
}

function ActionMenuItem({ icon: Icon, label, onClick }: ActionMenuItemProps) {
  return (
    <button
      type="button"
      className={cn(
        'flex w-full items-center justify-between gap-3 rounded-md px-3 py-2 text-right text-sm transition',
        'hover:bg-muted'
      )}
      onClick={onClick}
    >
      <span>{label}</span>
      <Icon className="h-4 w-4 text-muted-foreground" />
    </button>
  )
}
