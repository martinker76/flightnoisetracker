import { useState, useEffect, useCallback } from 'react';

const STORAGE_KEY = 'flightnoisetracker-dark-mode';

export function useDarkMode(): [boolean, () => void] {
  const [dark, setDark] = useState<boolean>(() => {
    const stored = localStorage.getItem(STORAGE_KEY);
    if (stored !== null) return stored === 'true';
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
  });

  useEffect(() => {
    const root = document.documentElement;
    if (dark) {
      root.classList.add('dark');
    } else {
      root.classList.remove('dark');
    }
    localStorage.setItem(STORAGE_KEY, String(dark));
  }, [dark]);

  const toggle = useCallback(() => setDark((prev) => !prev), []);

  return [dark, toggle];
}
