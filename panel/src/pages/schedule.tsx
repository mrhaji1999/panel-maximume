import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Loader2, Save, Edit } from 'lucide-react'
import { useAuth } from '@/store/authStore'
import { useNotification } from '@/store/uiStore'
import { usersApi, cardsApi, scheduleApi } from '@/lib/api'
import {
  AvailabilityResponse,
  AvailabilitySlot,
  Card as CardType,
  ScheduleResponse,
  SupervisorListResponse,
  SupervisorCardsResponse,
} from '@/types'
import { cn, getErrorMessage, getWeekdayName } from '@/lib/utils'

const HOURS = Array.from({ length: 11 }, (_, index) => index + 8)
const WEEKDAYS = [0, 1, 2, 3, 4, 5, 6]

function buildKey(weekday: number, hour: number) {
  return `${weekday}-${hour}`
}

export function SchedulePage() {
  const [searchParams] = useSearchParams()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const { success, error: notifyError } = useNotification()

  const [selectedSupervisorId, setSelectedSupervisorId] = useState<number | null>(null)
  const [selectedCardId, setSelectedCardId] = useState<number | null>(null)
  const [matrixDraft, setMatrixDraft] = useState<Record<string, number>>({})
  const [isDirty, setIsDirty] = useState(false)
  const [editingSlot, setEditingSlot] = useState<{ weekday: number; hour: number } | null>(null)
  const [newCapacity, setNewCapacity] = useState('')

  const supervisorParam = searchParams.get('supervisor_id')
  const supervisorParamNumber = supervisorParam ? Number(supervisorParam) : undefined

  const supervisorsQuery = useQuery({
    queryKey: ['supervisors', { per_page: 100 }],
    queryFn: async () => {
      const response = await usersApi.getSupervisors({ per_page: 100 })
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در دریافت سرپرست‌ها')
      }
      return response.data as SupervisorListResponse
    },
  })

  const supervisors = supervisorsQuery.data?.items ?? []

  useEffect(() => {
    if (!supervisors.length) {
      setSelectedSupervisorId(null)
      return
    }

    if (
      supervisorParamNumber &&
      supervisors.some((item) => item.id === supervisorParamNumber)
    ) {
      setSelectedSupervisorId(supervisorParamNumber)
      return
    }

    if (
      selectedSupervisorId === null ||
      !supervisors.some((item) => item.id === selectedSupervisorId)
    ) {
      setSelectedSupervisorId(supervisors[0].id)
    }
  }, [supervisors, supervisorParamNumber, selectedSupervisorId])

  const cardsQuery = useQuery({
    queryKey: ['supervisor-cards', selectedSupervisorId],
    enabled: Boolean(selectedSupervisorId),
    queryFn: async () => {
      if (!selectedSupervisorId) {
        return null
      }
      const response = await cardsApi.getSupervisorCards(selectedSupervisorId)
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در دریافت کارت‌های سرپرست')
      }
      return response.data as SupervisorCardsResponse
    },
  })

  const cards = (cardsQuery.data?.items ?? []) as CardType[]

  useEffect(() => {
    if (!cards.length) {
      setSelectedCardId(null)
      setMatrixDraft({})
      setIsDirty(false)
      return
    }

    if (
      selectedCardId === null ||
      !cards.some((card) => card.id === selectedCardId)
    ) {
      setSelectedCardId(cards[0].id)
    }
  }, [cards, selectedCardId])

  const scheduleQuery = useQuery({
    queryKey: ['schedule', selectedSupervisorId, selectedCardId],
    enabled: Boolean(selectedSupervisorId && selectedCardId),
    queryFn: async () => {
      if (!selectedSupervisorId || !selectedCardId) {
        throw new Error('سرپرست یا کارت انتخاب نشده است')
      }
      const response = await scheduleApi.getSchedule(selectedSupervisorId, selectedCardId)
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در دریافت جدول ظرفیت')
      }
      return response.data as ScheduleResponse
    },
  })

  const availabilityQuery = useQuery({
    queryKey: ['availability', selectedSupervisorId, selectedCardId],
    enabled: Boolean(selectedSupervisorId && selectedCardId),
    queryFn: async () => {
      if (!selectedSupervisorId || !selectedCardId) {
        throw new Error('سرپرست یا کارت انتخاب نشده است')
      }
      const response = await scheduleApi.getAvailability(selectedCardId, selectedSupervisorId)
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در دریافت ظرفیت رزرو')
      }
      return response.data as AvailabilityResponse
    },
  })

  useEffect(() => {
    if (!selectedSupervisorId || !selectedCardId) {
      return
    }

    const base: Record<string, number> = {}
    WEEKDAYS.forEach((day) => {
      HOURS.forEach((hour) => {
        base[buildKey(day, hour)] = 0
      })
    })

    if (scheduleQuery.data?.matrix?.length) {
      scheduleQuery.data.matrix.forEach((slot) => {
        const key = buildKey(slot.weekday, slot.hour)
        base[key] = slot.capacity ?? 0
      })
    }

    setMatrixDraft(base)
    setIsDirty(false)
  }, [selectedSupervisorId, selectedCardId, scheduleQuery.data])

  const availabilityMap = useMemo(() => {
    const map = new Map<string, AvailabilitySlot>()
    availabilityQuery.data?.slots?.forEach((slot) => {
      map.set(buildKey(slot.weekday, slot.hour), slot)
    })
    return map
  }, [availabilityQuery.data])

  const canManage =
    !!selectedSupervisorId &&
    (user?.role === 'company_manager' ||
      (user?.role === 'supervisor' && user?.id === selectedSupervisorId))

  const handleSlotUpdate = (weekday: number, hour: number, capacity: number) => {
    const key = buildKey(weekday, hour)
    setMatrixDraft((prev) => ({
      ...prev,
      [key]: capacity,
    }))
    setIsDirty(true)
  }

  const updateScheduleMutation = useMutation({
    mutationFn: async (matrix: { weekday: number; hour: number; capacity: number }[]) => {
      if (!selectedSupervisorId || !selectedCardId) {
        throw new Error('سرپرست یا کارت انتخاب نشده است')
      }

      const response = await scheduleApi.updateSchedule(
        selectedSupervisorId,
        selectedCardId,
        matrix
      )

      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در ذخیره ظرفیت')
      }

      return response.data as ScheduleResponse
    },
    onSuccess: () => {
      success('ذخیره شد', 'ظرفیت‌ها با موفقیت بروزرسانی شد')
      setIsDirty(false)
      if (selectedSupervisorId && selectedCardId) {
        queryClient.invalidateQueries({
          queryKey: ['schedule', selectedSupervisorId, selectedCardId],
        })
        queryClient.invalidateQueries({
          queryKey: ['availability', selectedSupervisorId, selectedCardId],
        })
      }
    },
    onError: (error) => {
      notifyError('خطا در ذخیره', getErrorMessage(error))
    },
  })

  const handleSaveAll = () => {
    if (!selectedSupervisorId || !selectedCardId) {
      notifyError('انتخاب ناقص', 'لطفاً ابتدا سرپرست و کارت را انتخاب کنید')
      return
    }

    const matrixPayload = WEEKDAYS.flatMap((weekday) =>
      HOURS.map((hour) => ({
        weekday,
        hour,
        capacity: matrixDraft[buildKey(weekday, hour)] ?? 0,
      }))
    )

    updateScheduleMutation.mutate(matrixPayload)
  }

  const handleOpenEdit = (weekday: number, hour: number) => {
    const key = buildKey(weekday, hour)
    setEditingSlot({ weekday, hour })
    setNewCapacity(String(matrixDraft[key] ?? 0))
  }

  const handleConfirmEdit = () => {
    if (!editingSlot) return
    const value = Number(newCapacity)
    if (!Number.isFinite(value) || value < 0) {
      notifyError('ورودی نامعتبر', 'ظرفیت باید یک عدد معتبر و بزرگ‌تر یا مساوی صفر باشد')
      return
    }

    handleSlotUpdate(editingSlot.weekday, editingSlot.hour, Math.floor(value))
    setEditingSlot(null)
    setNewCapacity('')
  }

  const renderSupervisorSelect = () => {
    if (supervisorsQuery.isLoading) {
      return <div className="h-10 w-full animate-pulse rounded-md bg-muted" />
    }

    if (!supervisors.length) {
      return (
        <p className="text-sm text-muted-foreground">
          سرپرستی برای نمایش وجود ندارد. ابتدا سرپرست ایجاد کنید.
        </p>
      )
    }

    return (
      <select
        className="h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring"
        value={selectedSupervisorId ?? ''}
        onChange={(event) => setSelectedSupervisorId(Number(event.target.value))}
      >
        {supervisors.map((supervisor) => (
          <option key={supervisor.id} value={supervisor.id}>
            {supervisor.display_name}
          </option>
        ))}
      </select>
    )
  }

  const renderCardSelect = () => {
    if (!selectedSupervisorId) {
      return (
        <p className="text-sm text-muted-foreground">
          ابتدا سرپرست را انتخاب کنید.
        </p>
      )
    }

    if (cardsQuery.isLoading) {
      return <div className="h-10 w-full animate-pulse rounded-md bg-muted" />
    }

    if (!cards.length) {
      return (
        <p className="text-sm text-muted-foreground">
          هیچ کارتی به این سرپرست اختصاص داده نشده است.
        </p>
      )
    }

    return (
      <select
        className="h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring"
        value={selectedCardId ?? ''}
        onChange={(event) => setSelectedCardId(Number(event.target.value))}
      >
        {cards.map((card) => (
          <option key={card.id} value={card.id}>
            {card.title}
          </option>
        ))}
      </select>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div className="space-y-1">
          <h1 className="text-2xl font-bold tracking-tight">زمان‌بندی</h1>
          <p className="text-muted-foreground">
            ظرفیت‌های هفتگی هر سرپرست و کارت را مدیریت کنید.
          </p>
        </div>
        <div className="flex items-center gap-2">
          {!canManage && selectedSupervisorId && (
            <Badge variant="secondary">فقط مشاهده</Badge>
          )}
          <Button
            onClick={handleSaveAll}
            disabled={
              !canManage ||
              !selectedSupervisorId ||
              !selectedCardId ||
              !isDirty ||
              updateScheduleMutation.isPending
            }
            className="gap-2"
          >
            {updateScheduleMutation.isPending ? (
              <>
                <Loader2 className="h-4 w-4 animate-spin" />
                در حال ذخیره...
              </>
            ) : (
              <>
                <Save className="h-4 w-4" />
                ذخیره تغییرات
              </>
            )}
          </Button>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>انتخاب سرپرست و کارت</CardTitle>
          <CardDescription>
            ابتدا سرپرست و کارت مرتبط را انتخاب کنید تا جدول ظرفیت نمایش داده شود.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="grid gap-4 md:grid-cols-2">
            <div className="space-y-2">
              <label className="text-sm font-medium text-foreground">سرپرست</label>
              {renderSupervisorSelect()}
            </div>
            <div className="space-y-2">
              <label className="text-sm font-medium text-foreground">کارت</label>
              {renderCardSelect()}
            </div>
          </div>
        </CardContent>
      </Card>

      {selectedSupervisorId && selectedCardId && (
        <Card>
          <CardHeader>
            <CardTitle>جدول ظرفیت هفتگی</CardTitle>
            <CardDescription>
              ظرفیت هر اسلات زمانی از ساعت ۸ تا ۱۸ را مدیریت کنید.
            </CardDescription>
          </CardHeader>
          <CardContent>
            {scheduleQuery.isLoading ? (
              <div className="flex h-48 items-center justify-center">
                <Loader2 className="h-8 w-8 animate-spin text-primary" />
              </div>
            ) : scheduleQuery.isError ? (
              <div className="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                {getErrorMessage(scheduleQuery.error)}
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full border-collapse">
                  <thead>
                    <tr>
                      <th className="border bg-muted p-2 text-right text-sm font-medium">
                        روز
                      </th>
                      {HOURS.map((hour) => (
                        <th
                          key={hour}
                          className="border bg-muted px-2 py-1 text-center text-xs font-medium"
                        >
                          {hour}:00
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {WEEKDAYS.map((weekday) => (
                      <tr key={weekday}>
                        <td className="border bg-muted/50 p-2 text-sm font-medium">
                          {getWeekdayName(weekday)}
                        </td>
                        {HOURS.map((hour) => {
                          const key = buildKey(weekday, hour)
                          const capacity = matrixDraft[key] ?? 0
                          const slotAvailability = availabilityMap.get(key)
                          const used = slotAvailability?.used ?? 0
                          const available =
                            slotAvailability?.remaining ?? Math.max(0, capacity - used)
                          const isOverbooked = used > capacity

                          return (
                            <td
                              key={key}
                              className={cn(
                                'border p-2 text-center align-top',
                                isOverbooked && 'bg-red-50'
                              )}
                            >
                              <div className="space-y-1">
                                <div className="text-sm font-semibold text-foreground">
                                  {capacity}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                  رزرو: {used}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                  خالی: {available}
                                </div>
                                {canManage && (
                                  <Button
                                    variant="ghost"
                                    size="icon"
                                    className="h-6 w-6"
                                    onClick={() => handleOpenEdit(weekday, hour)}
                                  >
                                    <Edit className="h-3.5 w-3.5" />
                                  </Button>
                                )}
                              </div>
                            </td>
                          )
                        })}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </CardContent>
        </Card>
      )}

      {editingSlot && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
          <Card className="w-full max-w-md">
            <CardHeader>
              <CardTitle>ویرایش ظرفیت اسلات</CardTitle>
              <CardDescription>
                ظرفیت روز {getWeekdayName(editingSlot.weekday)} ساعت {editingSlot.hour}:00
              </CardDescription>
            </CardHeader>
            <CardContent>
              <div className="space-y-4">
                <div>
                  <label className="block text-sm font-medium text-foreground mb-1">
                    ظرفیت جدید
                  </label>
                  <input
                    type="number"
                    min={0}
                    className="h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring"
                    value={newCapacity}
                    onChange={(event) => setNewCapacity(event.target.value)}
                  />
                </div>
                <div className="flex gap-2">
                  <Button className="flex-1" onClick={handleConfirmEdit}>
                    ذخیره
                  </Button>
                  <Button
                    variant="outline"
                    className="flex-1"
                    onClick={() => {
                      setEditingSlot(null)
                      setNewCapacity('')
                    }}
                  >
                    لغو
                  </Button>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>
      )}
    </div>
  )
}
