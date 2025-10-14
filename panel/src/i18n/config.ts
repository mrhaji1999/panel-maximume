import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'

import faCommon from '@/locales/fa/common.json'
import enCommon from '@/locales/en/common.json'

const resources = {
  fa: {
    translation: faCommon,
  },
  en: {
    translation: enCommon,
  },
}

i18n
  .use(initReactI18next)
  .init({
    resources,
    lng: 'fa',
    fallbackLng: 'en',
    interpolation: {
      escapeValue: false,
    },
  })
  .catch((error) => {
    console.error('i18n initialization failed', error)
  })

export default i18n
