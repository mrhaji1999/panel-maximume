import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { customersApi } from '@/lib/api'
import type { CustomerDetail } from '@/types'
import { formatDateTime, getErrorMessage } from '@/lib/utils'
import { Loader2 } from 'lucide-react'

interface FormInfoDialogProps {
  customerId: number | null
  customerName?: string
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function FormInfoDialog({ customerId, customerName, open, onOpenChange }: FormInfoDialogProps) {
  const query = useQuery({
    queryKey: ['customer-detail', customerId],
    enabled: open && Boolean(customerId),
    queryFn: async () => {
      if (!customerId) throw new Error('شناسه مشتری نامعتبر است')
      const response = await customersApi.getCustomer(customerId)
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در دریافت اطلاعات فرم')
      }
      return response.data as CustomerDetail
    },
    staleTime: 1000 * 60,
  })

  const formFields = useMemo(() => query.data?.form_data ?? [], [query.data?.form_data])

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>اطلاعات فرم رزرو</DialogTitle>
          <DialogDescription>
            اطلاعات ثبت‌شده برای {customerName || 'مشتری'}
            {query.data?.registered_at && (
              <span className="block text-xs text-muted-foreground">
                تاریخ ثبت‌نام: {formatDateTime(query.data.registered_at)}
              </span>
            )}
          </DialogDescription>
        </DialogHeader>

        {query.isLoading ? (
          <div className="flex items-center justify-center py-6 text-sm text-muted-foreground">
            <Loader2 className="mr-2 h-4 w-4 animate-spin" /> در حال دریافت اطلاعات فرم...
          </div>
        ) : query.isError ? (
          <div className="rounded-md border border-destructive/30 bg-destructive/10 px-3 py-4 text-sm text-destructive">
            {getErrorMessage(query.error)}
          </div>
        ) : formFields.length === 0 ? (
          <div className="rounded-md border border-dashed px-3 py-6 text-center text-sm text-muted-foreground">
            اطلاعاتی برای این فرم ثبت نشده است.
          </div>
        ) : (
          <div className="space-y-3">
            {formFields.map((field, index) => (
              <div
                key={`${field.label}-${index}`}
                className="rounded-md border border-border bg-muted/30 px-3 py-2"
              >
                <p className="text-xs font-medium text-muted-foreground">{field.label}</p>
                <p className="text-sm font-medium text-foreground">{field.value || '-'}</p>
              </div>
            ))}
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}
