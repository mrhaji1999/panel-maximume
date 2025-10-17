import { type ClassValue, clsx } from "clsx"
import { twMerge } from "tailwind-merge"
import { format, parseISO, isValid } from 'date-fns'
import { faIR } from 'date-fns/locale'
import { STATUS_COLORS, STATUS_LABELS, isCustomerStatus } from '@/constants/customer-status'

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

// Date formatting utilities
export function formatDate(date: string | Date, formatStr: string = 'yyyy/MM/dd'): string {
  try {
    const dateObj = typeof date === 'string' ? parseISO(date) : date
    if (!isValid(dateObj)) return 'تاریخ نامعتبر'
    return format(dateObj, formatStr, { locale: faIR })
  } catch {
    return 'تاریخ نامعتبر'
  }
}

export function formatDateTime(date: string | Date): string {
  return formatDate(date, 'yyyy/MM/dd HH:mm')
}

export function formatTime(date: string | Date): string {
  return formatDate(date, 'HH:mm')
}

// Status utilities
export function getStatusLabel(status: string): string {
  return isCustomerStatus(status) ? STATUS_LABELS[status] : status
}

export function getStatusColor(status: string): string {
  return isCustomerStatus(status) ? STATUS_COLORS[status] : 'bg-gray-100 text-gray-800'
}

// Role utilities
export function getRoleLabel(role: string): string {
  const roleLabels: Record<string, string> = {
    'company_manager': 'مدیر شرکت',
    'supervisor': 'سرپرست',
    'agent': 'کارشناس',
  }
  return roleLabels[role] || role
}

// Validation utilities
export function isValidEmail(email: string): boolean {
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
  return emailRegex.test(email)
}

export function isValidPhone(phone: string): boolean {
  const phoneRegex = /^(\+98|0)?9\d{9}$/
  return phoneRegex.test(phone.replace(/\s/g, ''))
}

// String utilities
export function truncateText(text: string, maxLength: number): string {
  if (text.length <= maxLength) return text
  return text.slice(0, maxLength) + '...'
}

export function capitalizeFirst(str: string): string {
  return str.charAt(0).toUpperCase() + str.slice(1)
}

// Number utilities
export function formatNumber(num: number): string {
  return new Intl.NumberFormat('fa-IR').format(num)
}

export function formatCurrency(amount: number): string {
  return new Intl.NumberFormat('fa-IR', {
    style: 'currency',
    currency: 'IRR',
  }).format(amount)
}

// Array utilities
export function groupBy<T>(array: T[], key: keyof T): Record<string, T[]> {
  return array.reduce((groups, item) => {
    const group = String(item[key])
    groups[group] = groups[group] || []
    groups[group].push(item)
    return groups
  }, {} as Record<string, T[]>)
}

export function sortBy<T>(array: T[], key: keyof T, direction: 'asc' | 'desc' = 'asc'): T[] {
  return [...array].sort((a, b) => {
    const aVal = a[key]
    const bVal = b[key]
    
    if (aVal < bVal) return direction === 'asc' ? -1 : 1
    if (aVal > bVal) return direction === 'asc' ? 1 : -1
    return 0
  })
}

// Local storage utilities
export function getStorageItem<T>(key: string, defaultValue: T): T {
  try {
    const item = localStorage.getItem(key)
    return item ? JSON.parse(item) : defaultValue
  } catch {
    return defaultValue
  }
}

export function setStorageItem<T>(key: string, value: T): void {
  try {
    localStorage.setItem(key, JSON.stringify(value))
  } catch {
    // Handle storage quota exceeded
    console.warn('Failed to save to localStorage')
  }
}

export function removeStorageItem(key: string): void {
  try {
    localStorage.removeItem(key)
  } catch {
    console.warn('Failed to remove from localStorage')
  }
}

// Debounce utility
export function debounce<T extends (...args: any[]) => any>(
  func: T,
  wait: number
): (...args: Parameters<T>) => void {
  let timeout: NodeJS.Timeout
  return (...args: Parameters<T>) => {
    clearTimeout(timeout)
    timeout = setTimeout(() => func(...args), wait)
  }
}

// Throttle utility
export function throttle<T extends (...args: any[]) => any>(
  func: T,
  limit: number
): (...args: Parameters<T>) => void {
  let inThrottle: boolean
  return (...args: Parameters<T>) => {
    if (!inThrottle) {
      func(...args)
      inThrottle = true
      setTimeout(() => (inThrottle = false), limit)
    }
  }
}

// Error handling utilities
export function getErrorMessage(error: any): string {
  if (typeof error === 'string') return error
  if (error?.message) return error.message
  if (error?.error?.message) return error.error.message
  return 'خطای نامشخص رخ داده است'
}

// URL utilities
export function buildQueryString(params: Record<string, any>): string {
  const searchParams = new URLSearchParams()
  
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') {
      searchParams.append(key, String(value))
    }
  })
  
  return searchParams.toString()
}

// Time utilities
export function getTimeAgo(date: string | Date): string {
  const now = new Date()
  const past = typeof date === 'string' ? new Date(date) : date
  const diffInSeconds = Math.floor((now.getTime() - past.getTime()) / 1000)
  
  if (diffInSeconds < 60) return 'همین الان'
  if (diffInSeconds < 3600) return `${Math.floor(diffInSeconds / 60)} دقیقه پیش`
  if (diffInSeconds < 86400) return `${Math.floor(diffInSeconds / 3600)} ساعت پیش`
  if (diffInSeconds < 2592000) return `${Math.floor(diffInSeconds / 86400)} روز پیش`
  
  return formatDate(past, 'yyyy/MM/dd')
}

// Weekday utilities
export function getWeekdayName(weekday: number): string {
  const weekdays = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه', 'شنبه']
  return weekdays[weekday] || 'نامشخص'
}

export function getWeekdayShortName(weekday: number): string {
  const weekdays = ['ی', 'د', 'س', 'چ', 'پ', 'ج', 'ش']
  return weekdays[weekday] || '؟'
}
