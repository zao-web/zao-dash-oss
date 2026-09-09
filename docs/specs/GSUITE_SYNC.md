# GSuite Sync (Gmail & Calendar)

Syncs emails and calendar events from Google Workspace for client communication tracking and meeting management.

## Overview

| Service | Sync Method | Data Stored | Watch Support |
|---------|-------------|-------------|---------------|
| Gmail | Pull + Push | Emails | Yes (Pub/Sub) |
| Calendar | Pull + Push | Events | Yes (Webhook) |
| Drive | Pull | Documents | Yes (Webhook) |

---

## Environment Variables

```bash
GOOGLE_CLIENT_ID=your-client-id
GOOGLE_CLIENT_SECRET=your-client-secret
GOOGLE_REDIRECT_URI=https://yourdomain.com/auth/google/callback
GOOGLE_PUBSUB_topic=projects/your-project/topics/gmail-notifications
```

---

## SyncGSuiteJob

Main job that syncs both Gmail and Calendar data.

### Running the Job

```bash
# Sync all connected users
php artisan tinker
>>> App\Jobs\SyncGSuiteJob::dispatch();

# Sync specific user
>>> App\Jobs\SyncGSuiteJob::dispatch(userId: 1);

# Full sync (more history)
>>> App\Jobs\SyncGSuiteJob::dispatch(fullSync: true);
```

### Scheduling

Add to `routes/console.php`:

```php
use App\Jobs\SyncGSuiteJob;

// Daily sync at 6am
Schedule::job(new SyncGSuiteJob())->dailyAt('06:00');

// Or every 4 hours for more frequent updates
Schedule::job(new SyncGSuiteJob())->everyFourHours();
```

### What Gets Synced

**Gmail:**
- Inbox messages from last 7 days (or 100 messages on full sync)
- Extracts: from, to, subject, body, date
- Auto-matches emails to clients by email domain
- Detects meeting transcripts from Google Meet

**Calendar:**
- Events from next 30 days
- Extracts: title, description, attendees, meet link
- Auto-matches to clients by attendee email
- Flags external (client) meetings

---

## Gmail Service

### Sync Emails

```php
use App\Services\Google\GmailService;

$gmail = app(GmailService::class);

// List recent messages
$messages = $gmail->listMessages($user, [
    'maxResults' => 50,
    'q' => 'after:2024/01/01',
]);

// Get full message
$message = $gmail->getMessage($user, $messageId);

// Sync and store to database
$email = $gmail->syncAndStoreEmail($user, $messageId);
```

### Watch for New Emails

```php
// Set up Gmail push notifications
$gmail->watchInbox($user);

// Stop watching
$gmail->stopWatch($user);
```

Push notifications require Google Cloud Pub/Sub setup.

### Email Model

```php
// app/Models/Email.php
Email::create([
    'google_message_id' => 'abc123',
    'thread_id' => 'thread456',
    'client_id' => 1,  // Auto-matched
    'from_address' => 'client@company.com',
    'from_name' => 'John Doe',
    'to_addresses' => ['you@example.com'],
    'subject' => 'Project Update',
    'body_text' => '...',
    'body_html' => '...',
    'received_at' => now(),
    'is_transcript' => false,  // true for Meet transcripts
]);
```

---

## Calendar Service

### Sync Events

```php
use App\Services\Google\CalendarService;

$calendar = app(CalendarService::class);

// Sync all upcoming events
$count = $calendar->syncEvents($user);

// Get upcoming client meetings
$meetings = $calendar->getUpcomingClientMeetings($user, hours: 24);
```

### Watch for Changes

```php
// Set up calendar webhook
$calendar->watchCalendar($user);
```

### CalendarEvent Model

```php
// app/Models/CalendarEvent.php
CalendarEvent::create([
    'google_event_id' => 'event123',
    'calendar_id' => 'primary',
    'client_id' => 1,  // Auto-matched from attendees
    'title' => 'Weekly Standup',
    'description' => '...',
    'location' => 'Google Meet',
    'start_at' => now(),
    'end_at' => now()->addHour(),
    'is_all_day' => false,
    'attendees' => [...],
    'meet_link' => 'https://meet.google.com/xxx',
    'is_client_meeting' => true,
    'status' => 'confirmed',
]);
```

