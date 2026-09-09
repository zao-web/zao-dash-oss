// Popup script for Zao Dash Recorder

let state = {
  connected: false,
  recording: false,
  startingRecording: false,
  paused: false,
  apiUrl: '',
  apiToken: '',
  source: 'screen',
  taskId: null,
  startTime: null,
  pausedAt: null,
  totalPausedMs: 0,
  timerInterval: null,
  micDeviceId: '',
  cameraDeviceId: '',
  taxFlowCapture: {
    active: false,
    status: 'idle',
    agency: 'irs',
    eventCount: 0,
    requestCount: 0,
    navigationCount: 0,
    latestAvailable: false,
    startedAt: null,
    stoppedAt: null,
    currentUrl: null,
  },
}

// DOM Elements
const els = {
  statusDot: document.getElementById('statusDot'),
  statusText: document.getElementById('statusText'),
  errorMsg: document.getElementById('errorMsg'),
  authSection: document.getElementById('authSection'),
  apiUrl: document.getElementById('apiUrl'),
  apiToken: document.getElementById('apiToken'),
  connectBtn: document.getElementById('connectBtn'),
  recordSection: document.getElementById('recordSection'),
  taskSelect: document.getElementById('taskSelect'),
  micSelect: document.getElementById('micSelect'),
  cameraSelect: document.getElementById('cameraSelect'),
  cameraSelectGroup: document.getElementById('cameraSelectGroup'),
  timer: document.getElementById('timer'),
  startBtn: document.getElementById('startBtn'),
  recordingControls: document.getElementById('recordingControls'),
  pauseBtn: document.getElementById('pauseBtn'),
  stopBtn: document.getElementById('stopBtn'),
  uploadProgress: document.getElementById('uploadProgress'),
  progressFill: document.getElementById('progressFill'),
  progressText: document.getElementById('progressText'),
  successSection: document.getElementById('successSection'),
  videoTitle: document.getElementById('videoTitle'),
  videoLink: document.getElementById('videoLink'),
  newRecordingBtn: document.getElementById('newRecordingBtn'),
  logoutLink: document.getElementById('logoutLink'),
  taxCaptureAgency: document.getElementById('taxCaptureAgency'),
  taxCaptureEventCount: document.getElementById('taxCaptureEventCount'),
  taxCaptureRequestCount: document.getElementById('taxCaptureRequestCount'),
  taxCaptureNavigationCount: document.getElementById('taxCaptureNavigationCount'),
  taxCaptureStateLabel: document.getElementById('taxCaptureStateLabel'),
  taxCaptureStatus: document.getElementById('taxCaptureStatus'),
  taxCaptureStartBtn: document.getElementById('taxCaptureStartBtn'),
  taxCaptureStopBtn: document.getElementById('taxCaptureStopBtn'),
  taxCaptureExportBtn: document.getElementById('taxCaptureExportBtn'),
  taxCaptureClearBtn: document.getElementById('taxCaptureClearBtn'),
}

// Track if task came from web app (should not be overridden by dropdown)
let taskFromWebApp = false

// Initialize
document.addEventListener('DOMContentLoaded', async () => {
  // Check for pending task from web app FIRST (before connect)
  const { pendingTaskId } = await chrome.storage.local.get(['pendingTaskId'])
  if (pendingTaskId) {
    console.log('[Zao Recorder] Found pending task:', pendingTaskId)
    state.taskId = pendingTaskId
    taskFromWebApp = true
  }

  // Load saved config
  const config = await chrome.storage.local.get(['apiUrl', 'apiToken'])
  if (config.apiUrl) els.apiUrl.value = config.apiUrl
  if (config.apiToken) els.apiToken.value = config.apiToken

  // Check if already connected
  if (config.apiUrl && config.apiToken) {
    await connect(config.apiUrl, config.apiToken, true)
  }

  // Source buttons
  document.querySelectorAll('.source-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.source-btn').forEach(b => b.classList.remove('active'))
      btn.classList.add('active')
      state.source = btn.dataset.source
      // Show camera select only for 'both' mode
      updateCameraVisibility()
    })
  })

  // Device selection listeners
  els.micSelect.addEventListener('change', (e) => {
    state.micDeviceId = e.target.value
  })
  els.cameraSelect.addEventListener('change', (e) => {
    state.cameraDeviceId = e.target.value
  })

  // Event listeners
  els.connectBtn.addEventListener('click', handleConnect)
  els.startBtn.addEventListener('click', handleStart)
  els.pauseBtn.addEventListener('click', handlePause)
  els.stopBtn.addEventListener('click', handleStop)
  els.newRecordingBtn.addEventListener('click', handleNewRecording)
  els.logoutLink.addEventListener('click', handleLogout)
  els.taxCaptureAgency.addEventListener('change', handleTaxCaptureAgencyChange)
  els.taxCaptureStartBtn.addEventListener('click', handleTaxCaptureStart)
  els.taxCaptureStopBtn.addEventListener('click', handleTaxCaptureStop)
  els.taxCaptureExportBtn.addEventListener('click', handleTaxCaptureExport)
  els.taxCaptureClearBtn.addEventListener('click', handleTaxCaptureClear)

  // Listen for messages from background
  chrome.runtime.onMessage.addListener(handleMessage)

  // Check for active recording
  const recordingState = await chrome.storage.local.get(['recording', 'startTime'])
  if (recordingState.recording) {
    state.recording = true
    state.startTime = recordingState.startTime
    showRecordingUI()
  }

  await loadTaxFlowCaptureState()
})

