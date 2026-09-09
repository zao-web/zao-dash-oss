# Video Recording & Sharing System

Loom-like video recording and sharing with AI transcription, action item extraction, and collaborative comments.

## Features

- Screen/camera recording via Chrome extension
- Chunked upload for large files
- Auto-organization by client/project folders (task-linked)
- Shareable links with optional password protection
- View analytics and engagement tracking
- **AI Transcription** via Hugging Face Whisper
- **AI Analysis** with Claude for summaries and action items
- **Auto-task creation** from extracted action items
- **Timestamped comments** with moderation for public viewers
- **Real-time notifications** via Laravel Reverb
- **Social previews** with Open Graph meta tags
- **Embeddable player** for iframe integration

## Architecture

### Database Tables

- `videos` - Video metadata, storage paths, sharing settings, transcript data
- `video_views` - View tracking with watch duration, completion, device info
- `video_comments` - Timestamped comments with moderation workflow

### Key Files

#### Models
- `app/Models/Video.php` - Video model with transcript accessors
- `app/Models/VideoView.php` - View tracking model
- `app/Models/VideoComment.php` - Comment model with moderation

#### Services
- `app/Services/Video/VideoService.php` - Upload, streaming, chunked upload
- `app/Services/Transcription/WhisperService.php` - Hugging Face Whisper integration

#### Jobs
- `app/Jobs/ProcessVideoJob.php` - Video processing (metadata, thumbnails)
- `app/Jobs/TranscribeVideoJob.php` - AI transcription pipeline
- `app/Jobs/AnalyzeVideoContentJob.php` - Claude AI analysis

#### Controllers
- `app/Http/Controllers/Api/VideoController.php` - API for Chrome extension
- `app/Http/Controllers/Api/VideoCommentController.php` - Comment management
- `app/Http/Controllers/PublicVideoController.php` - Public sharing routes

#### Events (Real-time)
- `app/Events/VideoProcessingCompleted.php` - Video ready
- `app/Events/VideoViewed.php` - New view recorded
- `app/Events/TranscriptionCompleted.php` - Transcript ready
- `app/Events/ActionItemsExtracted.php` - AI found action items
- `app/Events/DraftTasksCreated.php` - Tasks auto-created
- `app/Events/VideoCommentAdded.php` - New comment added

### Vue Pages

- `resources/js/Pages/Videos/Index.vue` - Video library with folder organization
- `resources/js/Pages/Videos/Player.vue` - Public player with transcript/comment sidebar
- `resources/js/Pages/Videos/PasswordPrompt.vue` - Password protection UI

## API Endpoints

### Authenticated (Chrome Extension)

```
GET    /api/videos              - List user's videos
GET    /api/videos/folders      - Get folder structure
GET    /api/videos/tasks        - Get available tasks for linking
POST   /api/videos/upload       - Single-file upload
POST   /api/videos/upload/init  - Initialize chunked upload
POST   /api/videos/upload/chunk - Upload chunk
POST   /api/videos/upload/finalize - Complete chunked upload
GET    /api/videos/{id}         - Video details
PUT    /api/videos/{id}         - Update video
DELETE /api/videos/{id}         - Delete video
POST   /api/videos/{id}/regenerate-token - New share link
POST   /api/videos/{id}/password - Set/remove password
GET    /api/videos/{id}/analytics - View analytics
```

### Video Comments (Authenticated)

```
GET    /api/videos/{id}/comments         - List comments (owners see pending)
GET    /api/videos/{id}/comments/pending - Pending moderation queue
GET    /api/videos/{id}/comments/markers - Timeline markers for scrubber
POST   /api/videos/{id}/comments         - Add comment
POST   /api/videos/{id}/comments/{id}/approve - Approve public comment
DELETE /api/videos/{id}/comments/{id}    - Delete comment
```

### Public (Sharing)

```
GET    /v/{token}               - View video page (SSR with OG tags)
POST   /v/{token}/verify-password - Verify password
GET    /v/{token}/stream        - Stream video (range requests)
GET    /v/{token}/thumbnail     - Get thumbnail image
POST   /v/{token}/view          - Record view start
POST   /v/{token}/ping          - Update watch progress
GET    /v/{token}/embed         - Embeddable player (iframe)
GET    /v/{token}/comments      - Approved comments only
GET    /v/{token}/comments/markers - Timeline markers
POST   /v/{token}/comments      - Submit comment (requires moderation)
```

## AI Transcription

Videos are automatically transcribed after upload using the Whisper API.

### Supported Providers

| Provider | Model | Cost | Notes |
|----------|-------|------|-------|
| OpenAI | whisper-1 | ~$0.006/min | Most reliable, requires API key |
| Groq | whisper-large-v3 | Free | Very fast, generous free tier |

### Pipeline

1. `ProcessVideoJob` completes (video ready)
2. `TranscribeVideoJob` dispatched (5s delay)
3. Audio extracted via FFmpeg (MP3, 64kbps mono)
4. Audio sent to Whisper API
5. Transcript stored with timestamped segments
6. `AnalyzeVideoContentJob` dispatched

### Configuration

```env
# Choose provider: 'openai' (default) or 'groq'
TRANSCRIPTION_PROVIDER=groq

# For OpenAI
OPENAI_API_KEY=sk-xxxxx

# For Groq (free)
GROQ_API_KEY=gsk_xxxxx
```

