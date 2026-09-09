// Background service worker for Zao Dash Recorder

console.log('[Zao Recorder] Background service worker started')

let recordedChunks = []
let recordingTabId = null
let originalWindowId = null
let recorderWindowId = null
let apiUrl = ''
let apiToken = ''
let taskId = null
let recordingSource = 'screen'
let recordingCameraId = ''
let taxFlowCapture = null
const taxFlowPendingRequests = new Map()

const CHUNK_SIZE = 5 * 1024 * 1024 // 5MB chunks
const TAX_CAPTURE_REQUEST_TYPES = new Set(['main_frame', 'sub_frame', 'xmlhttprequest', 'fetch'])
const TAX_CAPTURE_PRESETS = {
  irs: {
    label: 'IRS.gov / ID.me',
    hostSuffixes: ['irs.gov', 'id.me'],
    startUrls: [
      'https://www.irs.gov/payments/your-online-account',
      'https://www.irs.gov/individuals/get-transcript',
    ],
  },
  oregon_dor: {
    label: 'Oregon Revenue Online',
    hostSuffixes: ['revenueonline.dor.oregon.gov', 'dor.oregon.gov', 'oregon.gov', 'state.or.us'],
    startUrls: [
      'https://revenueonline.dor.oregon.gov/tap/_/',
      'https://www.oregon.gov/dor/programs/individuals/pages/revenue-online.aspx',
    ],
  },
}

loadTaxFlowCaptureState().catch((error) => {
  console.error('[Zao Recorder] Failed to load tax flow capture state:', error)
})

// Helper to convert blob to base64
function blobToBase64(blob) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onloadend = () => resolve(reader.result)
    reader.onerror = reject
    reader.readAsDataURL(blob)
  })
}

// Send message to overlay, injecting scripts if needed
async function sendOverlayMessage(tabId, message) {
  console.log('[Zao Recorder] Sending overlay message to tab:', tabId, message)

  try {
    await chrome.tabs.sendMessage(tabId, message)
    console.log('[Zao Recorder] Overlay message sent successfully')
  } catch (err) {
    console.log('[Zao Recorder] Message failed, injecting content scripts:', err.message)

    // Inject the content scripts
    try {
      await chrome.scripting.executeScript({
        target: { tabId },
        files: ['content.js', 'overlay.js']
      })
      console.log('[Zao Recorder] Content scripts injected')

      // Wait a moment for scripts to initialize
      await new Promise(r => setTimeout(r, 100))

      // Retry sending message
      await chrome.tabs.sendMessage(tabId, message)
      console.log('[Zao Recorder] Overlay message sent after injection')
    } catch (injectErr) {
      console.error('[Zao Recorder] Failed to inject/send:', injectErr)
    }
  }
}

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  console.log('[Zao Recorder] Background received message:', message, 'from:', sender?.tab?.id)
  switch (message.action) {
    case 'startRecording':
      startRecording(message)
      break
    case 'pauseRecording':
      // The real MediaRecorder lives in the offscreen document, not here.
      chrome.runtime.sendMessage({ target: 'offscreen', action: 'pauseCapture' })
      break
    case 'resumeRecording':
      chrome.runtime.sendMessage({ target: 'offscreen', action: 'resumeCapture' })
      break
    case 'stopRecording':
      stopRecording()
      break
    case 'webAppRecordRequest':
      // Handle recording request from web app - capture the sender tab!
      console.log('[Zao Recorder] Web app record request for task:', message.taskId, 'from tab:', sender?.tab?.id)
      if (sender?.tab?.id) {
        recordingTabId = sender.tab.id
        console.log('[Zao Recorder] Captured recording tab ID:', recordingTabId)
      }
      handleWebAppRequest(message)
      break
    case 'startTaxFlowCapture':
      startTaxFlowCapture(message)
      break
    case 'stopTaxFlowCapture':
      stopTaxFlowCapture()
      break
    case 'exportTaxFlowCapture':
      exportTaxFlowCapture()
      break
    case 'clearTaxFlowCapture':
      clearTaxFlowCapture()
      break
    case 'taxFlowEvent':
      if (sender?.tab?.id) {
        recordTaxFlowEvent(sender.tab.id, message.event)
      }
      break
    case 'taxFlowRecorderStatusRequest':
      sendResponse({
        active: !!taxFlowCapture?.active && sender?.tab?.id === taxFlowCapture?.tabId,
        agency: taxFlowCapture?.agency || null,
      })
      break
  }
  return true
})

