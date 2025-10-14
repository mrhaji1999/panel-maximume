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

const noteSchema = z.object({
  note: z
    .string()
    .trim()
    .min(3, 'حداقل ۳ کاراکتر وارد کنید')
    .max(2000, 'حداکثر ۲۰۰۰ کاراکتر مجاز است'),
})

type NoteFormValues = z.infer<typeof noteSchema>

interface NoteDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSubmit: (values: NoteFormValues) => Promise<void> | void
  isSubmitting?: boolean
  customerName: string
}

export function NoteDialog({
  open,
  onOpenChange,
  onSubmit,
  isSubmitting = false,
  customerName,
}: NoteDialogProps) {
  const form = useForm<NoteFormValues>({
    resolver: zodResolver(noteSchema),
    defaultValues: { note: '' },
  })

  useEffect(() => {
    if (!open) {
      form.reset({ note: '' })
    }
  }, [open, form])

  const handleSubmit = form.handleSubmit(async (values) => {
    await onSubmit(values)
    onOpenChange(false)
    form.reset({ note: '' })
  })

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>ثبت یادداشت برای {customerName}</DialogTitle>
          <DialogDescription>
            توضیحات یا نکته‌ای که باید برای این مشتری ثبت شود را وارد کنید.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="space-y-2 text-sm">
            <label htmlFor="note" className="font-medium text-foreground">
              متن یادداشت
            </label>
            <Textarea
              id="note"
              placeholder="متن یادداشت..."
              {...form.register('note')}
              disabled={isSubmitting}
            />
            {form.formState.errors.note && (
              <p className="text-xs text-destructive">
                {form.formState.errors.note.message}
              </p>
            )}
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={isSubmitting}>
              انصراف
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? 'در حال ثبت...' : 'ثبت یادداشت'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
