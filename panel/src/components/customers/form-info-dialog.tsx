import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Badge } from '@/components/ui/badge'
import { formsApi } from '@/lib/api'
import type { CustomerFormField, FormSubmission } from '@/types'
import { formatDateTime, getErrorMessage } from '@/lib/utils'
import { Loader2 } from 'lucide-react'
import { useAuth } from '@/store/authStore'

interface FormInfoDialogProps {
  customerId: number | null
  customerName?: string
  open: boolean
  onOpenChange: (open: boolean) => void
  registeredAt?: string
}

export function FormInfoDialog({
  customerId,
  customerName,
  open,
  onOpenChange,
  registeredAt,
}: FormInfoDialogProps) {
  const { user } = useAuth()
  const supervisorId = user?.role === 'supervisor' ? user.id : undefined
  const agentId = user?.role === 'agent' ? user.id : undefined
  const assignedCardIds = useMemo(
    () => (user?.assigned_cards ? [...user.assigned_cards] : []),
    [user?.assigned_cards]
  )

  const query = useQuery<FormSubmission[]>({
    queryKey: ['customer-form-info', customerId, supervisorId ?? 'all', agentId ?? 'all'],
    enabled: open && Boolean(customerId),
    queryFn: async () => {
      if (!customerId) throw new Error('شناسه مشتری نامعتبر است')
      const response = await formsApi.getForms({
        customer_id: customerId,
        per_page: 100,
        supervisor_id: supervisorId,
        agent_id: agentId,
      })
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در دریافت اطلاعات فرم')
      }
      return response.data?.items ?? []
    },
    staleTime: 1000 * 60,
  })

  const forms = useMemo(() => {
    const items = query.data ?? []
    if (!user) {
      return items
    }

    return items.filter((form) => {
      if (supervisorId) {
        if (form.meta.supervisor_id && form.meta.supervisor_id !== supervisorId) {
          return false
        }
        if (assignedCardIds.length && !assignedCardIds.includes(form.meta.card_id)) {
          return false
        }
      }
      if (agentId) {
        return form.meta.agent_id === agentId
      }
      return true
    })
  }, [agentId, assignedCardIds, query.data, supervisorId, user])

  const preparedForms = useMemo(
    () =>
      forms.map((form) => {
        const fields: CustomerFormField[] = []
        const { meta } = form

        const pushField = (label: string, value: unknown) => {
          const normalizedLabel = (label || '').trim()
          const normalizedValue = normalizeFormValue(value)
          if (!normalizedLabel || !normalizedValue) {
            return
          }
          fields.push({ label: normalizedLabel, value: normalizedValue })
        }

        pushField('کد رزرو', meta.code)
        pushField('تاریخ رزرو', meta.date)
        pushField('ساعت رزرو', meta.time)
        pushField('کد شگفتانه', meta.surprise)

        if (meta.meta && typeof meta.meta === 'object') {
          Object.entries(meta.meta).forEach(([key, rawValue]) => {
            const label = extractFieldLabel(key, rawValue)
            const value = normalizeFormValue(rawValue)
            if (value) {
              fields.push({ label, value })
            }
          })
        }

        const seen = new Set<string>()
        const uniqueFields = fields.filter((field) => {
          const key = field.label.trim()
          if (!key || seen.has(key)) {
            return false
          }
          seen.add(key)
          return true
        })

        return {
          id: form.id,
          title: form.title,
          createdAt: form.created_at,
          cardId: meta.card_id,
          fields: uniqueFields,
        }
      }),
    [forms]
  )

  const registeredAtLabel = preparedForms[0]?.createdAt ?? registeredAt ?? null

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>اطلاعات فرم رزرو</DialogTitle>
          <DialogDescription>
            اطلاعات ثبت‌شده برای {customerName || 'مشتری'}
            {registeredAtLabel && (
              <span className="block text-xs text-muted-foreground">
                تاریخ ثبت‌نام: {formatDateTime(registeredAtLabel)}
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
        ) : preparedForms.length === 0 ? (
          <div className="rounded-md border border-dashed px-3 py-6 text-center text-sm text-muted-foreground">
            اطلاعاتی برای این فرم ثبت نشده است.
          </div>
        ) : (
          <div className="space-y-4">
            {preparedForms.map((form) => (
              <div key={form.id} className="space-y-3 rounded-md border border-border bg-muted/30 p-4">
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                  <div>
                    <p className="text-sm font-semibold text-foreground">{form.title || `فرم #${form.id}`}</p>
                    <p className="text-xs text-muted-foreground">
                      ارسال شده در: {formatDateTime(form.createdAt)}
                    </p>
                  </div>
                  <Badge variant="outline">کارت #{form.cardId}</Badge>
                </div>
                {form.fields.length > 0 ? (
                  <div className="grid gap-2 md:grid-cols-2">
                    {form.fields.map((field, index) => (
                      <div
                        key={`${form.id}-${field.label}-${index}`}
                        className="rounded-md border border-border bg-background/60 px-3 py-2"
                      >
                        <p className="text-xs font-medium text-muted-foreground">{field.label}</p>
                        <p className="text-sm font-medium text-foreground">{field.value || '-'}</p>
                      </div>
                    ))}
                  </div>
                ) : (
                  <p className="text-xs text-muted-foreground">فیلدی برای این فرم ثبت نشده است.</p>
                )}
              </div>
            ))}
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}

function normalizeFormValue(value: unknown): string {
  if (value === null || value === undefined) {
    return ''
  }

  if (typeof value === 'string') {
    return value.trim()
  }

  if (typeof value === 'number' || typeof value === 'boolean') {
    return String(value)
  }

  if (Array.isArray(value)) {
    return value
      .map((item) => normalizeFormValue(item))
      .filter((item) => item !== '')
      .join('، ')
  }

  if (typeof value === 'object') {
    const record = value as Record<string, unknown>
    if ('value' in record) {
      return normalizeFormValue(record.value)
    }

    if ('label' in record && Object.keys(record).length === 1) {
      return normalizeFormValue(record.label)
    }

    const nested = Object.values(record)
      .map((item) => normalizeFormValue(item))
      .filter((item) => item !== '')

    return nested.join('، ')
  }

  return ''
}

function extractFieldLabel(key: string, value: unknown): string {
  if (value && typeof value === 'object' && !Array.isArray(value)) {
    const record = value as Record<string, unknown>
    if (typeof record.label === 'string' && record.label.trim() !== '') {
      return record.label.trim()
    }
  }

  return key.replace(/_/g, ' ').trim() || key
}
