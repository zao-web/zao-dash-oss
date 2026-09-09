import { ref, onUnmounted } from 'vue'
import echo from '@/echo'
import { useToast } from './useToast'

interface SyncProgressEvent {
    step: string
    message: string
    completed: number
    total: number
    progress: number
    account_name: string | null
}

/**
 * Real-time account sync progress composable.
 * Listens for sync progress events and shows toast notifications.
 */
export function useAccountSync(userId: number) {
    const syncing = ref(false)
    const progress = ref(0)
    const currentStep = ref('')
    const toast = useToast()

    let toastId: string | null = null

    const channel = echo.private(`user.${userId}`)

    channel.listen('.account.sync.progress', (event: SyncProgressEvent) => {
        currentStep.value = event.step
        progress.value = event.progress

        if (event.step === 'starting') {
            syncing.value = true
            toast.info('Account Sync', event.message, 3000)
        } else if (event.step === 'syncing_account') {
            toast.info('Syncing', event.message, 2500)
        } else if (event.step === 'categorizing') {
            toast.info('Categorizing', event.message, 2500)
        } else if (event.step === 'complete') {
            syncing.value = false
            progress.value = 100
            toast.success('Sync Complete', event.message, 5000)
        } else {
            toast.info('Syncing', event.message, 2500)
        }
    })

    onUnmounted(() => {
        // Don't leave the channel — other composables use user.{userId} too
        channel.stopListening('.account.sync.progress')
    })

    return {
        syncing,
        progress,
        currentStep,
    }
}
