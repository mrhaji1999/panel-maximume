import { CustomerStatus } from '@/types'

export const ALL_STATUSES: CustomerStatus[] = [
  'normal',
  'upsell',
  'upsell_pending',
  'upsell_paid',
  'no_answer',
  'canceled',
]

export const HIGHLIGHT_STATUSES: CustomerStatus[] = ['upsell_pending', 'upsell_paid']

export const SECONDARY_STATUSES: CustomerStatus[] = ['upsell', 'normal', 'no_answer', 'canceled']

export const STATUS_LABELS: Record<CustomerStatus, string> = {
  normal: 'عادی',
  upsell: 'خرید افزایشی',
  upsell_pending: 'خرید افزایشی در انتظار پرداخت',
  upsell_paid: 'خرید افزایشی پرداخت شده',
  no_answer: 'جواب نداد',
  canceled: 'انصراف داد',
}

export const STATUS_COLORS: Record<CustomerStatus, string> = {
  normal: 'status-normal',
  upsell: 'status-upsell',
  upsell_pending: 'status-upsell-pending',
  upsell_paid: 'status-upsell-paid',
  no_answer: 'status-no-answer',
  canceled: 'status-canceled',
}

export function isCustomerStatus(value: string): value is CustomerStatus {
  return (ALL_STATUSES as string[]).includes(value)
}
