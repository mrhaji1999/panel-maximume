import { CustomerStatus } from '@/types'

export const ALL_STATUSES: CustomerStatus[] = [
  'normal',
  'upsell',
  'upsell_pending',
  'upsell_paid',
  'no_answer',
  'canceled',
]

export const STATUS_LABELS: Record<CustomerStatus, string> = {
  normal: 'عادی',
  upsell: 'خرید افزایشی',
  upsell_pending: 'خرید افزایشی در انتظار پرداخت',
  upsell_paid: 'خرید افزایشی پرداخت شده',
  no_answer: 'جواب نداد',
  canceled: 'انصراف داد',
}
