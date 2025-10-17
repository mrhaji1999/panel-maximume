import { useMemo } from 'react'
import { CustomerManagementView } from '@/components/customers/customer-management-view'
import { useAuth } from '@/store/authStore'

export function MyCustomersPage() {
  const { user } = useAuth()
  const baseFilters = useMemo(() => (user?.id ? { supervisor_id: user.id } : {}), [user?.id])

  return (
    <CustomerManagementView
      title="مشتریان من"
      description="مشتریانی که به شما تخصیص داده شده‌اند"
      perPage={20}
      baseFilters={baseFilters}
      assignmentTypes={['agent']}
      supervisorFilterForAgents={user?.id}
      emptyStateMessage="هیچ مشتری برای شما ثبت نشده است."
    />
  )
}
