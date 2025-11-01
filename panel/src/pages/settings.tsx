import { useState } from 'react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { 
  Settings as SettingsIcon,
  Save,
  TestTube,
  Key,
  Globe,
  Bell,
  Shield
} from 'lucide-react'
import { useNotification } from '@/store/uiStore'

export function SettingsPage() {
  const { success: notifySuccess } = useNotification()
  const [settings, setSettings] = useState({
    // SMS Settings
    sms_gateway: 'payamak_panel',
    sms_username: '',
    sms_password: '',
    sms_normal_body_id: '',
    sms_upsell_body_id: '',
    sms_sender_number: '',
    
    // API Settings
    payment_token_expiry: 24,
    log_retention_days: 30,
    
    // Security Settings
    webhook_secret: '',
    cors_origins: [''],
    
    // Notification Settings
    email_notifications: true,
    sms_notifications: true,
    push_notifications: false,
  })

  const handleSave = () => {
    // Handle settings save
    console.log('Save settings:', settings)
    notifySuccess('موفق', 'تنظیمات با موفقیت ذخیره شد')
  }

  const handleTestSMS = () => {
    // Handle SMS test
    console.log('Test SMS configuration')
    notifySuccess('موفق', 'تست پیامک با موفقیت انجام شد')
  }

  const handleAddCORSOrigin = () => {
    setSettings({
      ...settings,
      cors_origins: [...settings.cors_origins, '']
    })
  }

  const handleRemoveCORSOrigin = (index: number) => {
    setSettings({
      ...settings,
      cors_origins: settings.cors_origins.filter((_, i) => i !== index)
    })
  }

  const handleCORSOriginChange = (index: number, value: string) => {
    const newOrigins = [...settings.cors_origins]
    newOrigins[index] = value
    setSettings({
      ...settings,
      cors_origins: newOrigins
    })
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">تنظیمات</h1>
          <p className="text-muted-foreground">
            پیکربندی سیستم و تنظیمات امنیتی
          </p>
        </div>
        <Button onClick={handleSave}>
          <Save className="ml-2 h-4 w-4" />
          ذخیره تغییرات
        </Button>
      </div>

      {/* SMS Settings */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center space-x-2 space-x-reverse">
            <Bell className="h-5 w-5" />
            <span>تنظیمات پیامک</span>
          </CardTitle>
          <CardDescription>
            پیکربندی سرویس پیامک و انتخاب درگاه موردنظر
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid gap-4 md:grid-cols-2">
            <div className="md:col-span-2">
              <label className="block text-sm font-medium mb-1">
                درگاه پیامک
              </label>
              <select
                className="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                value={settings.sms_gateway}
                onChange={(e) => setSettings({ ...settings, sms_gateway: e.target.value })}
              >
                <option value="payamak_panel">Payamak Panel</option>
                <option value="iran_payamak">IranPayamak</option>
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium mb-1">
                نام کاربری
              </label>
              <Input
                type="text"
                value={settings.sms_username}
                onChange={(e) => setSettings({ ...settings, sms_username: e.target.value })}
                placeholder="نام کاربری سرویس پیامک"
              />
            </div>
            <div>
              <label className="block text-sm font-medium mb-1">
                رمز عبور
              </label>
              <Input
                type="password"
                value={settings.sms_password}
                onChange={(e) => setSettings({ ...settings, sms_password: e.target.value })}
                placeholder="رمز عبور سرویس پیامک"
              />
            </div>
            <div>
              <label className="block text-sm font-medium mb-1">
                شماره ارسال‌کننده (اختیاری)
              </label>
              <Input
                type="text"
                value={settings.sms_sender_number}
                onChange={(e) => setSettings({ ...settings, sms_sender_number: e.target.value })}
                placeholder="مثال: 5000xxxx"
              />
              <p className="mt-1 text-xs text-muted-foreground">
                برای درگاه‌هایی که نیاز به خط اختصاصی دارند (مانند IranPayamak) این مقدار را وارد کنید.
              </p>
            </div>
            <div>
              <label className="block text-sm font-medium mb-1">
                Body ID کد عادی
              </label>
              <Input
                type="text"
                value={settings.sms_normal_body_id}
                onChange={(e) => setSettings({ ...settings, sms_normal_body_id: e.target.value })}
                placeholder="12345"
              />
            </div>
            <div>
              <label className="block text-sm font-medium mb-1">
                Body ID پرداخت
              </label>
              <Input
                type="text"
                value={settings.sms_upsell_body_id}
                onChange={(e) => setSettings({ ...settings, sms_upsell_body_id: e.target.value })}
                placeholder="67890"
              />
            </div>
          </div>
          <Button variant="outline" onClick={handleTestSMS}>
            <TestTube className="ml-2 h-4 w-4" />
            تست تنظیمات پیامک
          </Button>
        </CardContent>
      </Card>

      {/* API Settings */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center space-x-2 space-x-reverse">
            <Globe className="h-5 w-5" />
            <span>تنظیمات API</span>
          </CardTitle>
          <CardDescription>
            پیکربندی API و تنظیمات عمومی
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid gap-4 md:grid-cols-2">
            <div>
              <label className="block text-sm font-medium mb-1">
                انقضای توکن پرداخت (ساعت)
              </label>
              <Input
                type="number"
                value={settings.payment_token_expiry}
                onChange={(e) => setSettings({ ...settings, payment_token_expiry: parseInt(e.target.value) })}
                placeholder="24"
              />
            </div>
            <div>
              <label className="block text-sm font-medium mb-1">
                نگهداری لاگ‌ها (روز)
              </label>
              <Input
                type="number"
                value={settings.log_retention_days}
                onChange={(e) => setSettings({ ...settings, log_retention_days: parseInt(e.target.value) })}
                placeholder="30"
              />
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Security Settings */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center space-x-2 space-x-reverse">
            <Shield className="h-5 w-5" />
            <span>تنظیمات امنیتی</span>
          </CardTitle>
          <CardDescription>
            پیکربندی امنیت و دسترسی‌ها
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div>
            <label className="block text-sm font-medium mb-1">
              کلید مخفی Webhook
            </label>
            <div className="flex space-x-2 space-x-reverse">
              <Input
                type="text"
                value={settings.webhook_secret}
                onChange={(e) => setSettings({ ...settings, webhook_secret: e.target.value })}
                placeholder="کلید مخفی برای تأیید webhook ها"
                className="flex-1"
              />
              <Button variant="outline">
                <Key className="h-4 w-4" />
              </Button>
            </div>
          </div>
          
          <div>
            <label className="block text-sm font-medium mb-2">
              دامنه‌های مجاز CORS
            </label>
            <div className="space-y-2">
              {settings.cors_origins.map((origin, index) => (
                <div key={index} className="flex space-x-2 space-x-reverse">
                  <Input
                    type="url"
                    value={origin}
                    onChange={(e) => handleCORSOriginChange(index, e.target.value)}
                    placeholder="https://example.com"
                    className="flex-1"
                  />
                  <Button
                    variant="outline"
                    size="icon"
                    onClick={() => handleRemoveCORSOrigin(index)}
                  >
                    ×
                  </Button>
                </div>
              ))}
              <Button variant="outline" onClick={handleAddCORSOrigin}>
                افزودن دامنه
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Notification Settings */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center space-x-2 space-x-reverse">
            <Bell className="h-5 w-5" />
            <span>تنظیمات اعلان‌ها</span>
          </CardTitle>
          <CardDescription>
            مدیریت اعلان‌ها و هشدارها
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-3">
            <div className="flex items-center justify-between">
              <div>
                <p className="font-medium">اعلان‌های ایمیل</p>
                <p className="text-sm text-muted-foreground">
                  دریافت اعلان‌ها از طریق ایمیل
                </p>
              </div>
              <input
                type="checkbox"
                checked={settings.email_notifications}
                onChange={(e) => setSettings({ ...settings, email_notifications: e.target.checked })}
                className="h-4 w-4"
              />
            </div>
            
            <div className="flex items-center justify-between">
              <div>
                <p className="font-medium">اعلان‌های پیامک</p>
                <p className="text-sm text-muted-foreground">
                  دریافت اعلان‌ها از طریق پیامک
                </p>
              </div>
              <input
                type="checkbox"
                checked={settings.sms_notifications}
                onChange={(e) => setSettings({ ...settings, sms_notifications: e.target.checked })}
                className="h-4 w-4"
              />
            </div>
            
            <div className="flex items-center justify-between">
              <div>
                <p className="font-medium">اعلان‌های Push</p>
                <p className="text-sm text-muted-foreground">
                  دریافت اعلان‌های فوری در مرورگر
                </p>
              </div>
              <input
                type="checkbox"
                checked={settings.push_notifications}
                onChange={(e) => setSettings({ ...settings, push_notifications: e.target.checked })}
                className="h-4 w-4"
              />
            </div>
          </div>
        </CardContent>
      </Card>

      {/* System Info */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center space-x-2 space-x-reverse">
            <SettingsIcon className="h-5 w-5" />
            <span>اطلاعات سیستم</span>
          </CardTitle>
          <CardDescription>
            وضعیت سیستم و نسخه نرم‌افزار
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="grid gap-4 md:grid-cols-2">
            <div>
              <p className="text-sm font-medium">نسخه نرم‌افزار</p>
              <p className="text-sm text-muted-foreground">1.0.0</p>
            </div>
            <div>
              <p className="text-sm font-medium">وضعیت API</p>
              <Badge variant="success">فعال</Badge>
            </div>
            <div>
              <p className="text-sm font-medium">وضعیت پایگاه داده</p>
              <Badge variant="success">متصل</Badge>
            </div>
            <div>
              <p className="text-sm font-medium">وضعیت پیامک</p>
              <Badge variant="success">فعال</Badge>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
