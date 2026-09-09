// Offscreen document for media recording

let mediaRecorder = null
let recordedChunks = []
let screenStream = null
let micStream = null
let cameraStream = null
let canvasStream = null
let animationId = null

// Store upload config for direct upload from offscreen
let uploadConfig = { apiUrl: '', apiToken: '', taskId: null }
const CHUNK_SIZE = 5 * 1024 * 1024 // 5MB chunks

chrome.runtime.onMessage.addListener(async (message) => {
  if (message.target !== 'offscreen') return

  switch (message.action) {
    case 'startCapture':
      // Store upload config for later
      uploadConfig = {
        apiUrl: message.apiUrl || '',
        apiToken: message.apiToken || '',
        taskId: message.taskId || null
      }
      await startCapture(message.source, message.micDeviceId, message.cameraDeviceId)
      break
    case 'pauseCapture':
      if (mediaRecorder && mediaRecorder.state === 'recording') {
        mediaRecorder.pause()
        console.log('[Zao Recorder] MediaRecorder paused')
      }
      break
    case 'resumeCapture':
      if (mediaRecorder && mediaRecorder.state === 'paused') {
        mediaRecorder.resume()
        console.log('[Zao Recorder] MediaRecorder resumed')
      }
      break
    case 'stopCapture':
      stopCapture()
      break
  }
})

async function startCapture(source, micDeviceId, cameraDeviceId) {
  try {
    console.log('[Zao Recorder] Starting capture:', { source, micDeviceId, cameraDeviceId })
    recordedChunks = []

    // 1. Get screen capture (unless camera-only mode)
    if (source !== 'camera') {
      console.log('[Zao Recorder] Requesting getDisplayMedia')
      screenStream = await navigator.mediaDevices.getDisplayMedia({
        video: {
          width: { ideal: 2560, max: 3840 },
          height: { ideal: 1440, max: 2160 },
          frameRate: { ideal: 30, max: 60 }
        },
        audio: true  // System audio if available
      })
      console.log('[Zao Recorder] Got screen stream:', screenStream.getTracks().map(t => t.kind))
    }

    // 2. Get microphone audio if selected
    if (micDeviceId) {
      try {
        micStream = await navigator.mediaDevices.getUserMedia({
          audio: { deviceId: { exact: micDeviceId } },
          video: false
        })
        console.log('[Zao Recorder] Got mic stream')
      } catch (e) {
        console.warn('[Zao Recorder] Mic capture failed:', e.message)
      }
    }

    // 3. Get camera if needed (for 'both' or 'camera' mode)
    if ((source === 'both' || source === 'camera') && cameraDeviceId) {
      try {
        cameraStream = await navigator.mediaDevices.getUserMedia({
          video: {
            deviceId: { exact: cameraDeviceId },
            width: { ideal: 1280 },
            height: { ideal: 720 },
            frameRate: { ideal: 30 }
          },
          audio: source === 'camera' && !micDeviceId // Use camera audio only if no mic selected in camera-only mode
        })
        console.log('[Zao Recorder] Got camera stream:', cameraStream.getVideoTracks()[0]?.getSettings())
      } catch (e) {
        console.warn('[Zao Recorder] Camera capture failed:', e.message)
      }
    }

    // 4. Select final stream
    // Note: In 'both' mode, we DON'T composite here - the visible overlay preview
    // on the page gets captured by getDisplayMedia, avoiding double camera
    let finalStream
    if (source === 'camera' && cameraStream) {
      // Camera-only mode - use camera stream directly
      finalStream = cameraStream
    } else if (screenStream) {
      // Screen or 'both' mode - just use screen stream
      // The camera preview overlay is visible on screen and gets captured
      finalStream = screenStream
      // Stop the camera stream in offscreen since we're not using it
      if (cameraStream) {
        cameraStream.getTracks().forEach(track => track.stop())
        cameraStream = null
      }
    } else {
      throw new Error('No video source available')
    }

    // 5. Add mic audio to final stream if available
    if (micStream) {
      const audioTrack = micStream.getAudioTracks()[0]
      if (audioTrack) {
        finalStream.addTrack(audioTrack)
        console.log('[Zao Recorder] Added mic audio to stream')
      }
    }

    console.log('[Zao Recorder] Final stream tracks:', finalStream.getTracks().map(t => `${t.kind}:${t.label}`))

    // 6. Create MediaRecorder with high quality settings
    const options = { videoBitsPerSecond: 8000000 } // 8 Mbps for high quality
    if (MediaRecorder.isTypeSupported('video/webm;codecs=vp9')) {
      options.mimeType = 'video/webm;codecs=vp9'
    } else if (MediaRecorder.isTypeSupported('video/webm;codecs=vp8')) {
      options.mimeType = 'video/webm;codecs=vp8'
    } else {
      options.mimeType = 'video/webm'
    }

    mediaRecorder = new MediaRecorder(finalStream, options)

    mediaRecorder.ondataavailable = (event) => {
      if (event.data && event.data.size > 0) {
        recordedChunks.push(event.data)
      }
    }

    mediaRecorder.onstop = async () => {
      const blob = new Blob(recordedChunks, { type: 'video/webm' })
      console.log('[Zao Recorder] Recording stopped, blob size:', blob.size)

      // Generate thumbnail from first frame
      const thumbnailDataUrl = await generateThumbnail(blob)

      // Upload directly from offscreen to avoid 64MB message limit
      if (uploadConfig.apiUrl && uploadConfig.apiToken) {
        await uploadVideoDirectly(blob, thumbnailDataUrl)
      } else {
        // Fallback: try to send via message (will fail for large files)
        console.warn('[Zao Recorder] No upload config, attempting message passthrough')
        const reader = new FileReader()
        reader.onloadend = () => {
          chrome.runtime.sendMessage({
            type: 'recordingData',
            dataUrl: reader.result,
            thumbnailDataUrl,
          })
        }
        reader.readAsDataURL(blob)
      }
      cleanup()
    }

    mediaRecorder.onerror = (event) => {
      console.error('MediaRecorder error:', event.error)
      chrome.runtime.sendMessage({
        type: 'recordingError',
        error: event.error?.message || 'Recording error'
      })
      cleanup()
    }

    mediaRecorder.start(1000)
    console.log('[Zao Recorder] MediaRecorder started')
    chrome.runtime.sendMessage({ type: 'recordingStarted' })

  } catch (error) {
    console.error('Capture error:', error)
    chrome.runtime.sendMessage({
      type: 'recordingError',
      error: error.message || 'Failed to start capture'
    })
    cleanup()
  }
}