async function connect(url, token, silent = false) {
  try {
    const response = await fetch(`${url}/api/videos/folders`, {
      headers: { 'Authorization': `Bearer ${token}`, 'Accept': 'application/json' }
    })

    if (!response.ok) throw new Error('Invalid credentials')

    state.connected = true
    state.apiUrl = url
    state.apiToken = token

    await chrome.storage.local.set({ apiUrl: url, apiToken: token })

    updateStatus('connected', 'Connected')
    els.authSection.classList.add('hidden')
    els.recordSection.classList.remove('hidden')

    await loadTasks()
    await loadDevices()
    updateCameraVisibility()
  } catch (e) {
    state.connected = false
    if (!silent) showError('Connection failed. Check your URL and token.')
    updateStatus('disconnected', 'Not connected')
  }
}

async function loadDevices() {
  try {
    // Request permissions first to get device labels
    await navigator.mediaDevices.getUserMedia({ audio: true, video: true }).then(s => {
      s.getTracks().forEach(t => t.stop())
    }).catch(() => {})

    const devices = await navigator.mediaDevices.enumerateDevices()

    // Clear existing options
    while (els.micSelect.firstChild) els.micSelect.removeChild(els.micSelect.firstChild)
    while (els.cameraSelect.firstChild) els.cameraSelect.removeChild(els.cameraSelect.firstChild)

    // Add "none" options
    const noMic = document.createElement('option')
    noMic.value = ''
    noMic.textContent = 'No microphone'
    els.micSelect.appendChild(noMic)

    const noCam = document.createElement('option')
    noCam.value = ''
    noCam.textContent = 'No camera overlay'
    els.cameraSelect.appendChild(noCam)

    // Add devices
    devices.forEach(device => {
      const option = document.createElement('option')
      option.value = device.deviceId

      if (device.kind === 'audioinput') {
        option.textContent = device.label || `Microphone ${els.micSelect.length}`
        els.micSelect.appendChild(option)
        // Select first mic by default
        if (els.micSelect.length === 2) {
          els.micSelect.value = device.deviceId
          state.micDeviceId = device.deviceId
        }
      } else if (device.kind === 'videoinput') {
        option.textContent = device.label || `Camera ${els.cameraSelect.length}`
        els.cameraSelect.appendChild(option)
        // Select first camera by default
        if (els.cameraSelect.length === 2) {
          els.cameraSelect.value = device.deviceId
          state.cameraDeviceId = device.deviceId
        }
      }
    })

    console.log('[Zao Recorder] Loaded devices:', { mics: els.micSelect.length - 1, cameras: els.cameraSelect.length - 1 })
  } catch (e) {
    console.error('Failed to load devices:', e)
  }
}

function updateCameraVisibility() {
  // Only show camera select when source is 'both' or 'camera'
  if (state.source === 'both' || state.source === 'camera') {
    els.cameraSelectGroup.style.display = 'block'
  } else {
    els.cameraSelectGroup.style.display = 'none'
  }
}

async function handleConnect() {
  const url = els.apiUrl.value.trim().replace(/\/$/, '')
  const token = els.apiToken.value.trim()

  if (!url || !token) {
    showError('Please enter both URL and token')
    return
  }

  els.connectBtn.disabled = true
  els.connectBtn.textContent = 'Connecting...'

  await connect(url, token)

  els.connectBtn.disabled = false
  els.connectBtn.textContent = 'Connect'
}

