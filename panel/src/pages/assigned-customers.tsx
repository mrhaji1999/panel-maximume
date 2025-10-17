import { useMemo } from 'react'
import { CustomerManagementView } from '@/components/customers/customer-management-view'
import { useAuth } from '@/store/authStore'
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'

export function AssignedCustomersPage() {
  const { user } = useAuth()
  const baseFilters = useMemo(() => (user?.id ? { agent_id: user.id } : {}), [user?.id])

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
    <CustomerManagementView
      title="مشتریان تخصیص‌یافته"
      description="مشتریانی که شما مسئول آن‌ها هستید"
      perPage={20}
      baseFilters={baseFilters}
      assignmentTypes={[]}
      allowNotes
      emptyStateMessage="هنوز مشتری تخصیص‌یافته‌ای ندارید."
    />
  )
}