// Listen for messages from content scripts
chrome.runtime.onConnect.addListener((port) => {
  if (port.name === 'recorder') {
    port.onMessage.addListener((message) => {
      if (message.action === 'recordForTask') {
        // Web app requested recording for a specific task
        taskId = message.taskId
        chrome.action.openPopup()
      }
    })
  }
})

async function startRecording({ source, taskId: tid, apiUrl: url, apiToken: token, micDeviceId, cameraDeviceId }) {
  console.log('[Zao Recorder] startRecording called:', { source, taskId: tid, micDeviceId, cameraDeviceId })
  apiUrl = url
  apiToken = token
  taskId = tid
  recordingSource = source
  recordingCameraId = cameraDeviceId || ''
  recordedChunks = []

  try {
    // Use already-captured tab ID if available (from web app request)
    if (recordingTabId) {
      console.log('[Zao Recorder] Using pre-captured recording tab:', recordingTabId)
    } else {
      // Find the active tab in the current window for the overlay
      const [activeTab] = await chrome.tabs.query({ active: true, currentWindow: true })
      console.log('[Zao Recorder] Active tab:', activeTab ? { id: activeTab.id, url: activeTab.url } : 'none')

      // Use the active tab if it's a web page, otherwise find one
      if (activeTab && activeTab.url && (activeTab.url.startsWith('http://') || activeTab.url.startsWith('https://'))) {
        recordingTabId = activeTab.id
        console.log('[Zao Recorder] Using active tab for overlay:', { id: activeTab.id, url: activeTab.url })
      } else {
        // Fallback: find a suitable tab
        const allTabs = await chrome.tabs.query({ url: ['http://*/*', 'https://*/*'] })
        let tab = allTabs.find(t => t.url.includes('127.0.0.1') || t.url.includes('localhost') || t.url.includes('zaodash'))
        if (!tab) tab = allTabs[0]

        if (tab) {
          recordingTabId = tab.id
          console.log('[Zao Recorder] Fallback tab for overlay:', { id: tab.id, url: tab.url })
        } else {
          console.error('[Zao Recorder] No suitable tab found for overlay!')
        }
      }
    }

    // Create offscreen document for recording
    await setupOffscreenDocument()
    console.log('[Zao Recorder] Offscreen document ready')

    // Tell offscreen document to start capture - it will show its own screen picker
    // Pass upload config so offscreen can upload directly (avoids 64MB message limit)
    console.log('[Zao Recorder] Sending startCapture to offscreen')
    chrome.runtime.sendMessage({
      target: 'offscreen',
      action: 'startCapture',
      source,
      micDeviceId,
      cameraDeviceId,
      apiUrl,
      apiToken,
      taskId,
    })
    // Recording confirmation will come via 'recordingStarted' message from offscreen
  } catch (error) {
    console.error('[Zao Recorder] Recording error:', error)
    chrome.runtime.sendMessage({
      type: 'recordingError',
      error: error.message || 'Failed to start recording'
    })
  }
}

// Handle recording started confirmation from offscreen document
async function onRecordingStarted() {
  console.log('[Zao Recorder] Recording confirmed started')

  await chrome.storage.local.set({ recording: true, startTime: Date.now() })

  // Show overlay on the recording tab
  console.log('[Zao Recorder] onRecordingStarted - recordingTabId:', recordingTabId)
  if (recordingTabId) {
    // Show camera preview so user can see themselves
    // In 'both' mode, offscreen.js will NOT composite (screen capture includes the preview)
    const showCamera = (recordingSource === 'both' || recordingSource === 'camera') && !!recordingCameraId
    const overlayMessage = {
      type: 'showRecordingOverlay',
      showCamera,
      cameraDeviceId: recordingCameraId
    }

    // Try to send message, inject script if needed
    await sendOverlayMessage(recordingTabId, overlayMessage)
  } else {
    console.error('[Zao Recorder] No recordingTabId set!')
  }

  // Close recorder popup and focus the recording tab's window
  if (recorderWindowId) {
    try {
      await chrome.windows.remove(recorderWindowId)
      recorderWindowId = null
      console.log('[Zao Recorder] Closed recorder window')
    } catch (e) { /* window may be closed */ }
  }

  // Focus the window containing the recording tab
  if (recordingTabId) {
    try {
      const tab = await chrome.tabs.get(recordingTabId)
      if (tab.windowId) {
        await chrome.windows.update(tab.windowId, { focused: true })
        await chrome.tabs.update(recordingTabId, { active: true })
        console.log('[Zao Recorder] Focused recording tab window')
      }
    } catch (e) {
      console.log('[Zao Recorder] Could not focus recording tab:', e.message)
      // Fallback to original window
      if (originalWindowId) {
        try {
          await chrome.windows.update(originalWindowId, { focused: true })
        } catch (e2) { /* window may be closed */ }
      }
    }
  }
}

