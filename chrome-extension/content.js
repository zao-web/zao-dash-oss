// Content script for Zao Dash Recorder
// Bridges communication between the web app and the extension

console.log('[Zao Recorder] Content script loaded on:', window.location.href)

// Listen for messages from the web app
window.addEventListener('message', (event) => {
  // Only accept messages from the same origin
  if (event.source !== window) return

  console.log('[Zao Recorder] Received message:', event.data)

  // Handle recording request from web app
  if (event.data?.type === 'ZAO_RECORD_VIDEO') {
    console.log('[Zao Recorder] Recording request for task:', event.data.taskId)
    chrome.runtime.sendMessage({
      action: 'webAppRecordRequest',
      taskId: event.data.taskId,
    })
  }
})

// Listen for messages from the extension
chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  console.log('[Zao Recorder] Chrome message received:', message)

  // Handle overlay messages - call overlay.js functions
  if (message.type === 'showRecordingOverlay') {
    console.log('[Zao Recorder] Triggering overlay creation')
    if (typeof createOverlay === 'function') {
      createOverlay(message.showCamera, message.cameraDeviceId)
    } else {
      console.error('[Zao Recorder] createOverlay function not available')
    }
  } else if (message.type === 'hideRecordingOverlay') {
    console.log('[Zao Recorder] Triggering overlay removal')
    if (typeof removeOverlay === 'function') {
      removeOverlay()
    }
  }

  // Forward relevant messages to the web app
  if (message.type === 'recordingComplete') {
    window.postMessage({
      type: 'ZAO_VIDEO_RECORDED',
      video: message.video,
    }, '*')
  }
})

// Notify the page that the extension is installed
window.postMessage({ type: 'ZAO_EXTENSION_READY' }, '*')
console.log('[Zao Recorder] Extension ready message sent')