---

## Client Matching

Emails and events are automatically matched to clients:

1. **Email matching**: Looks up `ClientContact` by email address
2. **Domain matching**: Falls back to matching by email domain
3. **Attendee matching**: For calendar events, checks all attendees

```php
// Internal domains (excluded from client matching)
$internalDomains = ['example.com', 'internal.example.com'];
```

---

## Watch Refresh

Gmail and Calendar watches expire and need periodic refresh:

- Gmail watch: Expires in ~7 days
- Calendar watch: Expires in ~7 days

`SyncGSuiteJob` automatically refreshes watches when they're expiring within 24 hours.

---

## Webhook Endpoints

```php
// routes/web.php (already configured)
POST /webhooks/google/gmail     # Gmail push notifications
POST /webhooks/google/calendar  # Calendar change notifications
POST /webhooks/google/drive     # Drive file changes
```

---

## Meeting Transcript Detection

The system automatically detects Google Meet transcripts:

```php
$isTranscript = str_contains($fromEmail, 'meet-recordings-noreply@google.com') ||
    str_contains($subject, 'Meeting transcript');
```

Transcripts can be processed by the MeetingParserAgent.

---

## Database Tables

### google_credentials

```sql
CREATE TABLE google_credentials (
    id BIGINT PRIMARY KEY,
    user_id BIGINT REFERENCES users(id),
    google_id VARCHAR(255),
    email VARCHAR(255),
    name VARCHAR(255),
    avatar VARCHAR(255),
    access_token TEXT,
    refresh_token TEXT,
    token_expires_at TIMESTAMP,
    scopes JSON,
    watch_expiration TIMESTAMP,        -- Gmail watch
    watch_resource_id VARCHAR(255),
    calendar_watch_expiration TIMESTAMP,
    calendar_watch_resource_id VARCHAR(255),
    is_active BOOLEAN DEFAULT true,
    last_synced_at TIMESTAMP,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### emails

```sql
CREATE TABLE emails (
    id BIGINT PRIMARY KEY,
    google_message_id VARCHAR(255) UNIQUE,
    thread_id VARCHAR(255),
    client_id BIGINT REFERENCES clients(id),
    from_address VARCHAR(255),
    from_name VARCHAR(255),
    to_addresses JSON,
    subject VARCHAR(255),
    body_text TEXT,
    body_html TEXT,
    received_at TIMESTAMP,
    is_transcript BOOLEAN DEFAULT false,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### calendar_events

```sql
CREATE TABLE calendar_events (
    id BIGINT PRIMARY KEY,
    google_event_id VARCHAR(255) UNIQUE,
    calendar_id VARCHAR(255),
    client_id BIGINT REFERENCES clients(id),
    title VARCHAR(255),
    description TEXT,
    location VARCHAR(255),
    start_at TIMESTAMP,
    end_at TIMESTAMP,
    is_all_day BOOLEAN DEFAULT false,
    attendees JSON,
    meet_link VARCHAR(255),
    is_client_meeting BOOLEAN DEFAULT false,
    status VARCHAR(50),
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

---

## Artisan Commands

```bash
# Check sync status
php artisan tinker
>>> GoogleCredential::pluck('last_synced_at', 'email');

# View recent emails
>>> Email::latest()->take(10)->get(['from_address', 'subject', 'received_at']);

# View upcoming client meetings
>>> CalendarEvent::where('is_client_meeting', true)
...     ->where('start_at', '>', now())
...     ->orderBy('start_at')
...     ->take(10)
...     ->get(['title', 'start_at', 'client_id']);
```

---

## Troubleshooting

### "invalid_grant" error
- Refresh token expired or revoked
- User needs to re-authenticate via Settings → Integrations

### Emails not syncing
- Check `last_synced_at` on GoogleCredential
- Verify scopes include `gmail.readonly`
- Run `SyncGSuiteJob::dispatch(userId: X)` manually

### Watch not receiving notifications
- Verify Pub/Sub topic is correctly configured
- Check Cloud Console for Pub/Sub errors
- Ensure webhook URL is publicly accessible

### Client not matched
- Add client contact with correct email
- Check `ClientContact` table for matching entries
