import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { customersApi, usersApi } from '@/lib/api'
import type { AgentListResponse, CustomerListResponse } from '@/types'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { formatJalaliDate, formatNumber, getErrorMessage } from '@/lib/utils'
import { useNotification } from '@/store/uiStore'
import { Loader2, Users } from 'lucide-react'

const PAGE_SIZE_OPTIONS = [10, 20, 50]

interface CustomerAssignmentTabProps {
  supervisorId: number
}

export function CustomerAssignmentTab({ supervisorId }: CustomerAssignmentTabProps) {
  const queryClient = useQueryClient()
  const { success: notifySuccess, error: notifyError } = useNotification()

  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(10)
  const [selectedCustomers, setSelectedCustomers] = useState<number[]>([])
  const [selectedAgentId, setSelectedAgentId] = useState<number | ''>('')

  useEffect(() => {
    setPage(1)
    setSelectedCustomers([])
  }, [perPage, supervisorId])

  const customersQuery = useQuery<CustomerListResponse>({
    queryKey: ['assignment-customers', supervisorId, page, perPage],
    enabled: supervisorId > 0,
    queryFn: async () => {
      const response = await customersApi.getCustomers({
        supervisor_id: supervisorId,
        status: 'unassigned',
        page,
        per_page: perPage,
      })
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در دریافت مشتریان تعیین‌نشده')
      }
      return response.data as CustomerListResponse
    },
    placeholderData: (previousData) => previousData,
  })

  const agentsQuery = useQuery<AgentListResponse>({
    queryKey: ['assignment-agents', supervisorId],
    enabled: supervisorId > 0,
    queryFn: async () => {
      const response = await usersApi.getAgents({ supervisor_id: supervisorId, per_page: 200 })
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در دریافت کارشناسان')
      }
      return response.data as AgentListResponse
    },
  })

  const assignMutation = useMutation({
    mutationFn: async () => {
      if (!selectedAgentId) {
        throw new Error('لطفاً یک کارشناس را انتخاب کنید')
      }
      if (selectedCustomers.length === 0) {
        throw new Error('هیچ مشتری‌ای انتخاب نشده است')
      }

      for (const customerId of selectedCustomers) {
        const response = await customersApi.assignAgent(customerId, Number(selectedAgentId))
        if (!response.success) {
          throw new Error(response.error?.message || 'تخصیص کارشناس ناموفق بود')
        }
      }
    },
    onSuccess: () => {
      notifySuccess('تخصیص انجام شد', 'مشتریان انتخاب‌شده به کارشناس انتخاب‌شده تخصیص یافتند')
      setSelectedCustomers([])
      queryClient.invalidateQueries({ queryKey: ['assignment-customers', supervisorId] })
      queryClient.invalidateQueries({ queryKey: ['customers'] })
    },
    onError: (error) => {
      notifyError('خطا در تخصیص مشتری', getErrorMessage(error))
    },
  })

  const customers = customersQuery.data?.items ?? []
  const pagination = customersQuery.data?.pagination
  const totalPages = pagination?.total_pages ?? 1
  const totalCustomers = pagination?.total ?? 0

  const allSelected = useMemo(() => {
    if (customers.length === 0) return false
    return customers.every((customer) => selectedCustomers.includes(customer.id))
  }, [customers, selectedCustomers])

  const toggleCustomer = (customerId: number, checked: boolean) => {
    setSelectedCustomers((previous) => {
      if (checked) {
        if (previous.includes(customerId)) return previous
        return [...previous, customerId]
      }
      return previous.filter((id) => id !== customerId)
    })
  }

  const handleToggleAll = (checked: boolean) => {
    if (checked) {
      setSelectedCustomers((previous) => {
        const newSelection = new Set(previous)
        for (const customer of customers) {
          newSelection.add(customer.id)
        }
        return Array.from(newSelection)
      })
    } else {
      setSelectedCustomers((previous) => previous.filter((id) => !customers.some((customer) => customer.id === id)))
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center justify-between">
          <span>تخصیص گروهی مشتریان تعیین‌نشده</span>
          <Badge variant="outline">{formatNumber(totalCustomers)} مشتری تعیین‌نشده</Badge>
        </CardTitle>
        <CardDescription>
          مشتریان با وضعیت تعیین‌نشده را انتخاب کنید و آن‌ها را به یکی از کارشناسان خود تخصیص دهید.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
          <div className="flex items-center gap-2">
            <label htmlFor="per-page" className="text-sm text-muted-foreground">
              تعداد در هر صفحه
            </label>
            <select
              id="per-page"
              value={perPage}
              onChange={(event) => setPerPage(Number(event.target.value))}
              className="h-10 rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
            >
              {PAGE_SIZE_OPTIONS.map((option) => (
                <option key={option} value={option}>
                  {option}
                </option>
              ))}
            </select>
          </div>

          <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3">
            <div className="flex items-center gap-2">
              <Users className="h-4 w-4 text-muted-foreground" />
              <select
                value={selectedAgentId}
                onChange={(event) => setSelectedAgentId(event.target.value ? Number(event.target.value) : '')}
                className="h-10 rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
              >
                <option value="">انتخاب کارشناس</option>
                {agentsQuery.data?.items.map((agent) => (
                  <option key={agent.id} value={agent.id}>
                    {agent.display_name}
                  </option>
                ))}
              </select>
            </div>
            <Button
              onClick={() => assignMutation.mutate()}
              disabled={assignMutation.isPending || !selectedAgentId || selectedCustomers.length === 0}
            >
              {assignMutation.isPending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
              تخصیص مشتریان انتخاب‌شده
            </Button>
          </div>
        </div>

        {customersQuery.isLoading ? (
          <div className="flex items-center justify-center py-8 text-sm text-muted-foreground">
            <Loader2 className="mr-2 h-4 w-4 animate-spin" /> در حال دریافت مشتریان...
          </div>
        ) : customersQuery.isError ? (
          <div className="rounded-md border border-destructive/30 bg-destructive/10 px-3 py-4 text-sm text-destructive">
            {getErrorMessage(customersQuery.error)}
          </div>
        ) : customers.length === 0 ? (
          <div className="rounded-md border border-dashed px-4 py-12 text-center text-sm text-muted-foreground">
            مشتری تعیین‌نشده‌ای برای تخصیص وجود ندارد.
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-border text-right">
              <thead className="bg-muted/40 text-xs text-muted-foreground">
                <tr>
                  <th className="px-3 py-2">
                    <input
                      type="checkbox"
                      checked={allSelected}
                      onChange={(event) => handleToggleAll(event.target.checked)}
                      className="h-4 w-4 rounded border-input text-primary focus:ring-primary"
                    />
                  </th>
                  <th className="px-3 py-2 font-medium">مشتری</th>
                  <th className="px-3 py-2 font-medium">شماره تماس</th>
                  <th className="px-3 py-2 font-medium">تاریخ ثبت‌نام (شمسی)</th>
                  <th className="px-3 py-2 font-medium">کارت</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border text-sm">
                {customers.map((customer) => {
                  const isSelected = selectedCustomers.includes(customer.id)
                  return (
                    <tr key={customer.id} className={isSelected ? 'bg-primary/5' : undefined}>
                      <td className="px-3 py-2">
                        <input
                          type="checkbox"
                          checked={isSelected}
                          onChange={(event) => toggleCustomer(customer.id, event.target.checked)}
                          className="h-4 w-4 rounded border-input text-primary focus:ring-primary"
                        />
                      </td>
                      <td className="px-3 py-2 font-medium text-foreground">
                        {customer.display_name || customer.email || `مشتری #${customer.id}`}
                      </td>
                      <td className="px-3 py-2">{customer.phone || '—'}</td>
                      <td className="px-3 py-2">{customer.registered_at ? formatJalaliDate(customer.registered_at) : '—'}</td>
                      <td className="px-3 py-2">{customer.card_title || '—'}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}

        <div className="flex flex-col gap-3 border-t pt-4 text-sm sm:flex-row sm:items-center sm:justify-between">
          <span className="text-muted-foreground">
            {selectedCustomers.length > 0
              ? `${formatNumber(selectedCustomers.length)} مشتری انتخاب شده`
              : 'هیچ مشتری‌ای انتخاب نشده است'}
          </span>
          {pagination && totalPages > 1 && (
            <div className="flex items-center gap-2">
              <Button
                variant="outline"
                size="sm"
                onClick={() => setPage((previous) => Math.max(previous - 1, 1))}
                disabled={page === 1 || customersQuery.isFetching}
              >
                قبلی
              </Button>
              <span className="text-xs text-muted-foreground">
                صفحه {formatNumber(page)} از {formatNumber(totalPages)}
              </span>
              <Button
                variant="outline"
                size="sm"
                onClick={() => setPage((previous) => Math.min(previous + 1, totalPages))}
                disabled={page === totalPages || customersQuery.isFetching}
              >
                بعدی
              </Button>
            </div>
          )}
        </div>
      </CardContent>
    </Card>
  )
}