async function setupOffscreenDocument() {
  const existingContexts = await chrome.runtime.getContexts({
    contextTypes: ['OFFSCREEN_DOCUMENT']
  })

  if (existingContexts.length > 0) return

  await chrome.offscreen.createDocument({
    url: 'offscreen.html',
    reasons: ['DISPLAY_MEDIA'],
    justification: 'Recording screen capture via getDisplayMedia'
  })
}

async function stopRecording() {
  // Notify popup immediately that we're processing
  chrome.runtime.sendMessage({ type: 'uploadProgress', progress: 0, status: 'Stopping recording...' })

  chrome.runtime.sendMessage({
    target: 'offscreen',
    action: 'stopCapture'
  })

  // Hide overlay
  if (recordingTabId) {
    chrome.tabs.sendMessage(recordingTabId, { type: 'hideRecordingOverlay' }).catch(() => {})
  }

  await chrome.storage.local.set({ recording: false })
}

// Handle messages from offscreen document
chrome.runtime.onMessage.addListener(async (message) => {
  if (message.type === 'recordingStarted') {
    onRecordingStarted()
  } else if (message.type === 'recordingData') {
    // Fallback: offscreen sent data via message (only works for small files)
    console.log('[Zao Recorder] Got recordingData, thumbnailDataUrl length:', message.thumbnailDataUrl?.length || 0)
    const blob = await fetch(message.dataUrl).then(r => r.blob())
    let thumbnailBlob = null
    if (message.thumbnailDataUrl) {
      try {
        thumbnailBlob = await fetch(message.thumbnailDataUrl).then(r => r.blob())
        console.log('[Zao Recorder] Thumbnail blob created:', thumbnailBlob.size, thumbnailBlob.type)
      } catch (e) {
        console.error('[Zao Recorder] Failed to create thumbnail blob:', e)
      }
    }
    await uploadVideo(blob, thumbnailBlob)
  } else if (message.type === 'uploadComplete' && message.shareUrl) {
    // Direct upload from offscreen completed - open the video
    console.log('[Zao Recorder] Direct upload complete, opening:', message.shareUrl)
    const tab = await chrome.tabs.create({ url: message.shareUrl, active: true })
    if (tab.windowId) {
      chrome.windows.update(tab.windowId, { focused: true })
    }
  }
})

async function uploadVideo(blob, thumbnailBlob = null) {
  try {
    console.log('[Zao Recorder] uploadVideo called, size:', blob.size, 'CHUNK_SIZE:', CHUNK_SIZE)
    // For large files, use chunked upload
    if (blob.size > CHUNK_SIZE) {
      console.log('[Zao Recorder] Using chunked upload')
      await uploadChunked(blob, thumbnailBlob)
    } else {
      console.log('[Zao Recorder] Using single upload')
      await uploadSingle(blob, thumbnailBlob)
    }
  } catch (error) {
    console.error('[Zao Recorder] Upload error:', error)
    chrome.runtime.sendMessage({
      type: 'uploadError',
      error: error.message || 'Upload failed'
    })
  }
}

