import { useState } from 'react'
import { CustomerManagementView } from '@/components/customers/customer-management-view'
import { FormInfoDialog } from '@/components/customers/form-info-dialog'
import type { Customer } from '@/types'

export function CustomersPage() {
  const [formDialogCustomer, setFormDialogCustomer] = useState<{
    id: number
    name: string
    registeredAt?: string
  } | null>(null)

  const handleShowFormInfo = (customer: Customer) => {
    setFormDialogCustomer({
      id: customer.id,
      name: customer.display_name || customer.email || `مشتری #${customer.id}`,
      registeredAt: customer.registered_at,
    })
  }

  return (
    <>
      <CustomerManagementView
        title="مدیریت مشتریان"
        description="مشاهده و مدیریت مشتریان بر اساس نقش و وضعیت"
        perPage={12}
        assignmentTypes={['supervisor', 'agent']}
        syncStatusWithUrl
        showCallButton
        onShowFormInfo={handleShowFormInfo}
      />
      <FormInfoDialog
        open={Boolean(formDialogCustomer)}
        customerId={formDialogCustomer?.id ?? null}
        customerName={formDialogCustomer?.name}
        registeredAt={formDialogCustomer?.registeredAt}
        onOpenChange={(open) => {
          if (!open) {
            setFormDialogCustomer(null)
          }
        }}
      />
    </>
  )
}
