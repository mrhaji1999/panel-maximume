import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useAuthActions } from '@/store/authStore'
import { useNotification } from '@/store/uiStore'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Eye, EyeOff, Loader2 } from 'lucide-react'

const registerSchema = z.object({
  username: z.string().min(3, 'نام کاربری باید حداقل 3 کاراکتر باشد'),
  email: z.string().email('ایمیل معتبر نیست'),
  password: z.string().min(6, 'رمز عبور باید حداقل 6 کاراکتر باشد'),
  confirmPassword: z.string(),
  display_name: z.string().min(2, 'نام نمایشی الزامی است'),
  role: z.enum(['company_manager', 'supervisor', 'agent']),
  supervisor_id: z.number().optional(),
}).refine((data) => data.password === data.confirmPassword, {
  message: "رمز عبور و تکرار آن مطابقت ندارند",
  path: ["confirmPassword"],
})

type RegisterForm = z.infer<typeof registerSchema>

export function RegisterPage() {
  const navigate = useNavigate()
  const { register: registerUser } = useAuthActions()
  const { error: notifyError, success: notifySuccess } = useNotification()
  const [showPassword, setShowPassword] = useState(false)
  const [showConfirmPassword, setShowConfirmPassword] = useState(false)
  const [isLoading, setIsLoading] = useState(false)

  const {
    register,
    handleSubmit,
    watch,
    formState: { errors },
  } = useForm<RegisterForm>({
    resolver: zodResolver(registerSchema),
  })

  const selectedRole = watch('role')

  const onSubmit = async (data: RegisterForm) => {
    setIsLoading(true)
    try {
      const { confirmPassword, ...userData } = data
      await registerUser(userData)
      notifySuccess('ثبت‌نام موفق', 'حساب کاربری شما با موفقیت ایجاد شد')
      navigate('/dashboard')
    } catch (error: any) {
      notifyError('خطا در ثبت‌نام', error?.message || 'خطای نامشخص')
    } finally {
      setIsLoading(false)
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
      <div className="max-w-md w-full space-y-8">
        <div className="text-center">
          <h2 className="mt-6 text-3xl font-extrabold text-gray-900">
            ثبت‌نام در پنل مدیریت
          </h2>
          <p className="mt-2 text-sm text-gray-600">
            حساب کاربری جدید ایجاد کنید
          </p>
        </div>

        <Card>
          <CardHeader>
            <CardTitle>ثبت‌نام</CardTitle>
            <CardDescription>
              اطلاعات خود را برای ایجاد حساب کاربری وارد کنید
            </CardDescription>
          </CardHeader>
          <CardContent>
            <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
              <div>
                <label htmlFor="username" className="block text-sm font-medium text-gray-700 mb-1">
                  نام کاربری
                </label>
                <Input
                  id="username"
                  type="text"
                  {...register('username')}
                  className={errors.username ? 'border-red-500' : ''}
                  placeholder="نام کاربری خود را وارد کنید"
                />
                {errors.username && (
                  <p className="mt-1 text-sm text-red-600">{errors.username.message}</p>
                )}
              </div>

              <div>
                <label htmlFor="email" className="block text-sm font-medium text-gray-700 mb-1">
                  ایمیل
                </label>
                <Input
                  id="email"
                  type="email"
                  {...register('email')}
                  className={errors.email ? 'border-red-500' : ''}
                  placeholder="ایمیل خود را وارد کنید"
                />
                {errors.email && (
                  <p className="mt-1 text-sm text-red-600">{errors.email.message}</p>
                )}
              </div>

              <div>
                <label htmlFor="display_name" className="block text-sm font-medium text-gray-700 mb-1">
                  نام نمایشی
                </label>
                <Input
                  id="display_name"
                  type="text"
                  {...register('display_name')}
                  className={errors.display_name ? 'border-red-500' : ''}
                  placeholder="نام کامل خود را وارد کنید"
                />
                {errors.display_name && (
                  <p className="mt-1 text-sm text-red-600">{errors.display_name.message}</p>
                )}
              </div>

              <div>
                <label htmlFor="role" className="block text-sm font-medium text-gray-700 mb-1">
                  نقش
                </label>
                <select
                  id="role"
                  {...register('role')}
                  className="w-full h-10 px-3 py-2 border border-input bg-background rounded-md text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                >
                  <option value="">انتخاب نقش</option>
                  <option value="company_manager">مدیر شرکت</option>
                  <option value="supervisor">سرپرست</option>
                  <option value="agent">کارشناس</option>
                </select>
                {errors.role && (
                  <p className="mt-1 text-sm text-red-600">{errors.role.message}</p>
                )}
              </div>

              {selectedRole === 'agent' && (
                <div>
                  <label htmlFor="supervisor_id" className="block text-sm font-medium text-gray-700 mb-1">
                    سرپرست
                  </label>
                  <Input
                    id="supervisor_id"
                    type="number"
                    {...register('supervisor_id', { valueAsNumber: true })}
                    className={errors.supervisor_id ? 'border-red-500' : ''}
                    placeholder="شناسه سرپرست را وارد کنید"
                  />
                  {errors.supervisor_id && (
                    <p className="mt-1 text-sm text-red-600">{errors.supervisor_id.message}</p>
                  )}
                </div>
              )}

              <div>
                <label htmlFor="password" className="block text-sm font-medium text-gray-700 mb-1">
                  رمز عبور
                </label>
                <div className="relative">
                  <Input
                    id="password"
                    type={showPassword ? 'text' : 'password'}
                    {...register('password')}
                    className={errors.password ? 'border-red-500 pr-10' : 'pr-10'}
                    placeholder="رمز عبور خود را وارد کنید"
                  />
                  <button
                    type="button"
                    className="absolute inset-y-0 right-0 pr-3 flex items-center"
                    onClick={() => setShowPassword(!showPassword)}
                  >
                    {showPassword ? (
                      <EyeOff className="h-4 w-4 text-gray-400" />
                    ) : (
                      <Eye className="h-4 w-4 text-gray-400" />
                    )}
                  </button>
                </div>
                {errors.password && (
                  <p className="mt-1 text-sm text-red-600">{errors.password.message}</p>
                )}
              </div>

              <div>
                <label htmlFor="confirmPassword" className="block text-sm font-medium text-gray-700 mb-1">
                  تکرار رمز عبور
                </label>
                <div className="relative">
                  <Input
                    id="confirmPassword"
                    type={showConfirmPassword ? 'text' : 'password'}
                    {...register('confirmPassword')}
                    className={errors.confirmPassword ? 'border-red-500 pr-10' : 'pr-10'}
                    placeholder="رمز عبور را مجدداً وارد کنید"
                  />
                  <button
                    type="button"
                    className="absolute inset-y-0 right-0 pr-3 flex items-center"
                    onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                  >
                    {showConfirmPassword ? (
                      <EyeOff className="h-4 w-4 text-gray-400" />
                    ) : (
                      <Eye className="h-4 w-4 text-gray-400" />
                    )}
                  </button>
                </div>
                {errors.confirmPassword && (
                  <p className="mt-1 text-sm text-red-600">{errors.confirmPassword.message}</p>
                )}
              </div>

              <Button
                type="submit"
                className="w-full"
                disabled={isLoading}
              >
                {isLoading ? (
                  <>
                    <Loader2 className="ml-2 h-4 w-4 animate-spin" />
                    در حال ثبت‌نام...
                  </>
                ) : (
                  'ثبت‌نام'
                )}
              </Button>
            </form>

            <div className="mt-6 text-center">
              <p className="text-sm text-gray-600">
                قبلاً حساب کاربری دارید؟{' '}
                <Link
                  to="/login"
                  className="font-medium text-primary hover:text-primary/80"
                >
                  وارد شوید
                </Link>
              </p>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
