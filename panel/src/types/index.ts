// User types
export interface User {
  id: number
  username: string
  email: string
  first_name: string
  last_name: string
  display_name: string
  role: UserRole
  assigned_cards?: number[]
  assigned_card_titles?: string[]
  supervisor_id?: number | null
  supervisor_name?: string
}

export type UserRole = 'company_manager' | 'supervisor' | 'agent'

// Customer types
export interface Customer {
  id: number
  username: string
  email: string
  first_name: string
  last_name: string
  display_name: string
  status: CustomerStatus
  assigned_supervisor: number
  assigned_supervisor_name: string | null
  assigned_agent: number
  assigned_agent_name: string | null
  card_id: number
  card_title: string | null
  phone?: string | null
  registered_at?: string
  random_code?: string | null
}

export interface CustomerDetail extends Customer {
  random_code?: string
  status_logs: StatusLog[]
  notes: CustomerNote[]
}

export interface CustomerListResponse {
  items: Customer[]
  pagination: PaginationMeta
}

export interface CustomerTabData {
  items: Customer[]
  total: number
}

export interface CustomerTabsResponse {
  tabs: Record<string, CustomerTabData>
}

// Supervisor types
export interface Supervisor extends User {
  assigned_cards: number[]
  assigned_card_titles: string[]
  agents_count: number
  customers_count: number
}

export interface SupervisorListResponse {
  items: Supervisor[]
  pagination: PaginationMeta
}

export interface Agent extends Omit<User, 'supervisor_name'> {
  supervisor_id?: number | null
  supervisor_name?: string | null
  customers_count: number
  status?: string
}

export interface AgentListResponse {
  items: Agent[]
  pagination: PaginationMeta
}

export type CustomerStatus = 
  | 'no_answer' 
  | 'canceled' 
  | 'upsell' 
  | 'normal' 
  | 'upsell_pending' 
  | 'upsell_paid'

export interface StatusLog {
  id: number
  customer_id: number
  old_status: string | null
  new_status: string
  changed_by: number
  changed_by_name: string | null
  reason: string | null
  created_at: string
}

export interface CustomerNote {
  id: string
  note: string
  added_by: number
  added_by_name: string
  added_at: string
}

// Card types
export interface Card {
  id: number
  title: string
  content: string
  excerpt: string
  slug: string
  created_at: string
  modified_at: string
  pricings?: CardPricing[]
}

export interface CardPricing {
  id: number
  key: string
  label: string
  amount: number
}

export interface CardListResponse {
  items: Card[]
  pagination: PaginationMeta
}

export interface SupervisorCardsResponse {
  items: Card[]
}

export interface CardField {
  key: string
  label: string
  amount: number
}

export interface CardFieldsResponse {
  fields: CardField[]
}
// Schedule types
export interface ScheduleSlot {
  weekday: number
  hour: number
  capacity: number
}

export interface ScheduleResponse {
  supervisor_id: number
  card_id: number
  matrix: ScheduleSlot[]
}

export interface AvailabilitySlot {
  weekday: number
  hour: number
  capacity: number
  reserved: number
  available: number
  is_open: boolean
}

export interface AvailabilityResponse {
  card_id: number
  supervisor_id: number
  slots: AvailabilitySlot[]
}

// Reservation types
export interface Reservation {
  id: number
  customer_id: number
  customer_name: string
  customer_email: string
  card_id: number
  card_title: string
  supervisor_id: number
  supervisor_name: string
  weekday: number
  hour: number
  created_at: string
  time_display: string
}

export interface ReservationListResponse {
  items: Reservation[]
  pagination: PaginationMeta
}

export interface ReservationFilters {
  card_id?: number
  supervisor_id?: number
  customer_id?: number
  page?: number
  per_page?: number
}

// Upsell types
export interface UpsellInitRequest {
  card_id: number
  field_key: string
}

export interface UpsellResponse {
  order_id: number
  pay_link: string
  amount: number
  field_title: string
  sms_result: any
}

// SMS types
export interface SMSRequest {
  to: string
  bodyId: string
  text: string[]
  customer_id?: number
}

export interface SMSResponse {
  message: string
  result: any
}

export type SMSStatus = 'sent' | 'failed'

