import confetti from 'canvas-confetti'

/**
 * Trigger realistic confetti burst with varied fall rates
 */
export function triggerConfetti(event?: MouseEvent | { x: number; y: number }) {
    const origin = event
        ? { x: event.x / window.innerWidth, y: event.y / window.innerHeight }
        : { x: 0.5, y: 0.5 }

    const colors = ['#8b5cf6', '#3b82f6', '#22c55e', '#f59e0b', '#ec4899']

    // First burst - heavier particles that fall faster
    confetti({
        particleCount: 25,
        spread: 50,
        origin,
        colors,
        gravity: 1.4,
        scalar: 1.1,
        drift: -0.5,
        ticks: 200,
        startVelocity: 30,
    })

    // Second burst - medium particles
    confetti({
        particleCount: 35,
        spread: 70,
        origin,
        colors,
        gravity: 1.0,
        scalar: 0.9,
        drift: 0.3,
        ticks: 250,
        startVelocity: 35,
    })

    // Third burst - lighter particles that float longer
    confetti({
        particleCount: 20,
        spread: 90,
        origin,
        colors,
        gravity: 0.6,
        scalar: 0.7,
        drift: 0.8,
        ticks: 300,
        startVelocity: 25,
        decay: 0.92,
    })
}

/**
 * Apply poof animation to an element, returns a promise that resolves when done
 */
export function triggerPoof(element: HTMLElement): Promise<void> {
    return new Promise((resolve) => {
        element.classList.add('poof-animation')
        element.addEventListener('animationend', () => {
            resolve()
        }, { once: true })
    })
}
