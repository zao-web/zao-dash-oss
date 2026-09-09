import { ref, onMounted, onUnmounted } from 'vue';
import echo from '@/echo';

interface CreativeGenerationStarted {
    campaign_id: number;
    campaign_name: string;
    total_creatives: number;
    status: string;
    message: string;
    timestamp: string;
}

interface CreativeGenerationProgress {
    campaign_id: number;
    step: string;
    message: string;
    current: number;
    total: number;
    progress_percentage: number;
    metadata: Record<string, any> | null;
    timestamp: string;
}

interface CreativeGenerationCompleted {
    campaign_id: number;
    campaign_name: string;
    creatives_generated: number;
    success: boolean;
    error_message: string | null;
    status: string;
    message: string;
    metadata: Record<string, any> | null;
    timestamp: string;
}

/**
 * Listen for real-time Meta Ads campaign creative generation updates
 */
export function useMetaAdsRealtime(campaignId: number) {
    const isGenerating = ref(false);
    const isComplete = ref(false);
    const currentProgress = ref<CreativeGenerationProgress | null>(null);
    const completionData = ref<CreativeGenerationCompleted | null>(null);
    const progressHistory = ref<CreativeGenerationProgress[]>([]);
    const isConnected = ref(false);

    let campaignChannel: ReturnType<typeof echo.private> | null = null;

    const connect = () => {
        if (!echo) {
            console.warn('Echo not initialized');
            return;
        }

        // Listen to private campaign channel
        campaignChannel = echo.private(`meta-ads.campaign.${campaignId}`)
            .listen('.creative.generation.started', (data: CreativeGenerationStarted) => {
                console.log('Creative generation started:', data);
                isGenerating.value = true;
                isComplete.value = false;
                progressHistory.value = [];
                currentProgress.value = null;
                completionData.value = null;
            })
            .listen('.creative.generation.progress', (data: CreativeGenerationProgress) => {
                console.log('Creative generation progress:', data);
                currentProgress.value = data;
                progressHistory.value.push(data);
            })
            .listen('.creative.generation.completed', (data: CreativeGenerationCompleted) => {
                console.log('Creative generation completed:', data);
                isGenerating.value = false;
                isComplete.value = true;
                completionData.value = data;
            });

        isConnected.value = true;
    };

    const disconnect = () => {
        if (campaignChannel && campaignId) {
            echo.leave(`meta-ads.campaign.${campaignId}`);
            campaignChannel = null;
        }

        isConnected.value = false;
    };

    onMounted(() => {
        connect();
    });

    onUnmounted(() => {
        disconnect();
    });

    return {
        isGenerating,
        isComplete,
        currentProgress,
        completionData,
        progressHistory,
        isConnected,
        connect,
        disconnect,
    };
}
