interface ToastOptions {
    title: string;
    message: string;
    type?: 'info' | 'success' | 'warning' | 'error';
    duration?: number;
    action?: {
        label: string;
        onClick: () => void;
    };
}

export function useToast() {
    const show = (options: ToastOptions) => {
        const event = new CustomEvent('show-toast', {
            detail: {
                ...options,
                type: options.type ?? 'info',
            },
        });
        window.dispatchEvent(event);
    };

    const success = (title: string, message: string, duration?: number) => {
        show({ title, message, type: 'success', duration });
    };

    const error = (title: string, message: string, duration?: number) => {
        show({ title, message, type: 'error', duration: duration ?? 8000 });
    };

    const warning = (title: string, message: string, duration?: number) => {
        show({ title, message, type: 'warning', duration });
    };

    const info = (title: string, message: string, duration?: number) => {
        show({ title, message, type: 'info', duration });
    };

    return {
        show,
        success,
        error,
        warning,
        info,
    };
}
