import { usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

interface PageProps {
    auth: {
        user: {
            ui_preferences: Record<string, any>
        } | null
    }
}

/**
 * Get nested value using dot notation (e.g., 'tasks.viewMode')
 */
function getNestedValue(obj: Record<string, any>, path: string): any {
    return path.split('.').reduce((current, key) => current?.[key], obj)
}

/**
 * Get a UI preference value
 */
export function getPreference<T>(key: string, defaultValue: T): T {
    const page = usePage<PageProps>()
    const preferences = page.props.auth?.user?.ui_preferences ?? {}
    const value = getNestedValue(preferences, key)
    return (value as T) ?? defaultValue
}

/**
 * Save a UI preference value
 */
export async function savePreference(key: string, value: any): Promise<void> {
    try {
        await fetch('/user/preferences', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify({ key, value }),
        })
    } catch (e) {
        console.error('Failed to save preference:', e)
    }
}

/**
 * Set nested value using dot notation
 */
function setNestedValue(obj: Record<string, any>, path: string, value: any): void {
    const keys = path.split('.')
    const lastKey = keys.pop()!
    const target = keys.reduce((current, key) => {
        if (!current[key]) current[key] = {}
        return current[key]
    }, obj)
    target[lastKey] = value
}

/**
 * Composable for managing a specific preference with auto-save
 */
export function usePreference<T>(key: string, defaultValue: T) {
    const page = usePage<PageProps>()

    const value = computed(() => {
        const preferences = page.props.auth?.user?.ui_preferences ?? {}
        const val = getNestedValue(preferences, key)
        return (val as T) ?? defaultValue
    })

    const setValue = async (newValue: T) => {
        // Update local state optimistically via page props mutation
        if (page.props.auth?.user) {
            if (!page.props.auth.user.ui_preferences) {
                page.props.auth.user.ui_preferences = {}
            }
            setNestedValue(page.props.auth.user.ui_preferences, key, newValue)
        }
        await savePreference(key, newValue)
    }

    return { value, setValue }
}
