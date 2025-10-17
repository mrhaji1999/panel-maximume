import { CustomerManagementView } from '@/components/customers/customer-management-view'

export function CustomersPage() {
  return (
    <CustomerManagementView
      title="مدیریت مشتریان"
      description="مشاهده و مدیریت مشتریان بر اساس نقش و وضعیت"
      perPage={12}
      assignmentTypes={['supervisor', 'agent']}
      syncStatusWithUrl
    />
  )
}
