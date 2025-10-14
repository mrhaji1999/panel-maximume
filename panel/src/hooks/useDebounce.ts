import { useEffect, useState } from 'react'

/**
 * Returns a debounced copy of the provided value.
 * Useful for delaying API requests while a user is typing.
 */
export function useDebounce<T>(value: T, delay = 400): T {
  const [debouncedValue, setDebouncedValue] = useState(value)

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedValue(value), delay)

    return () => clearTimeout(timer)
  }, [value, delay])

  return debouncedValue
}
