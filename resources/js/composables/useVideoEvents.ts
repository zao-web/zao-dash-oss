import { ref, onMounted, onUnmounted } from 'vue';
import echo from '@/echo';
import { useToast } from './useToast';

interface VideoProcessedEvent {
    video_id: number;
    uuid: string;
    title: string;
    status: string;
    duration: number;
    formatted_duration: string;
    thumbnail_url: string | null;
    share_url: string;
    task_id: number | null;
    project_id: number | null;
}

interface VideoViewedEvent {
    video_id: number;
    video_title: string;
    video_uuid: string;
    share_url: string;
    view: {
        id: number;
        viewer_email: string | null;
        viewer_ip: string;
        device_type: string;
        referrer: string | null;
        country: string | null;
        started_at: string;
    };
    total_views: number;
    unique_views: number;
}

interface TranscriptionCompletedEvent {
    video_id: number;
    video_uuid: string;
    video_title: string;
    transcript_preview: string;
    language: string;
    share_url: string;
}

interface ActionItemsExtractedEvent {
    video_id: number;
    video_uuid: string;
    video_title: string;
    action_items: Array<{
        task: string;
        owner: string | null;
        deadline: string | null;
        priority: string;
    }>;
    action_items_count: number;
}

interface DraftTasksCreatedEvent {
    video_id: number;
    video_uuid: string;
    video_title: string;
    tasks: Array<{
        id: number;
        name: string;
        priority: string;
        status: string;
    }>;
    tasks_count: number;
    requires_review: boolean;
}

interface VideoCommentAddedEvent {
    video_id: number;
    video_uuid: string;
    video_title: string;
    comment: {
        id: number;
        content: string;
        timestamp_seconds: number | null;
        timestamp_formatted: string | null;
        commenter_name: string;
        is_authenticated: boolean;
        is_approved: boolean;
        type: string;
    };
    requires_moderation: boolean;
}

/**
 * Real-time video events composable.
 * Listens for video processing, transcription, AI analysis, and comment events.
 */
export function useVideoEvents(userId: number) {
    const isConnected = ref(false);
    const toast = useToast();

    let channel: ReturnType<typeof echo.private> | null = null;

    const handleVideoProcessed = (event: VideoProcessedEvent) => {
        toast.show({
            title: 'Video Ready',
            message: `"${event.title}" has finished processing`,
            type: 'success',
            duration: 6000,
            action: {
                label: 'View',
                onClick: () => {
                    window.location.href = event.share_url;
                },
            },
        });
    };

    const handleVideoViewed = (event: VideoViewedEvent) => {
        const viewerName = event.view.viewer_email || 'Someone';
        const location = event.view.country ? ` from ${event.view.country}` : '';

        toast.show({
            title: 'New Video View',
            message: `${viewerName} is watching "${event.video_title}"${location}`,
            type: 'info',
            duration: 5000,
            action: {
                label: 'Analytics',
                onClick: () => {
                    window.location.href = `/videos/${event.video_uuid}/analytics`;
                },
            },
        });
    };

    const handleTranscriptionCompleted = (event: TranscriptionCompletedEvent) => {
        toast.show({
            title: 'Transcription Complete',
            message: `"${event.video_title}" transcript is ready (${event.language})`,
            type: 'success',
            duration: 6000,
            action: {
                label: 'View Transcript',
                onClick: () => {
                    window.location.href = event.share_url;
                },
            },
        });
    };

    const handleActionItemsExtracted = (event: ActionItemsExtractedEvent) => {
        const count = event.action_items_count;
        toast.show({
            title: 'Action Items Found',
            message: `${count} action item${count !== 1 ? 's' : ''} extracted from "${event.video_title}"`,
            type: 'info',
            duration: 6000,
            action: {
                label: 'View',
                onClick: () => {
                    window.location.href = `/videos?uuid=${event.video_uuid}`;
                },
            },
        });
    };

    const handleDraftTasksCreated = (event: DraftTasksCreatedEvent) => {
        const count = event.tasks_count;
        toast.show({
            title: 'Draft Tasks Created',
            message: `${count} task${count !== 1 ? 's' : ''} created from "${event.video_title}" - review required`,
            type: 'warning',
            duration: 8000,
            action: {
                label: 'Review Tasks',
                onClick: () => {
                    window.location.href = '/tasks?status=pending';
                },
            },
        });
    };

    const handleVideoCommentAdded = (event: VideoCommentAddedEvent) => {
        const { comment, video_title, requires_moderation } = event;
        const timestampText = comment.timestamp_formatted ? ` at ${comment.timestamp_formatted}` : '';

        if (requires_moderation) {
            toast.show({
                title: 'New Comment (Pending)',
                message: `${comment.commenter_name} commented on "${video_title}"${timestampText} - requires approval`,
                type: 'warning',
                duration: 6000,
                action: {
                    label: 'Moderate',
                    onClick: () => {
                        window.location.href = `/videos?uuid=${event.video_uuid}&tab=comments`;
                    },
                },
            });
        } else {
            toast.show({
                title: 'New Comment',
                message: `${comment.commenter_name} commented on "${video_title}"${timestampText}`,
                type: 'info',
                duration: 5000,
                action: {
                    label: 'View',
                    onClick: () => {
                        window.location.href = `/videos?uuid=${event.video_uuid}&tab=comments`;
                    },
                },
            });
        }
    };

    const connect = () => {
        if (!echo || !userId) {
            console.warn('Echo not initialized or no user ID');
            return;
        }

        channel = echo.private(`user.${userId}`)
            .listen('.video.processing.completed', handleVideoProcessed)
            .listen('.video.viewed', handleVideoViewed)
            .listen('.transcription.completed', handleTranscriptionCompleted)
            .listen('.action-items.extracted', handleActionItemsExtracted)
            .listen('.draft-tasks.created', handleDraftTasksCreated)
            .listen('.video-comment.added', handleVideoCommentAdded);

        isConnected.value = true;
    };

    const disconnect = () => {
        if (channel && userId) {
            echo.leave(`user.${userId}`);
            channel = null;
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
        isConnected,
        connect,
        disconnect,
    };
}
