import { useMemo, useState } from 'react'
import { CustomerManagementView } from '@/components/customers/customer-management-view'
import { useAuth } from '@/store/authStore'
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { FormInfoDialog } from '@/components/customers/form-info-dialog'
import { formatJalaliDate, getCurrentGregorianDate } from '@/lib/utils'
import type { Customer } from '@/types'

export function AssignedCustomersPage() {
  const { user } = useAuth()
  const [selectedDate, setSelectedDate] = useState<string>(getCurrentGregorianDate())
  const [formDialogCustomer, setFormDialogCustomer] = useState<{ id: number; name: string } | null>(null)

  const baseFilters = useMemo(() => {
    const filters: Record<string, string | number | undefined> = {}
    if (user?.id) {
      filters.agent_id = user.id
    }
    if (selectedDate) {
      filters.registered_date = selectedDate
    }
    return filters
  }, [user?.id, selectedDate])

  const jalaliDateLabel = formatJalaliDate(selectedDate)

  const handleShowFormInfo = (customer: Customer) => {
    setFormDialogCustomer({
      id: customer.id,
      name: customer.display_name || customer.email || `مشتری #${customer.id}`,
    })
  }

  const toolbarExtras = (
    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
      <label className="text-xs font-medium text-muted-foreground">تاریخ نمایش</label>
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

  if (user?.role !== 'agent') {
    return (
      <Card>
        <CardHeader>
          <CardTitle>دسترسی محدود</CardTitle>
          <CardDescription>این بخش فقط برای کارشناسان در دسترس است.</CardDescription>
        </CardHeader>
      </Card>
    )
  }

  return (
    <>
      <CustomerManagementView
        title="مشتریان تخصیص‌یافته"
        description="مشتریانی که شما مسئول آن‌ها هستید"
        perPage={20}
        baseFilters={baseFilters}
        assignmentTypes={[]}
        allowNotes
        emptyStateMessage="هنوز مشتری تخصیص‌یافته‌ای ندارید."
        defaultStatus="unassigned"
        toolbarExtras={toolbarExtras}
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
}
