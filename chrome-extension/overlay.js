// Recording overlay control injected into page

let overlayElement = null
let cameraPreview = null
let cameraStream = null
let cameraVideoElement = null
let pipWindow = null
let timerInterval = null
let startTime = null
let isPaused = false
let pausedAt = null
let totalPausedMs = 0
let isPipActive = false

async function createOverlay(showCamera = false, cameraDeviceId = '') {
  if (overlayElement) return

  // Create styles
  const style = document.createElement('style')
  style.textContent = `
    #zao-recorder-overlay {
      position: fixed;
      bottom: 20px;
      left: 50%;
      transform: translateX(-50%);
      z-index: 2147483647;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      user-select: none;
    }
    .zao-overlay-inner {
      display: flex;
      align-items: center;
      gap: 12px;
      background: rgba(0, 0, 0, 0.85);
      backdrop-filter: blur(10px);
      padding: 8px 16px;
      border-radius: 100px;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
    }
    .zao-recording-dot {
      width: 12px;
      height: 12px;
      background: #ef4444;
      border-radius: 50%;
      animation: zao-pulse 1.5s ease-in-out infinite;
    }
    .zao-recording-dot.paused {
      animation: none;
      background: #f59e0b;
    }
    @keyframes zao-pulse {
      0%, 100% { opacity: 1; transform: scale(1); }
      50% { opacity: 0.5; transform: scale(0.9); }
    }
    .zao-timer {
      color: white;
      font-size: 14px;
      font-weight: 600;
      font-variant-numeric: tabular-nums;
      min-width: 45px;
    }
    .zao-btn {
      width: 32px;
      height: 32px;
      border: none;
      border-radius: 50%;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.15s ease;
      padding: 0;
    }
    .zao-btn svg {
      width: 16px;
      height: 16px;
    }
    .zao-pause-btn {
      background: rgba(255, 255, 255, 0.15);
      color: white;
    }
    .zao-pause-btn:hover {
      background: rgba(255, 255, 255, 0.25);
    }
    .zao-stop-btn {
      background: #ef4444;
      color: white;
    }
    .zao-stop-btn:hover {
      background: #dc2626;
      transform: scale(1.05);
    }
    #zao-camera-preview {
      position: fixed;
      bottom: 80px;
      right: 20px;
      width: 240px;
      height: 240px;
      border-radius: 50%;
      overflow: hidden;
      z-index: 2147483646;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4);
      border: 3px solid rgba(255, 255, 255, 0.3);
      cursor: grab;
      transition: transform 0.15s ease;
    }
    #zao-camera-preview:hover {
      transform: scale(1.05);
    }
    #zao-camera-preview.dragging {
      cursor: grabbing;
      transition: none;
    }
    #zao-camera-preview video {
      width: 100%;
      height: 100%;
      object-fit: cover;
      transform: scaleX(-1);
    }
  `
  document.documentElement.appendChild(style)

  // Create overlay container
  overlayElement = document.createElement('div')
  overlayElement.id = 'zao-recorder-overlay'

  const inner = document.createElement('div')
  inner.className = 'zao-overlay-inner'

  // Recording dot
  const dot = document.createElement('div')
  dot.className = 'zao-recording-dot'
  inner.appendChild(dot)

  // Timer
  const timer = document.createElement('span')
  timer.className = 'zao-timer'
  timer.textContent = '00:00'
  inner.appendChild(timer)

  // Pause button
  const pauseBtn = document.createElement('button')
  pauseBtn.className = 'zao-btn zao-pause-btn'
  pauseBtn.title = 'Pause'
  const pauseSvg = document.createElementNS('http://www.w3.org/2000/svg', 'svg')
  pauseSvg.setAttribute('viewBox', '0 0 24 24')
  pauseSvg.setAttribute('fill', 'currentColor')
  const pausePath = document.createElementNS('http://www.w3.org/2000/svg', 'path')
  pausePath.setAttribute('d', 'M6 4h4v16H6V4zm8 0h4v16h-4V4z')
  pauseSvg.appendChild(pausePath)
  pauseBtn.appendChild(pauseSvg)
  pauseBtn.addEventListener('click', togglePause)
  inner.appendChild(pauseBtn)

  // Stop button
  const stopBtn = document.createElement('button')
  stopBtn.className = 'zao-btn zao-stop-btn'
  stopBtn.title = 'Stop'
  const stopSvg = document.createElementNS('http://www.w3.org/2000/svg', 'svg')
  stopSvg.setAttribute('viewBox', '0 0 24 24')
  stopSvg.setAttribute('fill', 'currentColor')
  const stopRect = document.createElementNS('http://www.w3.org/2000/svg', 'rect')
  stopRect.setAttribute('x', '6')
  stopRect.setAttribute('y', '6')
  stopRect.setAttribute('width', '12')
  stopRect.setAttribute('height', '12')
  stopRect.setAttribute('rx', '1')
  stopSvg.appendChild(stopRect)
  stopBtn.appendChild(stopSvg)
  stopBtn.addEventListener('click', stopRecording)
  inner.appendChild(stopBtn)

  overlayElement.appendChild(inner)
  document.documentElement.appendChild(overlayElement)

  // Create camera preview if requested
  console.log('[Zao Overlay] Camera options:', { showCamera, cameraDeviceId })
  if (showCamera) {
    await createCameraPreview(cameraDeviceId)
  }

  // Start timer
  startTime = Date.now()
  isPaused = false
  pausedAt = null
  totalPausedMs = 0
  updateTimer()
  timerInterval = setInterval(updateTimer, 1000)
}

