import { useEffect, useMemo, useState, type ComponentType } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { smsApi } from '@/lib/api'
import {
  SMSLog,
  SMSLogListResponse,
  SMSStats,
  SMSStatus,
} from '@/types'
import {
  formatDateTime,
  formatNumber,
  getErrorMessage,
} from '@/lib/utils'
import { useNotification } from '@/store/uiStore'
import { useDebounce } from '@/hooks/useDebounce'
import { Search, Send, MessageSquare, Phone, Loader2, CheckCircle, XCircle, Clock } from 'lucide-react'

const PER_PAGE = 20

type StatusFilter = 'all' | SMSStatus

const STATUS_TABS: { key: StatusFilter; label: string }[] = [
  { key: 'all', label: 'همه' },
  { key: 'sent', label: 'ارسال شده' },
  { key: 'failed', label: 'ناموفق' },
]

export function SMSPage() {
  const queryClient = useQueryClient()
  const { success, error: notifyError } = useNotification()

  const [page, setPage] = useState(1)
  const [searchTerm, setSearchTerm] = useState('')
  const [selectedStatus, setSelectedStatus] = useState<StatusFilter>('all')
  const [newSMS, setNewSMS] = useState({ to: '', bodyId: '', message: '' })

  const debouncedSearch = useDebounce(searchTerm, 400)

  useEffect(() => {
    setPage(1)
  }, [debouncedSearch, selectedStatus])

  const filters = useMemo(
    () => ({
      page,
      per_page: PER_PAGE,
      search: debouncedSearch || undefined,
      status: selectedStatus === 'all' ? undefined : selectedStatus,
    }),
    [page, debouncedSearch, selectedStatus]
  )

  const logsQuery = useQuery({
    queryKey: ['sms-logs', filters],
    queryFn: async () => {
      const response = await smsApi.getSMSLogs(filters)
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در دریافت پیامک‌ها')
      }
      return response.data as SMSLogListResponse
    },
    placeholderData: (previousData) => previousData,
  })

  const statsQuery = useQuery({
    queryKey: ['sms-statistics'],
    queryFn: async () => {
      const response = await smsApi.getSMSStatistics({ days: 30 })
      if (!response.success) {
        throw new Error(response.error?.message || 'خطا در دریافت آمار پیامک')
      }
      return response.data as SMSStats
    },
  })

  const sendMutation = useMutation({
    mutationFn: async () => {
      const payload = {
        to: newSMS.to.trim(),
        bodyId: newSMS.bodyId.trim(),
        text: newSMS.message
          .split('\n')
          .map((line) => line.trim())
          .filter(Boolean),
      }

      if (!payload.to || !payload.bodyId) {
        throw new Error('شماره گیرنده و شناسه بدنه پیامک الزامی است')
      }

      const response = await smsApi.sendSMS(payload)
      if (!response.success) {
        throw new Error(response.error?.message || 'ارسال پیامک ناموفق بود')
      }
      return response.data
    },
    onSuccess: () => {
      success('پیامک ارسال شد', 'پیامک با موفقیت ارسال گردید')
      setNewSMS({ to: '', bodyId: '', message: '' })
      queryClient.invalidateQueries({ queryKey: ['sms-logs'] })
    },
    onError: (error) => {
      notifyError('خطا در ارسال پیامک', getErrorMessage(error))
    },
  })

  const testMutation = useMutation({
    mutationFn: async () => {
      const response = await smsApi.testSMS()
      if (!response.success) {
        throw new Error(response.error?.message || 'آزمایش اتصال پیامک ناموفق بود')
      }
      return response.data
    },
    onSuccess: (data) => {
      success('آزمایش موفق', data?.message || 'اتصال به سرویس پیامک برقرار است')
    },
    onError: (error) => {
      notifyError('خطا در آزمایش پیامک', getErrorMessage(error))
    },
  })

  const logs = logsQuery.data?.items ?? []
  const pagination = logsQuery.data?.pagination
  const totalPages = pagination?.total_pages ?? 1
  const totalLogs = pagination?.total ?? 0

  const statusSummary = useMemo(() => {
    return logs.reduce(
      (acc: Record<string, number>, log: SMSLog) => {
        if (log.status === 'sent') {
          acc.sent += 1
        } else if (log.status === 'failed') {
          acc.failed += 1
        }
        return acc
      },
      { sent: 0, failed: 0 }
    )
  }, [logs])

  const statusTabs = STATUS_TABS.map((tab) => ({
    ...tab,
    count:
      tab.key === 'all'
        ? totalLogs
        : tab.key === 'sent'
        ? statusSummary.sent
        : statusSummary.failed,
  }))

  const smsStats = statsQuery.data
  const isLogsLoading = logsQuery.isLoading
  const isLogsError = logsQuery.isError
  const logsError = logsQuery.error

  const handleSendSMS = () => {
    sendMutation.mutate()
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">پیامک‌ها</h1>
          <p className="text-muted-foreground">
            ارسال و مشاهده پیامک‌های ارسال‌شده از سیستم
          </p>
        </div>
        {smsStats && (
          <Badge variant="outline">کل پیامک‌ها: {formatNumber(smsStats.total_sent)}</Badge>
        )}
      </div>

      <Card>
        <CardHeader>
          <CardTitle>ارسال پیامک</CardTitle>
          <CardDescription>پیامی را برای مشتریان ارسال کنید</CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          <div className="grid gap-3 md:grid-cols-3">
            <Input
              placeholder="شماره گیرنده"
              value={newSMS.to}
              onChange={(event) => setNewSMS((prev) => ({ ...prev, to: event.target.value }))}
            />
            <Input
              placeholder="شناسه بدنه"
              value={newSMS.bodyId}
              onChange={(event) => setNewSMS((prev) => ({ ...prev, bodyId: event.target.value }))}
            />
            <div className="flex gap-2">
              <Button
                className="flex-1"
                onClick={handleSendSMS}
                disabled={sendMutation.isPending}
              >
                {sendMutation.isPending ? (
                  <span className="flex items-center gap-2">
                    <Loader2 className="h-4 w-4 animate-spin" /> در حال ارسال...
                  </span>
                ) : (
                  <span className="flex items-center gap-2">
                    <Send className="h-4 w-4" /> ارسال
                  </span>
                )}
              </Button>
              <Button
                type="button"
                variant="outline"
                onClick={() => testMutation.mutate()}
                disabled={testMutation.isPending}
              >
                {testMutation.isPending ? (
                  <Loader2 className="h-4 w-4 animate-spin" />
                ) : (
                  'آزمایش سرویس'
                )}
              </Button>
            </div>
          </div>
          <textarea
            className="h-24 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring"
            placeholder="متن پیامک"
            value={newSMS.message}
            onChange={(event) => setNewSMS((prev) => ({ ...prev, message: event.target.value }))}
          />
        </CardContent>
      </Card>

      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        {smsStats && (
          <>
            <StatCard title="کل ارسال" value={smsStats.total_sent} icon={MessageSquare} />
            <StatCard title="موفق" value={smsStats.successful} icon={CheckCircle} />
            <StatCard title="ناموفق" value={smsStats.failed} icon={XCircle} />
            <StatCard title="نرخ موفقیت" value={`${smsStats.success_rate}%`} icon={Clock} />
          </>
        )}
      </div>

      <Card>
        <CardHeader>
          <CardTitle>فیلترها</CardTitle>
          <CardDescription>بر اساس شماره، مشتری یا وضعیت جست‌وجو کنید</CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div className="relative flex-1">
              <Search className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                value={searchTerm}
                onChange={(event) => setSearchTerm(event.target.value)}
                placeholder="جست‌وجو در شماره یا نام مشتری"
                className="pr-10"
              />
            </div>
            <div className="flex items-center gap-2 overflow-x-auto">
              {statusTabs.map((tab) => (
                <Button
                  key={tab.key}
                  variant={selectedStatus === tab.key ? 'default' : 'outline'}
                  size="sm"
                  onClick={() => setSelectedStatus(tab.key)}
                  className="whitespace-nowrap"
                >
                  {tab.label}
                  {tab.count > 0 && (
                    <Badge variant="secondary" className="mr-2">
                      {formatNumber(tab.count)}
                    </Badge>
                  )}
                </Button>
              ))}
            </div>
          </div>
        </CardContent>
      </Card>

      {isLogsLoading ? (
        <LoadingSkeleton />
      ) : isLogsError ? (
        <Card>
          <CardHeader>
            <CardTitle>خطا در بارگذاری پیامک‌ها</CardTitle>
            <CardDescription>{getErrorMessage(logsError)}</CardDescription>
          </CardHeader>
        </Card>
      ) : logs.length === 0 ? (
        <Card>
          <CardContent className="p-12 text-center text-muted-foreground">
            <MessageSquare className="mx-auto mb-3 h-10 w-10" />
            <p>پیامی مطابق با فیلترهای فعلی یافت نشد.</p>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4">
          {logs.map((log: SMSLog) => (
            <SMSLogCard key={log.id} log={log} />
          ))}
        </div>
      )}

      {pagination && totalLogs > 0 && (
        <div className="flex items-center justify-between">
          <span className="text-sm text-muted-foreground">
            صفحه {formatNumber(page)} از {formatNumber(totalPages)}
          </span>
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              onClick={() => setPage((prev) => Math.max(prev - 1, 1))}
              disabled={page === 1 || logsQuery.isFetching}
            >
              قبلی
            </Button>
            <Button
              variant="outline"
              onClick={() => setPage((prev) => Math.min(prev + 1, totalPages))}
              disabled={page === totalPages || logsQuery.isFetching}
            >
              بعدی
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}

function StatCard({
  title,
  value,
  icon: Icon,
}: {
  title: string
  value: number | string
  icon: ComponentType<{ className?: string }>
}) {
  return (
    <Card>
      <CardContent className="flex items-center justify-between p-6">
        <div>
          <p className="text-sm text-muted-foreground">{title}</p>
          <p className="text-2xl font-semibold text-foreground">
            {typeof value === 'number' ? formatNumber(value) : value}
          </p>
        </div>
        <div className="rounded-full bg-primary/10 p-3 text-primary">
          <Icon className="h-5 w-5" />
        </div>
      </CardContent>
    </Card>
  )
}

function SMSLogCard({ log }: { log: SMSLog }) {
  const statusVariant = log.status === 'failed' ? 'destructive' : 'success'
  const statusLabel = log.status === 'failed' ? 'ناموفق' : 'ارسال شده'

  return (
    <Card>
      <CardContent className="p-6">
        <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
          <div className="flex flex-1 items-start gap-3">
            <div className="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary">
              <Phone className="h-5 w-5" />
            </div>
            <div className="space-y-2">
              <div className="flex flex-wrap items-center gap-2">
                <h3 className="text-base font-semibold text-foreground">
                  {log.customer_name || 'کاربر ناشناس'}
                </h3>
                <Badge variant="outline">{log.phone}</Badge>
              </div>
              <p className="text-sm text-muted-foreground">شناسه بدنه: {log.bodyId || '-'}</p>
              <p className="text-sm text-foreground whitespace-pre-wrap">{log.message}</p>
              {log.error_message && (
                <p className="text-sm text-destructive">خطا: {log.error_message}</p>
              )}
              {log.sent_by_name && (
                <p className="text-xs text-muted-foreground">ارسال شده توسط: {log.sent_by_name}</p>
              )}
            </div>
          </div>
          <div className="w-full text-sm text-muted-foreground md:w-40 md:text-right">
            <Badge variant={statusVariant}>{statusLabel}</Badge>
            <p className="mt-2">ارسال در:</p>
            <p className="font-medium text-foreground">
              {formatDateTime(log.created_at)}
            </p>
          </div>
        </div>
      </CardContent>
    </Card>
  )
}

function LoadingSkeleton() {
  return (
    <div className="space-y-4">
      {[...Array(4)].map((_, index) => (
        <Card key={index}>
          <CardContent className="p-6">
            <div className="flex items-center gap-4">
              <div className="h-12 w-12 animate-pulse rounded-full bg-muted" />
              <div className="flex-1 space-y-2">
                <div className="h-4 w-1/4 animate-pulse rounded bg-muted" />
                <div className="h-3 w-1/2 animate-pulse rounded bg-muted" />
              </div>
            </div>
          </CardContent>
        </Card>
      ))}
    </div>
  )
}