async function uploadSingle(blob, thumbnailBlob = null) {
  console.log('[Zao Recorder] Uploading blob:', { size: blob.size, type: blob.type, hasThumbnail: !!thumbnailBlob })

  // Show processing state
  chrome.runtime.sendMessage({ type: 'uploadProgress', progress: 0, status: 'Processing video...' })

  // Convert blobs to base64 for reliable transfer from service worker
  const videoBase64 = await blobToBase64(blob)
  const thumbnailBase64 = thumbnailBlob ? await blobToBase64(thumbnailBlob) : null

  chrome.runtime.sendMessage({ type: 'uploadProgress', progress: 30, status: 'Uploading...' })
  console.log('[Zao Recorder] Uploading to:', `${apiUrl}/api/videos/upload-base64`)

  const response = await fetch(`${apiUrl}/api/videos/upload-base64`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${apiToken}`,
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      video: videoBase64,
      thumbnail: thumbnailBase64,
      task_id: taskId,
      filename: 'recording.webm',
      mime_type: 'video/webm',
    }),
  })

  if (!response.ok) {
    const errData = await response.json().catch(() => ({}))
    console.error('[Zao Recorder] Upload error response:', response.status, JSON.stringify(errData, null, 2))
    console.error('[Zao Recorder] Upload details:', { blobSize: blob.size, base64Length: videoBase64.length })
    // Show validation errors if present
    const errorMsg = errData.errors
      ? Object.values(errData.errors).flat().join(', ')
      : errData.message || 'Upload failed'
    throw new Error(errorMsg)
  }

  const result = await response.json()
  chrome.runtime.sendMessage({ type: 'uploadComplete', video: result.video, shareUrl: result.share_url })

  // Open the video in a new tab and focus it
  if (result.share_url) {
    const tab = await chrome.tabs.create({ url: result.share_url, active: true })
    // Focus the window containing the tab
    if (tab.windowId) {
      chrome.windows.update(tab.windowId, { focused: true })
    }
  }
}

async function uploadChunked(blob, thumbnailBlob = null) {
  // Show processing state
  chrome.runtime.sendMessage({ type: 'uploadProgress', progress: 0, status: 'Processing video...' })

  // Calculate chunks first (5MB each)
  const chunkSize = CHUNK_SIZE
  const totalChunks = Math.ceil(blob.size / chunkSize)

  console.log('[Zao Recorder] Chunked upload init:', { blobSize: blob.size, chunkSize, totalChunks, apiUrl })
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

  console.log('[Zao Recorder] Init response status:', initResponse.status)
  const initData = await initResponse.json().catch((e) => {
    console.error('[Zao Recorder] Failed to parse init response:', e)
    return {}
  })
  console.log('[Zao Recorder] Init response data:', initData)

  if (!initResponse.ok) {
    console.error('[Zao Recorder] Init upload error:', initData)
    throw new Error(initData.message || 'Failed to initialize upload')
  }

  const { upload_id, chunk_size: serverChunkSize } = initData
  console.log('[Zao Recorder] Upload initialized:', { upload_id, serverChunkSize })
  // Use server's chunk size (should match our calculation but server is authoritative)
  const actualChunkSize = serverChunkSize || chunkSize
  const actualTotalChunks = Math.ceil(blob.size / actualChunkSize)

  // Upload chunks as base64 (FormData doesn't work reliably from service workers)
  for (let i = 0; i < actualTotalChunks; i++) {
    const start = i * actualChunkSize
    const end = Math.min(start + actualChunkSize, blob.size)
    const chunk = blob.slice(start, end)
    const chunkBase64 = await blobToBase64(chunk)

    console.log(`[Zao Recorder] Uploading chunk ${i + 1}/${actualTotalChunks}`)

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
      const errData = await chunkResponse.json().catch(() => ({}))
      console.error(`[Zao Recorder] Chunk ${i} error:`, errData)
      throw new Error(`Failed to upload chunk ${i}`)
    }

    // Report progress (10-90% for chunks, reserve 90-100% for finalize)
    const progress = 10 + ((i + 1) / actualTotalChunks) * 80
    chrome.runtime.sendMessage({ type: 'uploadProgress', progress, status: `Uploading chunk ${i + 1}/${actualTotalChunks}...` })
  }

  // Finalize upload with base64 thumbnail
  chrome.runtime.sendMessage({ type: 'uploadProgress', progress: 90, status: 'Finalizing...' })
  const thumbnailBase64 = thumbnailBlob ? await blobToBase64(thumbnailBlob) : null
  if (thumbnailBase64) {
    console.log('[Zao Recorder] Including thumbnail in finalize')
  }

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
      thumbnail: thumbnailBase64,
    }),
  })

  if (!finalizeResponse.ok) {
    const errData = await finalizeResponse.json().catch(() => ({}))
    console.error('[Zao Recorder] Finalize error:', errData)
    throw new Error('Failed to finalize upload')
  }

  const result = await finalizeResponse.json()
  chrome.runtime.sendMessage({ type: 'uploadComplete', video: result.video, shareUrl: result.share_url })

  // Open the video in a new tab and focus it
  if (result.share_url) {
    const tab = await chrome.tabs.create({ url: result.share_url, active: true })
    if (tab.windowId) {
      chrome.windows.update(tab.windowId, { focused: true })
    }
  }
}

async function handleWebAppRequest(message) {
  console.log('[Zao Recorder] handleWebAppRequest called with:', message)

  // Store the task ID
  await chrome.storage.local.set({ pendingTaskId: message.taskId })
  console.log('[Zao Recorder] Stored pendingTaskId:', message.taskId)

  // Open recorder window directly
  console.log('[Zao Recorder] Opening popup window...')

  try {
    // Remember the original window to return to later
    const currentWindow = await chrome.windows.getCurrent()
    originalWindowId = currentWindow?.id

    // Create small popup window
    const newWindow = await chrome.windows.create({
      url: chrome.runtime.getURL('popup.html?autostart=1'),
      type: 'popup',
      width: 380,
      height: 520,
      focused: true
    })

    recorderWindowId = newWindow.id
    console.log('[Zao Recorder] Window created:', newWindow)

    // If Chrome made it fullscreen, resize it
    if (newWindow.state === 'fullscreen' || newWindow.state === 'maximized') {
      await chrome.windows.update(newWindow.id, { state: 'normal' })
      await chrome.windows.update(newWindow.id, { width: 380, height: 520 })
      console.log('[Zao Recorder] Window resized from fullscreen')
    }
  } catch (err) {
    console.error('[Zao Recorder] Failed to create window:', err)
  }
}

async function loadTaxFlowCaptureState() {
  const { taxFlowCaptureSession } = await chrome.storage.local.get(['taxFlowCaptureSession'])

  if (taxFlowCaptureSession?.active) {
    taxFlowCapture = taxFlowCaptureSession
  }
}

function taxCapturePreset(agency) {
  return TAX_CAPTURE_PRESETS[agency] || TAX_CAPTURE_PRESETS.irs
}

function emptyTaxFlowCaptureState() {
  return {
    active: false,
    status: 'idle',
    agency: null,
    agencyLabel: null,
    tabId: null,
    startedAt: null,
    stoppedAt: null,
    currentUrl: null,
    eventCount: 0,
    requestCount: 0,
    navigationCount: 0,
    latestAvailable: false,
    latestFilename: null,
  }
}

function sanitizedUrlParts(rawUrl) {
  if (!rawUrl || typeof rawUrl !== 'string') {
    return {
      url: null,
      host: null,
      path: null,
      queryKeys: [],
    }
  }

  try {
    const parsed = new URL(rawUrl)
    const queryKeys = Array.from(new Set(Array.from(parsed.searchParams.keys()))).sort()

    return {
      url: `${parsed.origin}${parsed.pathname}`,
      host: parsed.host,
      path: parsed.pathname,
      queryKeys,
    }
  } catch (error) {
    const [withoutHash] = rawUrl.split('#')
    const [withoutQuery] = withoutHash.split('?')

    return {
      url: withoutQuery,
      host: null,
      path: null,
      queryKeys: [],
    }
  }
}

function normalizedRequestSeed(details) {
  const url = sanitizedUrlParts(details.url)

  return {
    request_id: details.requestId,
    recorded_at: new Date().toISOString(),
    url: url.url,
    host: url.host,
    path: url.path,
    query_keys: url.queryKeys,
    method: details.method,
    resource_type: details.type,
    initiator: details.initiator || null,
  }
}

function shouldTrackTaxCaptureUrl(rawUrl) {
  if (!taxFlowCapture) return false

  const { host } = sanitizedUrlParts(rawUrl)
  if (!host) return false

  return taxCapturePreset(taxFlowCapture.agency).hostSuffixes.some((suffix) => host === suffix || host.endsWith(`.${suffix}`))
}

function buildTaxFlowCaptureState() {
  if (!taxFlowCapture) {
    return emptyTaxFlowCaptureState()
  }

  return {
    active: !!taxFlowCapture.active,
    status: taxFlowCapture.status,
    agency: taxFlowCapture.agency,
    agencyLabel: taxFlowCapture.agencyLabel,
    tabId: taxFlowCapture.tabId,
    startedAt: taxFlowCapture.startedAt,
    stoppedAt: taxFlowCapture.stoppedAt || null,
    currentUrl: taxFlowCapture.currentUrl || taxFlowCapture.startedUrl || null,
    eventCount: taxFlowCapture.events.length,
    requestCount: taxFlowCapture.requests.length,
    navigationCount: taxFlowCapture.navigation.length,
    latestAvailable: !taxFlowCapture.active,
    latestFilename: taxFlowCapture.exportFilename || null,
  }
}

async function persistTaxFlowCaptureSession() {
  if (!taxFlowCapture) {
    await chrome.storage.local.set({
      taxFlowCaptureSession: null,
      taxFlowCaptureState: emptyTaxFlowCaptureState(),
    })

    return
  }

  await chrome.storage.local.set({
    taxFlowCaptureSession: taxFlowCapture,
    taxFlowCaptureState: buildTaxFlowCaptureState(),
  })
}

function normalizeTaxFlowEvent(event) {
  const normalized = {
    ...event,
    recorded_at: new Date().toISOString(),
  }

  if (normalized.page_url) {
    const pageUrl = sanitizedUrlParts(normalized.page_url)
    normalized.page_url = pageUrl.url
    normalized.page_host = pageUrl.host
    normalized.page_path = pageUrl.path
    normalized.page_query_keys = pageUrl.queryKeys
  }

  if (normalized.target?.href) {
    const href = sanitizedUrlParts(normalized.target.href)
    normalized.target = {
      ...normalized.target,
      href: href.url,
      href_host: href.host,
      href_path: href.path,
      href_query_keys: href.queryKeys,
    }
  }

  if (normalized.form?.action) {
    const action = sanitizedUrlParts(normalized.form.action)
    normalized.form = {
      ...normalized.form,
      action: action.url,
      action_host: action.host,
      action_path: action.path,
      action_query_keys: action.queryKeys,
    }
  }

  if (normalized.type === 'page_context') {
    normalized.mfa_signal = (normalized.otp_input_count || 0) > 0
  }

  return normalized
}

async function sendTaxFlowRecorderMessage(tabId, message) {
  try {
    await chrome.tabs.sendMessage(tabId, message)
  } catch (error) {
    try {
      await chrome.scripting.executeScript({
        target: { tabId },
        files: ['tax-flow-recorder.js'],
      })

      await new Promise((resolve) => setTimeout(resolve, 100))
      await chrome.tabs.sendMessage(tabId, message)
    } catch (injectError) {
      console.error('[Zao Recorder] Failed to reach tax flow recorder script:', injectError)
    }
  }
}

async function startTaxFlowCapture({ agency }) {
  if (taxFlowCapture?.active) {
    chrome.runtime.sendMessage({
      type: 'taxFlowCaptureError',
      error: 'A tax portal flow capture is already running.',
    })
    return
  }

  const [activeTab] = await chrome.tabs.query({ active: true, currentWindow: true })
  if (!activeTab?.id || !activeTab.url || (!activeTab.url.startsWith('http://') && !activeTab.url.startsWith('https://'))) {
    chrome.runtime.sendMessage({
      type: 'taxFlowCaptureError',
      error: 'Open the IRS or Oregon login flow in a normal browser tab before starting capture.',
    })
    return
  }

  const preset = taxCapturePreset(agency)
  const activeHost = sanitizedUrlParts(activeTab.url).host
  const allowedHost = preset.hostSuffixes.some((suffix) => activeHost === suffix || activeHost?.endsWith(`.${suffix}`))

  if (!allowedHost) {
    chrome.runtime.sendMessage({
      type: 'taxFlowCaptureError',
      error: `Start capture from an ${preset.label} browser tab.`,
    })
    return
  }

  const startUrl = sanitizedUrlParts(activeTab.url)

  taxFlowCapture = {
    id: `tax-flow-${Date.now()}`,
    active: true,
    status: 'recording',
    agency,
    agencyLabel: preset.label,
    preset,
    tabId: activeTab.id,
    windowId: activeTab.windowId,
    startedAt: new Date().toISOString(),
    stoppedAt: null,
    startedUrl: startUrl.url,
    currentUrl: startUrl.url,
    startedTitle: activeTab.title || null,
    events: [],
    requests: [],
    navigation: [
      {
        type: 'capture_started',
        recorded_at: new Date().toISOString(),
        url: startUrl.url,
        host: startUrl.host,
        path: startUrl.path,
        query_keys: startUrl.queryKeys,
        title: activeTab.title || null,
      },
    ],
    notes: [
      'Input values, passwords, cookies, and query-string values are redacted by design.',
      'Keep the full login flow in one browser tab for the cleanest capture.',
    ],
    exportFilename: null,
  }

  await persistTaxFlowCaptureSession()
  await sendTaxFlowRecorderMessage(activeTab.id, {
    type: 'taxFlowCaptureStart',
    agency,
  })

  chrome.runtime.sendMessage({
    type: 'taxFlowCaptureStarted',
    state: buildTaxFlowCaptureState(),
  })
}

async function stopTaxFlowCapture() {
  if (!taxFlowCapture?.active) {
    chrome.runtime.sendMessage({
      type: 'taxFlowCaptureError',
      error: 'No tax portal flow capture is currently running.',
    })
    return
  }

  taxFlowCapture.active = false
  taxFlowCapture.status = 'stopped'
  taxFlowCapture.stoppedAt = new Date().toISOString()

  if (taxFlowCapture.tabId) {
    await sendTaxFlowRecorderMessage(taxFlowCapture.tabId, {
      type: 'taxFlowCaptureStop',
    })
  }

  const completedCapture = {
    ...taxFlowCapture,
  }

  await chrome.storage.local.set({
    taxFlowCaptureLatest: completedCapture,
    taxFlowCaptureSession: null,
    taxFlowCaptureState: {
      ...buildTaxFlowCaptureState(),
      active: false,
      latestAvailable: true,
    },
  })

  taxFlowCapture = null
  taxFlowPendingRequests.clear()

  chrome.runtime.sendMessage({
    type: 'taxFlowCaptureStopped',
    state: {
      ...emptyTaxFlowCaptureState(),
      latestAvailable: true,
      agency: completedCapture.agency,
      agencyLabel: completedCapture.agencyLabel,
      startedAt: completedCapture.startedAt,
      stoppedAt: completedCapture.stoppedAt,
      eventCount: completedCapture.events.length,
      requestCount: completedCapture.requests.length,
      navigationCount: completedCapture.navigation.length,
    },
  })
}

async function clearTaxFlowCapture() {
  if (taxFlowCapture?.active) {
    chrome.runtime.sendMessage({
      type: 'taxFlowCaptureError',
      error: 'Stop the current tax portal flow capture before clearing it.',
    })
    return
  }

  await chrome.storage.local.remove(['taxFlowCaptureLatest', 'taxFlowCaptureSession'])
  await chrome.storage.local.set({ taxFlowCaptureState: emptyTaxFlowCaptureState() })

  chrome.runtime.sendMessage({
    type: 'taxFlowCaptureCleared',
    state: emptyTaxFlowCaptureState(),
  })
}

async function exportTaxFlowCapture() {
  if (taxFlowCapture?.active) {
    chrome.runtime.sendMessage({
      type: 'taxFlowCaptureError',
      error: 'Stop the tax portal flow capture before exporting it.',
    })
    return
  }

  const { taxFlowCaptureLatest } = await chrome.storage.local.get(['taxFlowCaptureLatest'])
  if (!taxFlowCaptureLatest) {
    chrome.runtime.sendMessage({
      type: 'taxFlowCaptureError',
      error: 'No completed tax portal flow capture is available to export.',
    })
    return
  }

  const exportPayload = {
    capture_version: '1.0.0',
    exported_at: new Date().toISOString(),
    agency: taxFlowCaptureLatest.agency,
    agency_label: taxFlowCaptureLatest.agencyLabel,
    tab_id: taxFlowCaptureLatest.tabId,
    started_at: taxFlowCaptureLatest.startedAt,
    stopped_at: taxFlowCaptureLatest.stoppedAt,
    started_url: taxFlowCaptureLatest.startedUrl,
    notes: taxFlowCaptureLatest.notes,
    navigation: taxFlowCaptureLatest.navigation,
    events: taxFlowCaptureLatest.events,
    requests: taxFlowCaptureLatest.requests,
  }

  try {
    const timestamp = (taxFlowCaptureLatest.startedAt || new Date().toISOString()).replace(/[:.]/g, '-')
    const filename = `zao-tax-flow-${taxFlowCaptureLatest.agency || 'capture'}-${timestamp}.json`
    const encodedPayload = encodeURIComponent(JSON.stringify(exportPayload, null, 2))

    await chrome.downloads.download({
      url: `data:application/json;charset=utf-8,${encodedPayload}`,
      filename,
      saveAs: true,
    })

    chrome.runtime.sendMessage({
      type: 'taxFlowCaptureExported',
      filename,
    })
  } catch (error) {
    chrome.runtime.sendMessage({
      type: 'taxFlowCaptureError',
      error: `Capture export failed: ${error?.message || 'unknown error'}`,
    })
  }
}

async function recordTaxFlowEvent(tabId, event) {
  if (!taxFlowCapture?.active || taxFlowCapture.tabId !== tabId) {
    return
  }

  const normalizedEvent = normalizeTaxFlowEvent(event || {})
  if (normalizedEvent.page_url && !shouldTrackTaxCaptureUrl(normalizedEvent.page_url)) {
    return
  }

  taxFlowCapture.currentUrl = normalizedEvent.page_url || taxFlowCapture.currentUrl
  taxFlowCapture.events.push(normalizedEvent)

  await persistTaxFlowCaptureSession()

  chrome.runtime.sendMessage({
    type: 'taxFlowCaptureUpdated',
    state: buildTaxFlowCaptureState(),
  })
}

chrome.webNavigation.onCommitted.addListener(async (details) => {
  if (!taxFlowCapture?.active || details.tabId !== taxFlowCapture.tabId || details.frameId !== 0 || !shouldTrackTaxCaptureUrl(details.url)) {
    return
  }

  const url = sanitizedUrlParts(details.url)

  taxFlowCapture.currentUrl = url.url
  taxFlowCapture.navigation.push({
    type: 'navigation_committed',
    recorded_at: new Date().toISOString(),
    url: url.url,
    host: url.host,
    path: url.path,
    query_keys: url.queryKeys,
    transition_type: details.transitionType,
    transition_qualifiers: details.transitionQualifiers || [],
  })

  await persistTaxFlowCaptureSession()
  chrome.runtime.sendMessage({ type: 'taxFlowCaptureUpdated', state: buildTaxFlowCaptureState() })
})

chrome.webNavigation.onCompleted.addListener(async (details) => {
  if (!taxFlowCapture?.active || details.tabId !== taxFlowCapture.tabId || details.frameId !== 0 || !shouldTrackTaxCaptureUrl(details.url)) {
    return
  }

  const url = sanitizedUrlParts(details.url)

  taxFlowCapture.currentUrl = url.url
  taxFlowCapture.navigation.push({
    type: 'navigation_completed',
    recorded_at: new Date().toISOString(),
    url: url.url,
    host: url.host,
    path: url.path,
    query_keys: url.queryKeys,
  })

  await persistTaxFlowCaptureSession()
  chrome.runtime.sendMessage({ type: 'taxFlowCaptureUpdated', state: buildTaxFlowCaptureState() })
})

chrome.webNavigation.onHistoryStateUpdated.addListener(async (details) => {
  if (!taxFlowCapture?.active || details.tabId !== taxFlowCapture.tabId || details.frameId !== 0 || !shouldTrackTaxCaptureUrl(details.url)) {
    return
  }

  const url = sanitizedUrlParts(details.url)

  taxFlowCapture.currentUrl = url.url
  taxFlowCapture.navigation.push({
    type: 'history_state_updated',
    recorded_at: new Date().toISOString(),
    url: url.url,
    host: url.host,
    path: url.path,
    query_keys: url.queryKeys,
  })

  await persistTaxFlowCaptureSession()
  chrome.runtime.sendMessage({ type: 'taxFlowCaptureUpdated', state: buildTaxFlowCaptureState() })
})

chrome.webRequest.onBeforeRequest.addListener((details) => {
  if (!taxFlowCapture?.active || details.tabId !== taxFlowCapture.tabId || !TAX_CAPTURE_REQUEST_TYPES.has(details.type) || !shouldTrackTaxCaptureUrl(details.url)) {
    return
  }

  taxFlowPendingRequests.set(details.requestId, normalizedRequestSeed(details))
}, { urls: ['<all_urls>'] })

chrome.webRequest.onBeforeRedirect.addListener(async (details) => {
  if (!taxFlowCapture?.active || details.tabId !== taxFlowCapture.tabId || !shouldTrackTaxCaptureUrl(details.url)) {
    return
  }

  const pending = taxFlowPendingRequests.get(details.requestId)
  const redirectUrl = sanitizedUrlParts(details.redirectUrl)

  taxFlowCapture.requests.push({
    ...(pending || normalizedRequestSeed(details)),
    status: 'redirected',
    status_code: details.statusCode || null,
    redirect_to: redirectUrl.url,
    redirect_host: redirectUrl.host,
    redirect_path: redirectUrl.path,
    redirect_query_keys: redirectUrl.queryKeys,
  })

  taxFlowPendingRequests.delete(details.requestId)
  await persistTaxFlowCaptureSession()
  chrome.runtime.sendMessage({ type: 'taxFlowCaptureUpdated', state: buildTaxFlowCaptureState() })
}, { urls: ['<all_urls>'] })

chrome.webRequest.onCompleted.addListener(async (details) => {
  if (!taxFlowCapture?.active || details.tabId !== taxFlowCapture.tabId || !shouldTrackTaxCaptureUrl(details.url)) {
    return
  }

  const pending = taxFlowPendingRequests.get(details.requestId)
  if (!pending) {
    return
  }

  taxFlowCapture.requests.push({
    ...pending,
    status: 'completed',
    status_code: details.statusCode || null,
    ip: details.ip || null,
    from_cache: !!details.fromCache,
  })

  taxFlowPendingRequests.delete(details.requestId)
  await persistTaxFlowCaptureSession()
  chrome.runtime.sendMessage({ type: 'taxFlowCaptureUpdated', state: buildTaxFlowCaptureState() })
}, { urls: ['<all_urls>'] })

chrome.webRequest.onErrorOccurred.addListener(async (details) => {
  if (!taxFlowCapture?.active || details.tabId !== taxFlowCapture.tabId || !shouldTrackTaxCaptureUrl(details.url)) {
    return
  }

  const pending = taxFlowPendingRequests.get(details.requestId)
  taxFlowCapture.requests.push({
    ...(pending || normalizedRequestSeed(details)),
    status: 'error',
    error: details.error,
  })

  taxFlowPendingRequests.delete(details.requestId)
  await persistTaxFlowCaptureSession()
  chrome.runtime.sendMessage({ type: 'taxFlowCaptureUpdated', state: buildTaxFlowCaptureState() })
}, { urls: ['<all_urls>'] })