export interface SMSLog {
  id: number
  customer_id: number | null
  customer_name: string | null
  phone: string
  bodyId: string | null
  message: string
  result_code: string | null
  result_message: string | null
  rec_id: string | null
  sent_by: number | null
  sent_by_name: string | null
  status: SMSStatus
  error_message: string | null
  created_at: string
}

export interface SMSLogListResponse {
  items: SMSLog[]
  pagination: PaginationMeta
}

export interface SMSFilters {
  search?: string
  status?: SMSStatus
  customer_id?: number
  phone?: string
  page?: number
  per_page?: number
}

export interface SMSStats {
  total_sent: number
  successful: number
  failed: number
  success_rate: number
  today_sent?: number
  this_week_sent?: number
}

// Form types
export interface FormSubmissionMeta {
  card_id: number
  user_id: number
  supervisor_id?: number
  agent_id?: number
  code?: string | null
  date?: string | null
  time?: string | null
  surprise?: string | null
  meta: Record<string, unknown>
}

export interface FormSubmission {
  id: number
  title: string
  created_at: string
  meta: FormSubmissionMeta
}

export interface FormListResponse {
  items: FormSubmission[]
  pagination: PaginationMeta
}

export interface FormFilters {
  card_id?: number
  customer_id?: number
  supervisor_id?: number
  agent_id?: number
  page?: number
  per_page?: number
}

// API Response types
export interface ApiResponse<T = any> {
  success: boolean
  data: T
  error: ApiErrorPayload | null
}

export interface ApiErrorPayload {
  code?: string
  message?: string
  status?: number
}

export interface ApiError {
  success: false
  data: null
  error: {
    code: string
    message: string
    status: number
  }
}

export interface PaginatedResponse<T> {
  data: T[]
  total: number
  page: number
  per_page: number
  total_pages: number
}

export interface PaginationMeta {
  page: number
  per_page: number
  total: number
  total_pages: number
}

// Auth types
export interface LoginRequest {
  username: string
  password: string
  role?: UserRole
}

export interface RegisterRequest {
  username: string
  email: string
  password: string
  display_name: string
  role: UserRole
  supervisor_id?: number
  cards?: number[]
}

export interface AuthResponse {
  token: string
  user: User
  role: UserRole
}

export interface CustomerFilters {
  status?: CustomerStatus
  card_id?: number
  supervisor_id?: number
  agent_id?: number
  search?: string
  page?: number
  per_page?: number
}


export interface AgentFilters {
  search?: string
  supervisor_id?: number
  page?: number
  per_page?: number
}

export interface PaginationParams {
  page: number
  per_page: number
}

// UI State types
export interface AppState {
  user: User | null
  token: string | null
  isAuthenticated: boolean
  isLoading: boolean
  theme: 'light' | 'dark'
  sidebarOpen: boolean
}

// Form types
export interface StatusChangeForm {
  status: CustomerStatus
  reason?: string
}

export interface NoteForm {
  note: string
}

export interface UpsellForm {
  card_id: number
  field_key: string
}

export interface AssignmentForm {
  supervisor_id?: number
  agent_id?: number
}

// Statistics types
export interface DashboardCounts {
  supervisors: number
  agents: number
  customers: number
  reservations_today: number
}

export interface SmsStatistics {
  total_sent: number
  successful: number
  failed: number
  success_rate: number
}

export interface UpsellStatistics {
  total_orders: number
  completed_orders: number
  failed_orders: number
  total_revenue: number
}

export interface LogStatistics {
  total_logs: number
  status_changes: Record<string, number>
}

export interface ActivityLog {
  id?: number
  level: string
  message: string
  context?: string | null
  user_id?: number | null
  ip?: string | null
  created_at: string
}

export interface DashboardSummary {
  counts: DashboardCounts
  status_counts: Record<string, number>
  sms: SmsStatistics | null
  upsell: UpsellStatistics | null
  logs: LogStatistics | null
  recent_activity: ActivityLog[]
}

// Tab types
export interface CustomerTab {
  key: string
  label: string
  status?: CustomerStatus
  count?: number
}

// Filter types
export interface SupervisorFilters {
  search?: string
  page?: number
  per_page?: number
}
