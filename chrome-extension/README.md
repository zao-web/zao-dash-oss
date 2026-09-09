# Zao Dash Recorder Chrome Extension

Screen and camera recording extension that uploads directly to Zao Dash.

## Features

- Screen, camera, or both recording
- Task linking for auto-organization
- Chunked upload for large files
- Progress tracking
- Shareable links on completion
- IRS/Oregon tax portal flow capture with masked export JSON

## Installation (Development)

1. Open Chrome and go to `chrome://extensions/`
2. Enable "Developer mode" (toggle in top right)
3. Click "Load unpacked"
4. Select the `chrome-extension` directory

## Setup

1. Click the extension icon in Chrome toolbar
2. Enter your Zao Dash URL (e.g., `http://localhost:8000` or `https://app.zaodash.com`)
3. Enter your Personal Access Token (generate from Settings > API Tokens)
4. Click "Connect"

## Usage

### From Extension Popup

1. Select recording source: Screen, Camera, or Both
2. (Optional) Select a task to link the video to
3. Click "Start Recording"
4. Grant screen capture permission when prompted
5. Record your video
6. Click "Stop" when done
7. Video uploads automatically
8. Copy the share link

### From Tasks Page

1. Open a task detail modal in Zao Dash
2. Click "Record Video" button
3. Extension popup opens with task pre-selected
4. Record and upload

### Tax Portal Flow Capture

Use this when you need to capture the real IRS.gov or Oregon Revenue Online login flow for automation work.

1. Open the IRS or Oregon login flow in one normal browser tab.
2. Open the extension popup.
3. In `Tax Portal Flow Capture`, pick `IRS.gov / ID.me` or `Oregon Revenue Online`.
4. Click `Start Capture`.
5. Complete the login flow in that same tab.
6. Click `Stop`.
7. Click `Export JSON`.
8. Share the exported file path with the agent.

The exported JSON captures navigation, sanitized request metadata, masked field metadata, and page-context checkpoints. It does not capture passwords, cookies, typed values, or query-string values.

## Icons

Replace the placeholder icons in `icons/` with your own:
- `icon16.png` - 16x16 pixels
- `icon48.png` - 48x48 pixels
- `icon128.png` - 128x128 pixels

## Technical Notes

### Manifest V3

Uses Chrome's Manifest V3 with:
- Service worker background script
- Offscreen document for MediaRecorder (required in MV3)
- Desktop capture API for screen recording

### Permissions

- `storage` - Save API credentials
- `tabs` - Get active tab for capture
- `activeTab` - Interact with current tab
- `desktopCapture` - Screen capture
- `offscreen` - MediaRecorder in service worker
- `downloads` - Export masked tax portal capture JSON
- `webNavigation` - Capture page-level navigation during tax portal flows
- `webRequest` - Capture sanitized request metadata during tax portal flows

## Tax Portal Flow Export

The tax portal capture export is designed for bridge-worker discovery, not replay.

- Included:
  - page navigation
  - sanitized request URLs without query-string values
  - query parameter names only
  - masked field metadata
  - MFA checkpoints and page headings
- Excluded:
  - passwords
  - typed input values
  - cookies
  - local/session storage
  - raw request/response bodies

### API Endpoints Used

- `GET /api/videos/folders` - Verify connection
- `GET /api/videos/tasks` - Load task list
- `POST /api/videos/upload` - Single file upload
- `POST /api/videos/upload/init` - Initialize chunked upload
- `POST /api/videos/upload/chunk` - Upload chunk
- `POST /api/videos/upload/finalize` - Complete upload

## Troubleshooting

**"Failed to start recording"**
- Ensure screen capture permission was granted
- Try selecting a different screen/window

**"Upload failed"**
- Check API token is valid
- Verify network connection
- Check server logs for errors

**Extension not connecting**
- Verify URL format (include protocol, no trailing slash)
- Generate a new API token
- Check CORS settings on server
