import { useMemo, useState } from 'react'
import { CustomerManagementView } from '@/components/customers/customer-management-view'
import { CustomerAssignmentTab } from '@/components/customers/customer-assignment-tab'
import { FormInfoDialog } from '@/components/customers/form-info-dialog'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/store/authStore'
import { formatJalaliDate, getCurrentGregorianDate } from '@/lib/utils'
import type { Customer } from '@/types'

export function MyCustomersPage() {
  const { user } = useAuth()
  const [activeTab, setActiveTab] = useState<'customers' | 'daily' | 'assignment'>('customers')
  const [selectedDate, setSelectedDate] = useState<string>(getCurrentGregorianDate())
  const [formDialogCustomer, setFormDialogCustomer] = useState<{ id: number; name: string } | null>(null)

  const supervisorFilters = useMemo(() => {
    const filters: Record<string, string | number | undefined> = {}
    if (user?.id) {
      filters.supervisor_id = user.id
    }
    return filters
  }, [user?.id])

  const dailyFilters = useMemo(() => {
    const filters: Record<string, string | number | undefined> = { ...supervisorFilters }
    filters.registered_date = selectedDate
    return filters
  }, [supervisorFilters, selectedDate])

  const jalaliDateLabel = formatJalaliDate(selectedDate)

  const handleShowFormInfo = (customer: Customer) => {
    setFormDialogCustomer({
      id: customer.id,
      name: customer.display_name || customer.email || `مشتری #${customer.id}`,
    })
  }

  const toolbarExtras = (
    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
      <label className="text-xs font-medium text-muted-foreground">فیلتر تاریخ</label>
      <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-2">
        <input
          type="date"
          value={selectedDate}
          onChange={(event) => setSelectedDate(event.target.value)}
          className="h-10 rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
        />
        <span className="text-xs text-muted-foreground">تاریخ شمسی: {jalaliDateLabel}</span>
        <Button variant="outline" size="sm" onClick={() => setSelectedDate(getCurrentGregorianDate())}>
          امروز
        </Button>
      </div>
    </div>
  )

  const isDailyTab = activeTab === 'daily'
  const appliedFilters = isDailyTab ? dailyFilters : supervisorFilters

  const renderCustomersView = () => (
    <>
      <CustomerManagementView
        title="مشتریان من"
        description="مشتریانی که به شما تخصیص داده شده‌اند"
        perPage={20}
        baseFilters={appliedFilters}
        assignmentTypes={['agent']}
        supervisorFilterForAgents={user?.id}
        emptyStateMessage="هیچ مشتری برای شما ثبت نشده است."
        defaultStatus="all"
        toolbarExtras={isDailyTab ? toolbarExtras : undefined}
        showCallButton
        onShowFormInfo={handleShowFormInfo}
      />
      <FormInfoDialog
        open={Boolean(formDialogCustomer)}
        customerId={formDialogCustomer?.id ?? null}
        customerName={formDialogCustomer?.name}
        onOpenChange={(open) => {
          if (!open) {
            setFormDialogCustomer(null)
          }
        }}
      />
    </>
  )

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-2">
        <Button variant={activeTab === 'customers' ? 'default' : 'outline'} onClick={() => setActiveTab('customers')}>
          لیست مشتریان
        </Button>
        <Button variant={activeTab === 'daily' ? 'default' : 'outline'} onClick={() => setActiveTab('daily')}>
          مشتریان بر اساس تاریخ
        </Button>
        <Button variant={activeTab === 'assignment' ? 'default' : 'outline'} onClick={() => setActiveTab('assignment')}>
          تخصیص مشتری
        </Button>
      </div>

      {activeTab === 'assignment'
        ? user?.id && <CustomerAssignmentTab supervisorId={user.id} />
        : renderCustomersView()}
    </div>
  )
}
