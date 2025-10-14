import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { zodResolver } from '@hookform/resolvers/zod'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import { Badge } from '@/components/ui/badge'
import { StatusBadge } from '@/components/ui/status-badge'
import type { CustomerStatus } from '@/types'
import { getStatusLabel } from '@/lib/utils'

const statusSchema = z.object({
  status: z.string().min(1, 'یک وضعیت جدید انتخاب کنید'),
  reason: z
    .string()
    .optional()
    .transform((value) => value?.trim() ?? '')
    .refine((value) => !value || value.length >= 3, {
      message: 'توضیح باید حداقل ۳ کاراکتر باشد',
    }),
})

type StatusFormValues = z.infer<typeof statusSchema>

interface StatusDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  customerName: string
  currentStatus: CustomerStatus
  selectableStatuses: CustomerStatus[]
  isSubmitting?: boolean
  onSubmit: (values: StatusFormValues) => Promise<void> | void
}

export function StatusDialog({
  open,
  onOpenChange,
  customerName,
  currentStatus,
  selectableStatuses,
  isSubmitting = false,
  onSubmit,
}: StatusDialogProps) {
  const form = useForm<StatusFormValues>({
    resolver: zodResolver(statusSchema),
    defaultValues: {
      status: selectableStatuses[0] ?? currentStatus,
      reason: '',
    },
  })

  useEffect(() => {
    if (open) {
      form.reset({
        status: selectableStatuses[0] ?? currentStatus,
        reason: '',
      })
    }
  }, [open, currentStatus, selectableStatuses, form])

  const handleSubmit = form.handleSubmit(async (values) => {
    if (!selectableStatuses.includes(values.status as CustomerStatus)) {
      form.setError('status', { message: 'وضعیت انتخاب‌شده معتبر نیست' })
      return
    }
    await onSubmit(values)
    onOpenChange(false)
  })

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>تغییر وضعیت مشتری</DialogTitle>
          <DialogDescription>
            وضعیت جدید برای {customerName} را انتخاب کنید. وضعیت فعلی{' '}
            <Badge variant="outline">{getStatusLabel(currentStatus)}</Badge> است.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="space-y-3">
            <p className="text-sm font-medium text-foreground">انتخاب وضعیت جدید</p>
            <div className="grid gap-2 sm:grid-cols-2">
              {selectableStatuses.map((status) => {
                const isSelected = form.watch('status') === status
                return (
                  <button
                    type="button"
                    key={status}
                    onClick={() => form.setValue('status', status, { shouldValidate: true })}
                    className={`
                      rounded-lg border p-3 text-right transition
                      ${isSelected ? 'border-primary bg-primary/10' : 'border-border hover:border-primary'}
                    `}
                  >
                    <div className="flex items-center justify-between">
                      <span className="text-sm font-semibold text-foreground">
                        {getStatusLabel(status)}
                      </span>
                      <StatusBadge status={status} />
                    </div>
                  </button>
                )
              })}
            </div>
            {form.formState.errors.status && (
              <p className="text-xs text-destructive">
                {form.formState.errors.status.message}
              </p>
            )}
          </div>

          <div className="space-y-2">
            <label htmlFor="reason" className="text-sm font-medium text-foreground">
              توضیح (اختیاری)
            </label>
            <Textarea
              id="reason"
              placeholder="در صورت نیاز توضیح کوتاهی وارد کنید..."
              rows={3}
              {...form.register('reason')}
              disabled={isSubmitting}
            />
            {form.formState.errors.reason && (
              <p className="text-xs text-destructive">
                {form.formState.errors.reason.message}
              </p>
            )}
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={isSubmitting}>
              انصراف
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? 'در حال بروزرسانی…' : 'ذخیره وضعیت'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
