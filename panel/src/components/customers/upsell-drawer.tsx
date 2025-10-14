import { useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
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
import { Loader2, ExternalLink } from 'lucide-react'
import { cardsApi } from '@/lib/api'
import type { CardField } from '@/types'
import { formatNumber, getErrorMessage } from '@/lib/utils'

const upsellSchema = z.object({
  field_key: z.string().min(1, 'یکی از گزینه‌ها را انتخاب کنید'),
})

type UpsellFormValues = z.infer<typeof upsellSchema>

interface UpsellDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  customerName: string
  cardId: number
  isSubmitting?: boolean
  onSubmit: (values: UpsellFormValues) => Promise<void> | void
}

export function UpsellDrawer({
  open,
  onOpenChange,
  customerName,
  cardId,
  isSubmitting = false,
  onSubmit,
}: UpsellDrawerProps) {
  const form = useForm<UpsellFormValues>({
    resolver: zodResolver(upsellSchema),
    defaultValues: {
      field_key: '',
    },
  })

  useEffect(() => {
    if (!open) {
      form.reset({ field_key: '' })
    }
  }, [open, form])

  const fieldsQuery = useQuery({
    queryKey: ['card-fields', cardId],
    enabled: open && !!cardId,
    queryFn: async () => {
      const response = await cardsApi.getCardFields(cardId)
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در دریافت فیلدها')
      }
      return response.data?.fields ?? []
    },
  })

  const handleSubmit = form.handleSubmit(async (values) => {
    await onSubmit(values)
    onOpenChange(false)
    form.reset({ field_key: '' })
  })

  const fields = fieldsQuery.data ?? []
  const selectedField = fields.find((item: CardField) => item.key === form.watch('field_key'))

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="right-0 top-0 h-full max-w-md translate-x-0 translate-y-0 rounded-none border-0 bg-card p-0 sm:rounded-l-xl">
        <DialogHeader className="border-b p-6 text-right">
          <DialogTitle>شروع فروش افزایشی</DialogTitle>
          <DialogDescription>
            یکی از گزینه‌های افزایشی کارت را برای {customerName} انتخاب کنید.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="flex h-full flex-col">
          <div className="flex-1 space-y-4 overflow-y-auto p-6">
            {fieldsQuery.isLoading ? (
              <div className="flex items-center justify-center py-12">
                <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
              </div>
            ) : fieldsQuery.isError ? (
              <p className="text-sm text-destructive">
                {getErrorMessage(fieldsQuery.error)}
              </p>
            ) : fields.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                فیلدی برای این کارت ثبت نشده است. ابتدا فیلدهای افزایشی کارت را تعریف کنید.
              </p>
            ) : (
              <div className="space-y-3">
                {fields.map((field) => {
                  const isSelected = form.watch('field_key') === field.key
                  return (
                    <button
                      type="button"
                      key={field.key}
                      onClick={() => form.setValue('field_key', field.key, { shouldValidate: true })}
                      className={`
                        w-full rounded-lg border p-4 text-right transition
                        ${isSelected ? 'border-primary bg-primary/10' : 'border-border hover:border-primary'}
                      `}
                    >
                      <div className="flex items-center justify-between gap-2">
                        <div>
                          <p className="text-sm font-semibold text-foreground">{field.label}</p>
                          <p className="text-xs text-muted-foreground">کلید: {field.key}</p>
                        </div>
                        <span className="text-base font-bold text-primary">
                          {formatNumber(field.amount)} تومان
                        </span>
                      </div>
                    </button>
                  )
                })}
              </div>
            )}

            {form.formState.errors.field_key && (
              <p className="text-xs text-destructive">
                {form.formState.errors.field_key.message}
              </p>
            )}

            {selectedField && (
              <div className="rounded-lg border border-dashed p-4 text-sm leading-6 text-muted-foreground">
                <p>
                  پس از ثبت سفارش، لینک پرداخت مربوط به گزینه{' '}
                  <strong className="text-foreground">{selectedField.label}</strong> برای مشتری
                  ایجاد و پیامک مرتبط ارسال خواهد شد.
                </p>
              </div>
            )}
          </div>

          <DialogFooter className="border-t p-6">
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={isSubmitting}>
              انصراف
            </Button>
            <Button
              type="submit"
              disabled={
                isSubmitting || fieldsQuery.isLoading || !form.watch('field_key') || fields.length === 0
              }
            >
              {isSubmitting ? (
                <span className="flex items-center gap-2">
                  <Loader2 className="h-4 w-4 animate-spin" />
                  در حال ایجاد سفارش...
                </span>
              ) : (
                <span className="flex items-center gap-2">
                  <ExternalLink className="h-4 w-4" />
                  ایجاد لینک پرداخت
                </span>
              )}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
