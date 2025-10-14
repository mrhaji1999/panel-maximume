import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Loader2, UserCheck } from 'lucide-react'
import { usersApi } from '@/lib/api'
import type { Agent, Supervisor } from '@/types'
import { useDebounce } from '@/hooks/useDebounce'
import { getErrorMessage } from '@/lib/utils'

type AssignmentKind = 'supervisor' | 'agent'

interface AssignmentDialogProps {
  type: AssignmentKind
  open: boolean
  onOpenChange: (open: boolean) => void
  customerName: string
  isSubmitting?: boolean
  onSubmit: (id: number) => Promise<void> | void
  supervisorFilter?: number
  allowUnassign?: boolean
}

export function AssignmentDialog({
  type,
  open,
  onOpenChange,
  customerName,
  isSubmitting = false,
  onSubmit,
  supervisorFilter,
  allowUnassign = false,
}: AssignmentDialogProps) {
  const [search, setSearch] = useState('')
  const debouncedSearch = useDebounce(search, 400)
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!open) {
      setSearch('')
      setSelectedId(null)
      setError(null)
    }
  }, [open])

  const queryKey = useMemo(() => {
    if (type === 'supervisor') {
      return ['assignment', 'supervisors', debouncedSearch]
    }
    return ['assignment', 'agents', debouncedSearch, supervisorFilter]
  }, [type, debouncedSearch, supervisorFilter])

  const optionsQuery = useQuery({
    queryKey: queryKey,
    enabled: open,
    queryFn: async () => {
      if (type === 'supervisor') {
        const response = await usersApi.getSupervisors({
          per_page: 100,
          search: debouncedSearch || undefined,
        })
        if (!response.success) {
          throw new Error(response.error?.message || 'خطا در دریافت سرپرست‌ها')
        }
        return response.data?.items ?? []
      }
      const response = await usersApi.getAgents({
        per_page: 100,
        search: debouncedSearch || undefined,
        supervisor_id: supervisorFilter,
      })
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در دریافت کارشناسان')
      }
      return response.data?.items ?? []
    },
  })

  const entries = optionsQuery.data ?? []

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setError(null)

    if (allowUnassign && selectedId === 0) {
      await onSubmit(0)
      onOpenChange(false)
      return
    }

    if (selectedId === null || selectedId <= 0) {
      setError('یکی از گزینه‌ها را انتخاب کنید')
      return
    }

    await onSubmit(selectedId)
    onOpenChange(false)
  }

  const title = type === 'supervisor' ? 'انتخاب سرپرست' : 'انتخاب کارشناس'
  const description =
    type === 'supervisor'
      ? 'سرپرستی جدید برای مشتری انتخاب کنید.'
      : 'کارشناس مورد نظر خود را انتخاب کنید.'

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>
            {description} - مشتری: <strong>{customerName}</strong>
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4">
          <Input
            placeholder="جست‌وجو..."
            value={search}
            onChange={(event) => setSearch(event.target.value)}
          />

          {optionsQuery.isLoading ? (
            <div className="flex items-center justify-center py-6">
              <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
            </div>
          ) : optionsQuery.isError ? (
            <p className="text-sm text-destructive">
              {getErrorMessage(optionsQuery.error)}
            </p>
          ) : entries.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              گزینه‌ای با جست‌وجوی فعلی یافت نشد.
            </p>
          ) : (
            <div className="grid max-h-72 gap-2 overflow-y-auto rounded-md border p-2">
              {entries.map((item: Agent | Supervisor) => {
                const isSelected = selectedId === item.id
                const secondaryLabel =
                  type === 'agent'
                    ? (item as Agent).supervisor_name || 'بدون سرپرست'
                    : `${(item as Supervisor).agents_count ?? 0} کارشناس`

                return (
                  <button
                    type="button"
                    key={item.id}
                    onClick={() => setSelectedId(item.id)}
                    className={`
                      flex flex-col items-start rounded-md border p-3 text-right transition
                      ${isSelected ? 'border-primary bg-primary/10' : 'border-border hover:border-primary'}
                    `}
                  >
                    <div className="flex w-full items-center justify-between">
                      <span className="text-sm font-semibold text-foreground">
                        {item.display_name || item.username}
                      </span>
                      <Badge variant="outline">شناسه: {item.id}</Badge>
                    </div>
                    <span className="mt-1 text-xs text-muted-foreground">{secondaryLabel}</span>
                  </button>
                )
              })}
              {allowUnassign && (
                <button
                  type="button"
                  onClick={() => setSelectedId(0)}
                  className={`flex items-center justify-between rounded-md border border-dashed p-3 text-sm transition ${
                    selectedId === 0
                      ? 'border-destructive bg-destructive/10 text-destructive'
                      : 'border-border text-muted-foreground hover:border-destructive'
                  }`}
                >
                  <span>حذف تخصیص</span>
                  <UserCheck className="h-4 w-4" />
                </button>
              )}
            </div>
          )}

          {error && <p className="text-xs text-destructive">{error}</p>}

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={isSubmitting}>
              انصراف
            </Button>
            <Button type="submit" disabled={isSubmitting || optionsQuery.isLoading}>
              {isSubmitting ? 'در حال ذخیره...' : 'تأیید'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
