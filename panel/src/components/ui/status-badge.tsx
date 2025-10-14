import { Badge } from './badge'
import { getStatusLabel, getStatusColor } from '@/lib/utils'
import { CustomerStatus } from '@/types'

interface StatusBadgeProps {
  status: CustomerStatus
  className?: string
}

export function StatusBadge({ status, className }: StatusBadgeProps) {
  const label = getStatusLabel(status)
  const colorClass = getStatusColor(status)
  
  return (
    <Badge 
      variant="outline" 
      className={`${colorClass} ${className}`}
    >
      {label}
    </Badge>
  )
}
