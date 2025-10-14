const SPEED_STORAGE_KEY = 'ucb-media-playback-rate'
const MIN_RATE = 0.25
const MAX_RATE = 4
const DEFAULT_STEP = 0.25

const getMediaElement = (): HTMLMediaElement | null => {
  if (typeof document === 'undefined') {
    return null
  }

  const preferred = document.querySelector<HTMLMediaElement>(
    '[data-playback-target], video[data-playback-target], audio[data-playback-target]'
  )

  if (preferred) {
    return preferred
  }

  return document.querySelector<HTMLMediaElement>('video, audio')
}

const clampRate = (rate: number): number => {
  if (Number.isNaN(rate)) {
    return 1
  }

  return Math.min(MAX_RATE, Math.max(MIN_RATE, rate))
}

const resolveStep = (media: HTMLMediaElement | null): number => {
  if (!media) {
    return DEFAULT_STEP
  }

  const stepAttribute = media.dataset?.playbackStep

  if (!stepAttribute) {
    return DEFAULT_STEP
  }

  const parsed = Number.parseFloat(stepAttribute)

  if (Number.isNaN(parsed) || parsed <= 0) {
    return DEFAULT_STEP
  }

  return Math.min(parsed, MAX_RATE)
}

const persistRate = (rate: number) => {
  try {
    window.localStorage.setItem(SPEED_STORAGE_KEY, rate.toString())
  } catch (error) {
    console.warn('Unable to persist playback rate preference.', error)
  }
}

const applyPlaybackRate = (rate: number) => {
  const media = getMediaElement()

  if (!media) {
    return
  }

  const nextRate = clampRate(rate)
  media.playbackRate = nextRate
  persistRate(nextRate)
}

const restorePlaybackRate = () => {
  if (typeof window === 'undefined') {
    return
  }

  try {
    const storedValue = window.localStorage.getItem(SPEED_STORAGE_KEY)

    if (!storedValue) {
      return
    }

    const parsed = Number.parseFloat(storedValue)

    if (!Number.isNaN(parsed)) {
      applyPlaybackRate(parsed)
    }
  } catch (error) {
    console.warn('Unable to restore playback rate preference.', error)
  }
}

const expose = (name: string, handler: () => void) => {
  if (typeof window === 'undefined') {
    return
  }

  // Assign to the global scope so legacy inline handlers keep working.
  Object.defineProperty(window, name, {
    value: handler,
    writable: true,
    configurable: true,
  })
}

expose('decSpeed', () => {
  const media = getMediaElement()

  if (!media) {
    return
  }

  const step = resolveStep(media)
  applyPlaybackRate(media.playbackRate - step)
})

expose('incSpeed', () => {
  const media = getMediaElement()

  if (!media) {
    return
  }

  const step = resolveStep(media)
  applyPlaybackRate(media.playbackRate + step)
})

expose('normalSpeed', () => {
  applyPlaybackRate(1)
})

expose('threeXSpeed', () => {
  applyPlaybackRate(3)
})

if (typeof document !== 'undefined') {
  document.addEventListener('DOMContentLoaded', () => {
    const media = getMediaElement()

    if (!media) {
      return
    }

    const hydrate = () => restorePlaybackRate()

    if (media.readyState >= 1) {
      hydrate()
    } else {
      media.addEventListener('loadedmetadata', hydrate, { once: true })
    }
  })
}