To get a free Groq API key: https://console.groq.com/keys

### Transcript Data

Stored in `videos` table:
- `transcript` (longtext) - Full text
- `transcript_segments` (JSON) - `[{start, end, text}]` for sync
- `transcript_language` (string) - Detected language
- `transcript_status` (enum) - pending/processing/completed/failed

## AI Analysis

After transcription, Claude analyzes the content.

### Features

- **Summary** - 2-3 sentence summary stored in `ai_summary`
- **Action Items** - Extracted with owner/deadline/priority
- **Auto-Task Creation** - Creates draft tasks if video has `project_id`

### Task Creation

Tasks created from videos have:
- `source: 'video'`
- `source_session_id: 'video:{video_id}'`
- `status: 'pending'` (requires review)

The `DraftTasksCreated` event notifies the owner to review.

## Video Comments

Viewers can leave timestamped comments that appear as markers on the video timeline.

### Comment Types

- `comment` - Standard text comment
- `marker` - Timeline marker (shorter, for annotations)
- `reaction` - Emoji reaction at timestamp

### Moderation

- **Authenticated users** - Comments auto-approved
- **Public viewers** - Must provide name, comments require owner approval
- Owners see pending queue and can approve/reject

### Timeline Markers

Comments with timestamps appear as dots on the video progress bar. Clicking a marker jumps to that position.

## Real-Time Notifications

Events broadcast via Laravel Reverb to the video owner's private channel.

### Events

| Event | Description |
|-------|-------------|
| `video.processing.completed` | Video finished processing |
| `video.viewed` | Someone is watching your video |
| `transcription.completed` | Transcript is ready |
| `action-items.extracted` | AI found action items |
| `draft-tasks.created` | Tasks auto-created from video |
| `video-comment.added` | New comment (may need moderation) |

### Frontend Integration

Events handled in `useVideoEvents.ts` composable, which shows toast notifications with action buttons.

## Social Sharing

Public videos have Open Graph meta tags for rich previews on social media.

### Meta Tags

```html
<meta property="og:type" content="video.other">
<meta property="og:title" content="{video.title}">
<meta property="og:description" content="{ai_summary or description}">
<meta property="og:image" content="{thumbnail_url}">
<meta property="og:video" content="{stream_url}">

<meta name="twitter:card" content="player">
<meta name="twitter:player" content="{embed_url}">
```

### Embed Player

Minimal player for iframe embedding:
```html
<iframe src="https://app.example.com/v/{token}/embed"
        width="640" height="360"
        frameborder="0" allowfullscreen>
</iframe>
```

Query params: `?autoplay=1&muted=1&loop=1`

Note: Password-protected videos cannot be embedded.

## Infrastructure Requirements

### FFmpeg

Required for video processing and audio extraction.

```bash
# macOS
brew install ffmpeg

# Ubuntu
apt install ffmpeg
```

Configure paths in `.env`:
```
FFMPEG_PATH=/usr/local/bin/ffmpeg
FFPROBE_PATH=/usr/local/bin/ffprobe
```

### Storage

Default: local disk. For production, configure S3/R2:
- Set `FILESYSTEM_DISK=s3` in `.env`
- Configure `AWS_*` credentials
- Videos stored at `videos/{user_id}/{YYYY}/{MM}/{uuid}.{ext}`

### Queue

Video processing and transcription run in background:
```bash
php artisan queue:work
```

For production, use Supervisor or Laravel Horizon.

### Laravel Reverb

Required for real-time notifications:
```bash
php artisan reverb:start
```

Configure in `.env`:
```
BROADCAST_DRIVER=reverb
REVERB_APP_KEY=your-app-key
REVERB_HOST=localhost
REVERB_PORT=8080
```

## Chrome Extension

Located at `/chrome-extension/`. See `chrome-extension/README.md` for full docs.

### Installation

1. Open `chrome://extensions/`
2. Enable "Developer mode"
3. Click "Load unpacked" and select the `chrome-extension` folder

### Features

- Screen, camera, or both recording
- Task linking from dropdown or task detail modal
- Chunked upload with progress
- Auto-generated share links

## View Analytics

Tracks:
- View count (total and unique via fingerprint)
- Watch duration and percentage
- Completion rate (>90% watched)
- Device type, referrer, country
- Daily view trends

Access analytics via `/api/videos/{id}/analytics` or video detail page.

## Artisan Commands

```bash
# Backfill missing duration/dimensions for cloud-stored videos
php artisan app:backfill-video-durations

# Backfill a specific video by UUID
php artisan app:backfill-video-durations --video=<uuid>

# Retry failed transcription
php artisan queue:retry all

# Check transcription queue
php artisan queue:monitor

# Clear cached video data
php artisan cache:forget video:{id}
```

### Backfilling Video Durations

Videos recorded via Chrome's MediaRecorder and stored on cloud storage (S3/R2) may be missing duration metadata. The `app:backfill-video-durations` command downloads each file to a temp directory, runs `ffprobe` to extract duration and dimensions, then cleans up. Requires `ffprobe` to be available on the server.

## Migrations

```
add_transcription_fields_to_videos_table
create_video_comments_table
add_video_to_task_source_enum
```

Run migrations:
```bash
php artisan migrate
```
