import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
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
import { Filter, Search } from 'lucide-react'
import { useAuth } from '@/store/authStore'
import { useNotification } from '@/store/uiStore'
import { useDebounce } from '@/hooks/useDebounce'
import { customersApi } from '@/lib/api'
import {
  Customer,
  CustomerListResponse,
  CustomerStatus,
  CustomerTabsResponse,
} from '@/types'
import { formatNumber, getErrorMessage } from '@/lib/utils'
import { NoteDialog } from '@/components/customers/note-dialog'
import { AssignmentDialog } from '@/components/customers/assignment-dialog'
import { CustomerCard } from './customers'
import { HIGHLIGHT_STATUSES, SECONDARY_STATUSES, STATUS_LABELS } from '@/constants/customer-status'

const PER_PAGE = 12

type StatusFilter = 'all' | CustomerStatus

interface StatusTab {
  key: StatusFilter
  label: string
  count?: number
}

export function MyCustomersPage() {
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const { success: notifySuccess, error: notifyError, info: notifyInfo } = useNotification()

  const [searchTerm, setSearchTerm] = useState('')
  const [selectedStatus, setSelectedStatus] = useState<StatusFilter>('all')
  const [page, setPage] = useState(1)
  const [noteDialogCustomer, setNoteDialogCustomer] = useState<Customer | null>(null)
  const [assignmentCustomer, setAssignmentCustomer] = useState<Customer | null>(null)

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

    if (user?.role === 'supervisor') {
      params.supervisor_id = user.id
    }

    return params
  }, [debouncedSearch, page, selectedStatus, user?.id, user?.role])

  const customersQuery = useQuery({
    queryKey: ['my-customers', apiFilters],
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
    queryKey: ['my-customers', 'tabs'],
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
    const highlightTabs = HIGHLIGHT_STATUSES.map((status) => ({
      key: status,
      label: STATUS_LABELS[status],
      count: tabData[status]?.total,
    }))
    const remainingTabs = SECONDARY_STATUSES.map((status) => ({
      key: status,
      label: STATUS_LABELS[status],
    }))

    return [
      { key: 'all', label: 'همه', count: totalCustomers },
      ...highlightTabs,
      ...remainingTabs,
    ]
  }, [tabsQuery.data, totalCustomers])

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
    }) => {
      const response = await customersApi.updateCustomerStatus(customerId, status, { reason, meta })
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در تغییر وضعیت')
      }
      return response.data
    },
    onSuccess: () => {
      notifySuccess('موفق', 'وضعیت مشتری بروزرسانی شد')
      queryClient.invalidateQueries({ queryKey: ['my-customers'] })
      queryClient.invalidateQueries({ queryKey: ['assigned-customers'] })
      queryClient.invalidateQueries({ queryKey: ['my-customers', 'tabs'] })
      queryClient.invalidateQueries({ queryKey: ['customers', 'tabs'] })
    },
    onError: (error) => {
      notifyError('خطا در تغییر وضعیت', getErrorMessage(error))
    },
  })

  const addNoteMutation = useMutation({
    mutationFn: async ({ customerId, note }: { customerId: number; note: string }) => {
      const response = await customersApi.addCustomerNote(customerId, note)
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در ثبت یادداشت')
      }
      return response.data
    },
    onSuccess: () => {
      notifySuccess('یادداشت ثبت شد', 'یادداشت با موفقیت ثبت شد')
    },
    onError: (error) => {
      notifyError('خطا در ثبت یادداشت', getErrorMessage(error))
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
      notifySuccess('کارشناس ثبت شد', 'کارشناس مشتری با موفقیت بروزرسانی شد')
      queryClient.invalidateQueries({ queryKey: ['my-customers'] })
      queryClient.invalidateQueries({ queryKey: ['assigned-customers'] })
    },
    onError: (error) => {
      notifyError('خطا در تخصیص کارشناس', getErrorMessage(error))
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
      notifySuccess('پیامک ارسال شد', 'پیامک با موفقیت ارسال شد')
    },
    onError: (error) => {
      notifyError('خطا در ارسال پیامک', getErrorMessage(error))
    },
  })

  const initUpsellMutation = useMutation({
    mutationFn: async ({ customerId, cardId, fieldKey }: { customerId: number; cardId: number; fieldKey: string }) => {
      const response = await customersApi.initUpsell(customerId, cardId, fieldKey)
      if (!response.success) {
        throw new Error(response.error?.message || 'ارسال لینک پرداخت ناموفق بود')
      }
      return response.data
    },
    onSuccess: () => {
      notifyInfo('پیامک ارسال شد', 'لینک پرداخت برای مشتری ارسال شد')
      queryClient.invalidateQueries({ queryKey: ['my-customers'] })
      queryClient.invalidateQueries({ queryKey: ['my-customers', 'tabs'] })
      queryClient.invalidateQueries({ queryKey: ['customers', 'tabs'] })
    },
    onError: (error) => {
      notifyError('خطا در فروش افزایشی', getErrorMessage(error))
    },
  })

  const handleChangeStatusFilter = (status: StatusFilter) => {
    setSelectedStatus(status)
  }

  const handleStatusChange = async (customer: Customer, status: CustomerStatus) => {
    await updateStatusMutation.mutateAsync({ customerId: customer.id, status })
  }

  const handleSendNormalCode = async (customer: Customer) => {
    await sendNormalCodeMutation.mutateAsync(customer.id)
  }

  const handleStartUpsell = async (customer: Customer, fieldKey: string) => {
    if (!customer.card_id) {
      notifyError('کارت نامشخص', 'برای این مشتری کارت تعریف نشده است')
      throw new Error('missing_card')
    }

    await initUpsellMutation.mutateAsync({
      customerId: customer.id,
      cardId: customer.card_id,
      fieldKey,
    })
  }

  const handleOpenNoteDialog = (customer: Customer) => {
    setNoteDialogCustomer(customer)
  }

  const handleNoteSubmit = async ({ note }: { note: string }) => {
    if (!noteDialogCustomer) return
    await addNoteMutation.mutateAsync({ customerId: noteDialogCustomer.id, note })
    setNoteDialogCustomer(null)
  }

  const canAssignAgents = user?.role === 'supervisor'

  const handleOpenAssignment = (customer: Customer) => {
    if (!canAssignAgents) return
    setAssignmentCustomer(customer)
  }

  const handleAssignmentSubmit = async (agentId: number) => {
    if (!assignmentCustomer) return
    await assignAgentMutation.mutateAsync({ customerId: assignmentCustomer.id, agentId })
    setAssignmentCustomer(null)
  }

  const isMutating =
    updateStatusMutation.isPending ||
    sendNormalCodeMutation.isPending ||
    initUpsellMutation.isPending ||
    assignAgentMutation.isPending

  const statusUpdatingId = updateStatusMutation.isPending ? updateStatusMutation.variables?.customerId ?? null : null
  const normalSmsCustomerId = sendNormalCodeMutation.isPending ? sendNormalCodeMutation.variables ?? null : null
  const upsellCustomerId = initUpsellMutation.isPending ? initUpsellMutation.variables?.customerId ?? null : null

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">مشتریان من</h1>
          <p className="text-muted-foreground">مشتریانی که به شما تخصیص داده شده‌اند</p>
        </div>
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
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
          <CardDescription>مجموع {formatNumber(totalCustomers)} مشتری</CardDescription>
        </CardHeader>
        <CardContent>
          {customersQuery.isLoading ? (
            <LoadingSkeleton />
          ) : customersQuery.isError ? (
            <div className="rounded-lg border border-dashed p-8 text-center text-sm text-destructive">
              {getErrorMessage(customersQuery.error)}
            </div>
          ) : customers.length === 0 ? (
            <div className="rounded-lg border border-dashed p-12 text-center text-muted-foreground">
              مشتری مطابق با فیلترهای فعلی یافت نشد.
            </div>
          ) : (
            <div className="space-y-4">
              {customers.map((customer) => (
                <CustomerCard
                  key={customer.id}
                  customer={customer}
                  disabled={isMutating}
                  onStatusChange={handleStatusChange}
                  onSendNormalCode={handleSendNormalCode}
                  onStartUpsell={handleStartUpsell}
                  onOpenNoteDialog={handleOpenNoteDialog}
                  onOpenAssignAgent={canAssignAgents ? handleOpenAssignment : undefined}
                  statusUpdatingId={statusUpdatingId}
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

      <NoteDialog
        open={Boolean(noteDialogCustomer)}
        onOpenChange={(open) => {
          if (!open) setNoteDialogCustomer(null)
        }}
        customerName={noteDialogCustomer ? noteDialogCustomer.display_name || noteDialogCustomer.email || '' : ''}
        onSubmit={handleNoteSubmit}
        isSubmitting={addNoteMutation.isPending}
      />

      {canAssignAgents && (
        <AssignmentDialog
          open={Boolean(assignmentCustomer)}
          onOpenChange={(open) => {
            if (!open) setAssignmentCustomer(null)
          }}
          type="agent"
          customerName={assignmentCustomer ? assignmentCustomer.display_name || assignmentCustomer.email || '' : ''}
          isSubmitting={assignAgentMutation.isPending}
          onSubmit={handleAssignmentSubmit}
          supervisorFilter={user?.role === 'supervisor' ? user.id : undefined}
        />
      )}
    </div>
  )
}

function LoadingSkeleton() {
  return (
    <div className="space-y-3">
      {Array.from({ length: 4 }).map((_, index) => (
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
  )
}