// Composite screen and camera into single stream using canvas
async function createCompositeStream(screenStream, cameraStream) {
  const screenTrack = screenStream.getVideoTracks()[0]
  const cameraTrack = cameraStream.getVideoTracks()[0]

  const screenSettings = screenTrack.getSettings()
  const width = screenSettings.width || 1920
  const height = screenSettings.height || 1080

  // Create canvas for compositing
  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height
  const ctx = canvas.getContext('2d')
  // Enable high quality image rendering
  ctx.imageSmoothingEnabled = true
  ctx.imageSmoothingQuality = 'high'

  // Create video elements
  const screenVideo = document.createElement('video')
  screenVideo.srcObject = new MediaStream([screenTrack])
  screenVideo.muted = true
  await screenVideo.play()

  const cameraVideo = document.createElement('video')
  cameraVideo.srcObject = new MediaStream([cameraTrack])
  cameraVideo.muted = true
  await cameraVideo.play()

  // Camera PiP dimensions (bottom-right corner) - larger for better quality
  const pipWidth = 320
  const pipHeight = 240
  const pipMargin = 30
  const pipX = width - pipWidth - pipMargin
  const pipY = height - pipHeight - pipMargin
  const pipRadius = 24

  // Pre-calculate values for performance (avoid calculations in draw loop)
  const borderPath = new Path2D()
  borderPath.roundRect(pipX, pipY, pipWidth, pipHeight, pipRadius)

  const clipPath = new Path2D()
  clipPath.roundRect(pipX, pipY, pipWidth, pipHeight, pipRadius)

  // Pre-calculate camera crop values
  const camSettings = cameraTrack.getSettings()
  const camWidth = camSettings.width || 1280
  const camHeight = camSettings.height || 720
  const camAspect = camWidth / camHeight
  const pipAspect = pipWidth / pipHeight
  let sx = 0, sy = 0, sw = camWidth, sh = camHeight
  if (camAspect > pipAspect) {
    sw = sh * pipAspect
    sx = (camWidth - sw) / 2
  } else {
    sh = sw / pipAspect
    sy = (camHeight - sh) / 2
  }

  // Draw composite frame - use setInterval instead of requestAnimationFrame
  // because offscreen documents may throttle rAF
  function drawFrame() {
    // Draw screen (full canvas)
    ctx.drawImage(screenVideo, 0, 0, width, height)

    // Draw camera PiP with rounded corners
    ctx.save()
    ctx.clip(clipPath)
    ctx.drawImage(cameraVideo, sx, sy, sw, sh, pipX, pipY, pipWidth, pipHeight)
    ctx.restore()

    // Add border to PiP
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.4)'
    ctx.lineWidth = 3
    ctx.stroke(borderPath)
  }

  // Use setInterval at 30fps for offscreen document compatibility
  // Note: Higher rates don't improve smoothness much but increase CPU usage
  animationId = setInterval(drawFrame, 1000 / 30)
  drawFrame() // Draw first frame immediately

  // Capture canvas stream
  canvasStream = canvas.captureStream(30)

  // Add system audio from screen capture if available
  const screenAudioTrack = screenStream.getAudioTracks()[0]
  if (screenAudioTrack) {
    canvasStream.addTrack(screenAudioTrack)
  }

  return canvasStream
}

