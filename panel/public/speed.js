;(function () {
  'use strict'

  const MIN_RATE = 0.25
  const MAX_RATE = 5
  const STEP = 0.25
  const DEFAULT_RATE = 1

  const getMediaElement = () => {
    const targetSelector = '[data-speed-target]' // optional hook for explicit targets
    return (
      document.querySelector(targetSelector) ||
      document.querySelector('video, audio') ||
      null
    )
  }

  const clamp = (value, min, max) => Math.min(Math.max(value, min), max)

  const setPlaybackRate = (rate) => {
    const media = getMediaElement()
    if (!media) {
      return null
    }

    const boundedRate = clamp(rate, MIN_RATE, MAX_RATE)
    media.playbackRate = boundedRate
    return boundedRate
  }

  const stepPlaybackRate = (direction) => {
    const media = getMediaElement()
    if (!media) {
      return null
    }

    const nextRate = clamp(
      (Number.isFinite(media.playbackRate) ? media.playbackRate : DEFAULT_RATE) +
        direction * STEP,
      MIN_RATE,
      MAX_RATE,
    )

    media.playbackRate = nextRate
    return nextRate
  }

  const expose = (name, handler) => {
    Object.defineProperty(window, name, {
      configurable: true,
      enumerable: true,
      writable: true,
      value: handler,
    })
  }

  expose('decSpeed', () => stepPlaybackRate(-1))
  expose('incSpeed', () => stepPlaybackRate(1))
  expose('normalSpeed', () => setPlaybackRate(DEFAULT_RATE))
  expose('threeXSpeed', () => setPlaybackRate(3))

  document.addEventListener('DOMContentLoaded', () => {
    const media = getMediaElement()
    if (!media) {
      return
    }

    const initialRate = Number.parseFloat(media.dataset.initialRate || '')
    if (Number.isFinite(initialRate)) {
      setPlaybackRate(initialRate)
    } else {
      setPlaybackRate(DEFAULT_RATE)
    }
  })
})()