async function loadTasks() {
  try {
    const response = await fetch(`${state.apiUrl}/api/videos/tasks`, {
      headers: { 'Authorization': `Bearer ${state.apiToken}`, 'Accept': 'application/json' }
    })

    if (response.ok) {
      const data = await response.json()
      const tasks = data.tasks || data

      // Clear existing options using safe DOM methods
      while (els.taskSelect.firstChild) {
        els.taskSelect.removeChild(els.taskSelect.firstChild)
      }

      // Add default option
      const defaultOption = document.createElement('option')
      defaultOption.value = ''
      defaultOption.textContent = 'No task (general recording)'
      els.taskSelect.appendChild(defaultOption)

      // Add task options
      tasks.forEach(task => {
        const option = document.createElement('option')
        option.value = task.id
        const projectName = task.project?.name || task.project
        option.textContent = projectName ? `[${projectName}] ${task.title}` : task.title
        els.taskSelect.appendChild(option)
      })

      // Select pending task if set
      if (state.taskId) {
        console.log('[Zao Recorder] Selecting task:', state.taskId, 'Available options:', Array.from(els.taskSelect.options).map(o => o.value))
        els.taskSelect.value = String(state.taskId)
        console.log('[Zao Recorder] Selected value:', els.taskSelect.value)
        // Clear pending task from storage
        chrome.storage.local.remove(['pendingTaskId'])

        // Don't auto-start - let user configure mic/camera first
        // The popup is shown so user can make selections before starting
      }
    }
  } catch (e) {
    console.error('Failed to load tasks:', e)
  }
}

async function handleStart() {
  // Prevent double-starting
  if (state.recording || state.startingRecording) {
    console.log('[Zao Recorder] Already recording or starting, ignoring')
    return
  }
  state.startingRecording = true
  els.startBtn.disabled = true
  els.startBtn.textContent = 'Starting...'
  console.log('[Zao Recorder] handleStart called')
  hideError()
  // Only use dropdown value if task didn't come from web app
  if (!taskFromWebApp) {
    state.taskId = els.taskSelect.value || null
  }
  console.log('[Zao Recorder] Using taskId:', state.taskId, 'fromWebApp:', taskFromWebApp)

  console.log('[Zao Recorder] Sending startRecording message:', {
    source: state.source,
    taskId: state.taskId,
    micDeviceId: state.micDeviceId,
    cameraDeviceId: state.cameraDeviceId,
  })

  // Send message to background to start recording
  chrome.runtime.sendMessage({
    action: 'startRecording',
    source: state.source,
    taskId: state.taskId,
    apiUrl: state.apiUrl,
    apiToken: state.apiToken,
    micDeviceId: state.micDeviceId,
    cameraDeviceId: state.cameraDeviceId,
  })

  // Reset web app flag after starting (subsequent recordings use dropdown)
  taskFromWebApp = false
}

function handlePause() {
  if (state.paused) {
    chrome.runtime.sendMessage({ action: 'resumeRecording' })
    els.pauseBtn.textContent = 'Pause'
    // Fold the just-ended pause into the running total so the timer reflects
    // recorded (not wall-clock) duration.
    if (state.pausedAt) {
      state.totalPausedMs += Date.now() - state.pausedAt
      state.pausedAt = null
    }
    state.paused = false
  } else {
    chrome.runtime.sendMessage({ action: 'pauseRecording' })
    els.pauseBtn.textContent = 'Resume'
    state.pausedAt = Date.now()
    state.paused = true
  }
}

function handleStop() {
  chrome.runtime.sendMessage({ action: 'stopRecording' })
  clearInterval(state.timerInterval)
  els.recordingControls.classList.add('hidden')
  els.timer.classList.add('hidden')
  els.uploadProgress.classList.remove('hidden')
  updateStatus('uploading', 'Uploading...')
}

function handleNewRecording() {
  els.successSection.classList.add('hidden')
  els.recordSection.classList.remove('hidden')
  els.startBtn.classList.remove('hidden')
  els.uploadProgress.classList.add('hidden')
  updateStatus('connected', 'Connected')
}

async function loadTaxFlowCaptureState() {
  const { taxFlowCaptureState } = await chrome.storage.local.get(['taxFlowCaptureState'])
  state.taxFlowCapture = {
    ...state.taxFlowCapture,
    ...(taxFlowCaptureState || {}),
  }

  if (state.taxFlowCapture.agency) {
    els.taxCaptureAgency.value = state.taxFlowCapture.agency
  }

  updateTaxCaptureUI()
}

function handleTaxCaptureAgencyChange() {
  state.taxFlowCapture.agency = els.taxCaptureAgency.value
}

function handleTaxCaptureStart() {
  hideError()
  chrome.runtime.sendMessage({
    action: 'startTaxFlowCapture',
    agency: els.taxCaptureAgency.value,
  })
}

function handleTaxCaptureStop() {
  hideError()
  chrome.runtime.sendMessage({
    action: 'stopTaxFlowCapture',
  })
}

function handleTaxCaptureExport() {
  hideError()
  chrome.runtime.sendMessage({
    action: 'exportTaxFlowCapture',
  })
}

function handleTaxCaptureClear() {
  hideError()
  chrome.runtime.sendMessage({
    action: 'clearTaxFlowCapture',
  })
}

async function handleLogout() {
  await chrome.storage.local.remove(['apiUrl', 'apiToken'])
  state.connected = false
  state.apiUrl = ''
  state.apiToken = ''
  els.apiToken.value = ''
  els.authSection.classList.remove('hidden')
  els.recordSection.classList.add('hidden')
  els.successSection.classList.add('hidden')
  updateStatus('disconnected', 'Not connected')
}

