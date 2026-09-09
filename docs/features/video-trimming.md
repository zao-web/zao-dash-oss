# Video Trimming Feature

Non-destructive video trimming with version history, visual timeline, and client-side preview.

## Overview

Video owners can trim videos without destroying the original. Each trim creates a new version while preserving all previous versions. Users can switch between versions at any time.

## User Guide

### Trimming a Video

1. Navigate to a video you own
2. Click the **Trim Video** button in the share buttons section
3. Use the visual timeline to set trim points:
   - Drag the left handle to set the start point
   - Drag the right handle to set the end point
   - Use the +1s/-1s buttons for fine adjustment
   - Enter times manually in the input fields
4. Click **Preview** to preview the trim in the video player
5. Click **Save as New Version** to queue the trim

### Version History

- Access the **Versions** tab in the sidebar to see all versions
- The original video is always preserved as version 1
- Click **Activate** to switch which version viewers see
- Click **Delete** to remove non-current, non-original versions

### Notifications

- Notification when trim starts processing
- Notification when trim completes successfully
- Notification if trim fails

## Technical Details

### Database Schema

**video_versions table:**
- `video_id` - Foreign key to videos
- `version_number` - Sequential version number (1 = original)
- `storage_path` - Path to trimmed video file
- `storage_disk` - Storage disk (local/s3/r2)
- `duration` - Duration in seconds
- `file_size` - File size in bytes
- `trim_start_seconds` - Start point of trim (null = untrimmed)
- `trim_end_seconds` - End point of trim (null = untrimmed)
- `status` - pending/processing/ready/failed
- `is_current` - Whether this is the active version
- `created_by` - User who created the version
- `metadata` - JSON blob with processing details

**videos table additions:**
- `has_versions` - Boolean indicating if versions exist
- `current_version` - Current active version number
- `original_storage_path` - Backup of original file path

### FFmpeg Processing

Frame-accurate trimming with re-encoding:

```bash
ffmpeg -i input.mp4 -ss {start} -to {end} \
    -c:v libx264 -preset fast -crf 23 \
    -c:a aac -b:a 128k \
    -movflags +faststart \
    output.mp4
```

Thumbnail generation for timeline:

```bash
ffmpeg -i input.mp4 -vf "fps=1/{interval},scale=120:-1" -vframes 10 thumb_%d.jpg
```

### API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/videos/{uuid}/thumbnails` | Get timeline thumbnails |
| POST | `/api/videos/{uuid}/trim` | Queue trim operation |
| POST | `/api/videos/{uuid}/trim/preview` | Get preview URL params |
| GET | `/api/videos/{uuid}/versions` | List all versions |
| POST | `/api/videos/{uuid}/versions/{id}/activate` | Activate version |
| DELETE | `/api/videos/{uuid}/versions/{id}` | Delete version |

### Queue Processing

Trimming is processed via the `TrimVideoJob` queue job:
- Max 3 retries with 60s backoff
- 10 minute timeout
- Processes one trim at a time per video
- Supports both local and cloud storage (S3/R2)

## Configuration

FFmpeg paths are configured in `config/services.php`:

```php
'ffmpeg' => [
    'ffmpeg_path' => env('FFMPEG_PATH', 'ffmpeg'),
    'ffprobe_path' => env('FFPROBE_PATH', 'ffprobe'),
],
```

## Limitations

- Cannot trim videos that are still processing
- Cannot delete the current active version
- Cannot delete the original version (v1)
- Minimum trim duration is 1 second