async function createCameraPreview(deviceId) {
  console.log('[Zao Overlay] Creating camera preview with deviceId:', deviceId)
  try {
    // Match quality settings with offscreen recording
    cameraStream = await navigator.mediaDevices.getUserMedia({
      video: {
        deviceId: deviceId ? { ideal: deviceId } : true,
        width: { ideal: 1280 },
        height: { ideal: 720 },
        frameRate: { ideal: 30 }
      },
      audio: false
    })
    console.log('[Zao Overlay] Got camera stream:', cameraStream.getVideoTracks()[0]?.getSettings())

    // Create in-page preview (fallback/initial state)
    cameraPreview = document.createElement('div')
    cameraPreview.id = 'zao-camera-preview'

    const video = document.createElement('video')
    video.srcObject = cameraStream
    video.muted = true
    video.autoplay = true
    video.playsInline = true
    cameraVideoElement = video

    cameraPreview.appendChild(video)
    document.documentElement.appendChild(cameraPreview)

    // Make draggable
    let isDragging = false
    let startX, startY, initialX, initialY

    cameraPreview.addEventListener('mousedown', (e) => {
      isDragging = true
      cameraPreview.classList.add('dragging')
      startX = e.clientX
      startY = e.clientY
      const rect = cameraPreview.getBoundingClientRect()
      initialX = rect.left
      initialY = rect.top
    })

    document.addEventListener('mousemove', (e) => {
      if (!isDragging || !cameraPreview) return
      const dx = e.clientX - startX
      const dy = e.clientY - startY
      cameraPreview.style.left = `${initialX + dx}px`
      cameraPreview.style.top = `${initialY + dy}px`
      cameraPreview.style.right = 'auto'
      cameraPreview.style.bottom = 'auto'
    })

    document.addEventListener('mouseup', () => {
      if (!cameraPreview) return
      isDragging = false
      cameraPreview.classList.remove('dragging')
    })

    console.log('[Zao Overlay] Camera preview created')

    // Automatically open Document Picture-in-Picture for desktop recording
    // Small delay to ensure video is playing
    await new Promise(resolve => setTimeout(resolve, 300))
    await openDocumentPictureInPicture()

  } catch (e) {
    console.error('[Zao Overlay] Failed to create camera preview:', e)
  }
}

