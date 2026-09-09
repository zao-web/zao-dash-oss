import { ref } from 'vue';

/**
 * Global command palette state and keyboard listener.
 *
 * Handles Cmd/Ctrl+K to open the palette globally.
 */
const isOpen = ref(false);
let listenerInitialized = false;

const openPalette = () => {
    isOpen.value = true;
};

const closePalette = () => {
    isOpen.value = false;
};

const togglePalette = () => {
    isOpen.value = !isOpen.value;
};

const handleKeyDown = (e: KeyboardEvent) => {
    // Cmd/Ctrl + K to toggle
    if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault();
        togglePalette();
        return;
    }

    // Escape to close (only if open)
    if (e.key === 'Escape' && isOpen.value) {
        e.preventDefault();
        closePalette();
        return;
    }
};

// Initialize listener once globally
const initListener = () => {
    if (listenerInitialized) return;
    document.addEventListener('keydown', handleKeyDown);
    listenerInitialized = true;
};

// Auto-initialize when module loads (client-side only)
if (typeof document !== 'undefined') {
    // Use setTimeout to ensure DOM is ready
    setTimeout(initListener, 0);
}

export function useGlobalCommandPalette() {
    // Ensure listener is initialized
    initListener();

    return {
        isOpen,
        openPalette,
        closePalette,
        togglePalette,
    };
}