function stopCapture() {
  if (mediaRecorder && mediaRecorder.state !== 'inactive') {
    mediaRecorder.stop()
  }
}

// Generate thumbnail from video blob using canvas
async function generateThumbnail(videoBlob) {
  console.log('[Zao Recorder] Generating thumbnail from blob:', videoBlob.size)
  return new Promise((resolve) => {
    const video = document.createElement('video')
    video.muted = true
    video.playsInline = true
    video.preload = 'metadata'

    const url = URL.createObjectURL(videoBlob)
    video.src = url

    video.onloadedmetadata = () => {
      console.log('[Zao Recorder] Video metadata loaded:', { duration: video.duration, width: video.videoWidth, height: video.videoHeight })
      // Seek to 0.5 seconds or start
      video.currentTime = Math.min(0.5, video.duration || 0.5)
    }

    video.onseeked = () => {
      console.log('[Zao Recorder] Video seeked, capturing frame')
      try {
        const canvas = document.createElement('canvas')
        const width = Math.min(640, video.videoWidth || 640)
        const height = video.videoHeight ? Math.round(width * (video.videoHeight / video.videoWidth)) : 360
        canvas.width = width
        canvas.height = height

        const ctx = canvas.getContext('2d')
        ctx.drawImage(video, 0, 0, width, height)

        const thumbnailDataUrl = canvas.toDataURL('image/jpeg', 0.8)
        console.log('[Zao Recorder] Thumbnail generated, length:', thumbnailDataUrl.length)
        URL.revokeObjectURL(url)
        resolve(thumbnailDataUrl)
      } catch (e) {
        console.error('[Zao Recorder] Thumbnail canvas error:', e)
        URL.revokeObjectURL(url)
        resolve(null)
      }
    }

    video.onerror = (e) => {
      console.error('[Zao Recorder] Video load error for thumbnail:', e)
      URL.revokeObjectURL(url)
      resolve(null)
    }

    // Timeout fallback
    setTimeout(() => {
      console.warn('[Zao Recorder] Thumbnail generation timeout')
      URL.revokeObjectURL(url)
      resolve(null)
    }, 5000)

    // Start loading
    video.load()
  })
}