async function openDocumentPictureInPicture() {
  // Check for Document PiP support
  if (!('documentPictureInPicture' in window)) {
    console.log('[Zao Overlay] Document Picture-in-Picture not supported, falling back to in-page preview')
    return false
  }

  try {
    // Request a Document PiP window - circular so width = height
    const pipSize = 200
    pipWindow = await window.documentPictureInPicture.requestWindow({
      width: pipSize,
      height: pipSize,
    })

    console.log('[Zao Overlay] Document Picture-in-Picture window opened')
    isPipActive = true

    // Add styles to the PiP window
    const style = pipWindow.document.createElement('style')
    style.textContent = `
      * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
      }
      html, body {
        width: 100%;
        height: 100%;
        overflow: hidden;
        background: transparent;
      }
      .pip-container {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: transparent;
      }
      .pip-video-wrapper {
        width: 180px;
        height: 180px;
        border-radius: 50%;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4);
        border: 3px solid rgba(255, 255, 255, 0.3);
        background: #1a1a1a;
      }
      .pip-video-wrapper video {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transform: scaleX(-1);
      }
    `
    pipWindow.document.head.appendChild(style)

    // Create the circular video container
    const container = pipWindow.document.createElement('div')
    container.className = 'pip-container'

    const wrapper = pipWindow.document.createElement('div')
    wrapper.className = 'pip-video-wrapper'

    // Create a new video element in the PiP window
    const pipVideo = pipWindow.document.createElement('video')
    pipVideo.srcObject = cameraStream
    pipVideo.muted = true
    pipVideo.autoplay = true
    pipVideo.playsInline = true

    wrapper.appendChild(pipVideo)
    container.appendChild(wrapper)
    pipWindow.document.body.appendChild(container)

    // Hide the in-page preview when PiP is active
    if (cameraPreview) {
      cameraPreview.style.display = 'none'
    }

    // Handle PiP window closing
    pipWindow.addEventListener('pagehide', () => {
      console.log('[Zao Overlay] Document Picture-in-Picture window closed')
      isPipActive = false
      pipWindow = null
      // Show in-page preview again if recording is still active
      if (cameraPreview && overlayElement) {
        cameraPreview.style.display = 'block'
      }
    })

    return true
  } catch (e) {
    console.error('[Zao Overlay] Failed to open Document Picture-in-Picture:', e)
    return false
  }
}

function closePictureInPicture() {
  if (pipWindow) {
    pipWindow.close()
    pipWindow = null
    isPipActive = false
  }
}

function updateTimer() {
  if (!overlayElement || isPaused) return
  // Exclude paused time so the overlay matches the recorded length.
  const elapsed = Math.floor((Date.now() - startTime - totalPausedMs) / 1000)
  const mins = Math.floor(elapsed / 60).toString().padStart(2, '0')
  const secs = (elapsed % 60).toString().padStart(2, '0')
  const timer = overlayElement.querySelector('.zao-timer')
  if (timer) timer.textContent = `${mins}:${secs}`
}

function togglePause() {
  isPaused = !isPaused
  const dot = overlayElement.querySelector('.zao-recording-dot')
  const btn = overlayElement.querySelector('.zao-pause-btn')

  if (isPaused) {
    pausedAt = Date.now()
    dot.classList.add('paused')
    btn.title = 'Resume'
    chrome.runtime.sendMessage({ action: 'pauseRecording' })
  } else {
    if (pausedAt) {
      totalPausedMs += Date.now() - pausedAt
      pausedAt = null
    }
    dot.classList.remove('paused')
    btn.title = 'Pause'
    chrome.runtime.sendMessage({ action: 'resumeRecording' })
  }
}

function stopRecording() {
  chrome.runtime.sendMessage({ action: 'stopRecording' })
  removeOverlay()
}

function removeOverlay() {
  if (timerInterval) {
    clearInterval(timerInterval)
    timerInterval = null
  }
  if (overlayElement) {
    overlayElement.remove()
    overlayElement = null
  }
  if (cameraPreview) {
    cameraPreview.remove()
    cameraPreview = null
  }
  // Close Picture-in-Picture window
  if (pipWindow) {
    pipWindow.close()
    pipWindow = null
    isPipActive = false
  }
  if (cameraStream) {
    cameraStream.getTracks().forEach(track => track.stop())
    cameraStream = null
  }
  cameraVideoElement = null
}

// Listen for messages from background
chrome.runtime.onMessage.addListener((message) => {
  console.log('[Zao Overlay] Received message:', message)
  if (message.type === 'showRecordingOverlay') {
    console.log('[Zao Overlay] Creating overlay, showCamera:', message.showCamera)
    createOverlay(message.showCamera, message.cameraDeviceId)
  } else if (message.type === 'hideRecordingOverlay') {
    console.log('[Zao Overlay] Removing overlay')
    removeOverlay()
  }
})

console.log('[Zao Overlay] Content script loaded')

// Check if we should show overlay (in case page was refreshed during recording)
chrome.storage.local.get(['recording']).then(({ recording }) => {
  if (recording) {
    createOverlay()
  }
})