function handleMessage(message) {
  switch (message.type) {
    case 'recordingStarted':
      state.recording = true
      state.startingRecording = false
      state.startTime = Date.now()
      state.paused = false
      state.pausedAt = null
      state.totalPausedMs = 0
      showRecordingUI()
      break

    case 'recordingError':
      state.startingRecording = false
      els.startBtn.disabled = false
      els.startBtn.textContent = 'Start Recording'
      showError(message.error)
      updateStatus('connected', 'Connected')
      break

    case 'uploadProgress':
      els.progressFill.style.width = `${message.progress}%`
      els.progressText.textContent = message.status || `Uploading... ${Math.round(message.progress)}%`
      break

    case 'uploadComplete':
      state.recording = false
      els.uploadProgress.classList.add('hidden')
      els.recordSection.classList.add('hidden')
      els.successSection.classList.remove('hidden')
      els.videoTitle.textContent = message.video.title
      els.videoLink.href = message.shareUrl || `${state.apiUrl}/videos/${message.video?.id}`
      updateStatus('connected', 'Connected')
      break

    case 'uploadError':
      showError(`Upload failed: ${message.error}`)
      els.uploadProgress.classList.add('hidden')
      els.startBtn.classList.remove('hidden')
      updateStatus('connected', 'Connected')
      break

    case 'taxFlowCaptureStarted':
    case 'taxFlowCaptureUpdated':
    case 'taxFlowCaptureStopped':
    case 'taxFlowCaptureCleared':
      state.taxFlowCapture = {
        ...state.taxFlowCapture,
        ...(message.state || {}),
      }
      if (state.taxFlowCapture.agency) {
        els.taxCaptureAgency.value = state.taxFlowCapture.agency
      }
      updateTaxCaptureUI()
      break

    case 'taxFlowCaptureExported':
      els.taxCaptureStatus.textContent = `Capture exported as ${message.filename}. Review it, then point me to the JSON file.`
      break

    case 'taxFlowCaptureError':
      showError(message.error)
      break
  }
}

function showRecordingUI() {
  els.startBtn.classList.add('hidden')
  els.recordingControls.classList.remove('hidden')
  els.timer.classList.remove('hidden')
  updateStatus('recording', 'Recording')

  // Start timer. Freezes while paused and excludes paused time, so it tracks
  // the actual recorded length.
  state.timerInterval = setInterval(() => {
    if (state.paused) return
    const elapsed = Math.floor((Date.now() - state.startTime - state.totalPausedMs) / 1000)
    const mins = Math.floor(elapsed / 60).toString().padStart(2, '0')
    const secs = (elapsed % 60).toString().padStart(2, '0')
    els.timer.textContent = `${mins}:${secs}`
  }, 1000)
}

function updateStatus(status, text) {
  els.statusDot.className = 'status-dot'
  if (status === 'connected') els.statusDot.classList.add('connected')
  if (status === 'recording') els.statusDot.classList.add('recording')
  els.statusText.textContent = text
}

function showError(msg) {
  els.errorMsg.textContent = msg
  els.errorMsg.classList.remove('hidden')
}

function hideError() {
  els.errorMsg.classList.add('hidden')
}

function updateTaxCaptureUI() {
  const capture = state.taxFlowCapture

  els.taxCaptureEventCount.textContent = String(capture.eventCount || 0)
  els.taxCaptureRequestCount.textContent = String(capture.requestCount || 0)
  els.taxCaptureNavigationCount.textContent = String(capture.navigationCount || 0)
  els.taxCaptureStateLabel.textContent = capture.active ? 'Recording' : capture.latestAvailable ? 'Ready' : 'Idle'

  els.taxCaptureStartBtn.disabled = !!capture.active
  els.taxCaptureStopBtn.disabled = !capture.active
  els.taxCaptureExportBtn.disabled = !!capture.active || !capture.latestAvailable
  els.taxCaptureClearBtn.disabled = !!capture.active || (!capture.latestAvailable && (capture.eventCount || 0) === 0)

  if (capture.active) {
    els.taxCaptureStatus.textContent = capture.currentUrl
      ? `Recording ${capture.agency === 'oregon_dor' ? 'Oregon' : 'IRS'} flow on ${capture.currentUrl}`
      : 'Recording current tab. Keep the login flow in this tab until export.'
    return
  }

  if (capture.latestAvailable) {
    els.taxCaptureStatus.textContent = `Latest capture is ready. Export the JSON and send me the file path so I can inspect the real login flow.`
    return
  }

  els.taxCaptureStatus.textContent = 'Open the IRS or Oregon login flow in one browser tab, start capture, complete the login, then stop and export the JSON.'
}