function cleanup() {
  // Cancel animation interval
  if (animationId) {
    clearInterval(animationId)
    animationId = null
  }

  // Stop all streams
  if (screenStream) {
    screenStream.getTracks().forEach(track => track.stop())
    screenStream = null
  }
  if (micStream) {
    micStream.getTracks().forEach(track => track.stop())
    micStream = null
  }
  if (cameraStream) {
    cameraStream.getTracks().forEach(track => track.stop())
    cameraStream = null
  }
  if (canvasStream) {
    canvasStream.getTracks().forEach(track => track.stop())
    canvasStream = null
  }

  mediaRecorder = null
  recordedChunks = []
}

// Helper to convert blob to base64
function blobToBase64(blob) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onloadend = () => resolve(reader.result)
    reader.onerror = reject
    reader.readAsDataURL(blob)
  })
}

// Upload video directly from offscreen document
async function uploadVideoDirectly(blob, thumbnailDataUrl) {
  const { apiUrl, apiToken, taskId } = uploadConfig
  console.log('[Zao Recorder] Direct upload starting:', { size: blob.size, apiUrl, hasTaskId: !!taskId })

  try {
    chrome.runtime.sendMessage({ type: 'uploadProgress', progress: 0, status: 'Processing video...' })

    // Use chunked upload for all files (more reliable)
    const totalChunks = Math.ceil(blob.size / CHUNK_SIZE)
    console.log('[Zao Recorder] Chunked upload:', { totalChunks, chunkSize: CHUNK_SIZE })

    chrome.runtime.sendMessage({ type: 'uploadProgress', progress: 5, status: 'Initializing upload...' })

    // Initialize chunked upload
    const initResponse = await fetch(`${apiUrl}/api/videos/upload/init`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${apiToken}`,
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        filename: 'recording.webm',
        total_size: blob.size,
        total_chunks: totalChunks,
      }),
    })

    if (!initResponse.ok) {
      const errData = await initResponse.json().catch(() => ({}))
      throw new Error(errData.message || 'Failed to initialize upload')
    }

    const { upload_id } = await initResponse.json()
    console.log('[Zao Recorder] Upload initialized:', upload_id)

    // Upload chunks
    for (let i = 0; i < totalChunks; i++) {
      const start = i * CHUNK_SIZE
      const end = Math.min(start + CHUNK_SIZE, blob.size)
      const chunk = blob.slice(start, end)
      const chunkBase64 = await blobToBase64(chunk)

      console.log(`[Zao Recorder] Uploading chunk ${i + 1}/${totalChunks}`)

      const chunkResponse = await fetch(`${apiUrl}/api/videos/upload/chunk`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${apiToken}`,
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          upload_id,
          chunk_index: i,
          chunk: chunkBase64,
        }),
      })

      if (!chunkResponse.ok) {
        throw new Error(`Failed to upload chunk ${i}`)
      }

      const progress = 10 + ((i + 1) / totalChunks) * 80
      chrome.runtime.sendMessage({ type: 'uploadProgress', progress, status: `Uploading ${i + 1}/${totalChunks}...` })
    }

    // Finalize upload
    chrome.runtime.sendMessage({ type: 'uploadProgress', progress: 90, status: 'Finalizing...' })

    const finalizeResponse = await fetch(`${apiUrl}/api/videos/upload/finalize`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${apiToken}`,
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        upload_id,
        task_id: taskId,
        thumbnail: thumbnailDataUrl,
      }),
    })

    if (!finalizeResponse.ok) {
      const errData = await finalizeResponse.json().catch(() => ({}))
      throw new Error(errData.message || 'Failed to finalize upload')
    }

    const result = await finalizeResponse.json()
    console.log('[Zao Recorder] Upload complete:', result)
    chrome.runtime.sendMessage({ type: 'uploadComplete', video: result.video, shareUrl: result.share_url })

  } catch (error) {
    console.error('[Zao Recorder] Direct upload error:', error)
    chrome.runtime.sendMessage({ type: 'uploadError', error: error.message || 'Upload failed' })
  }
}
