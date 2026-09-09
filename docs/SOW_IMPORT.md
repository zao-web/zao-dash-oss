# SOW Import Feature

Create a full project hierarchy from a Statement of Work document using AI-powered extraction.

## Overview

The SOW Import feature allows you to upload one or more project documents (SOW, discovery docs, requirements) and automatically create:

- **Client** (with contacts)
- **Project** (with type, budget, description)
- **Milestones** (as epic/phase groupings)
- **Tasks** (with subtasks, priorities, and hour estimates)

## How to Use

### 1. Open the Import Modal

- Press `Cmd+K` to open the command palette
- Type "import sow" or "statement of work"
- Select "Import Statement of Work"

### 2. Upload Documents

- **PDF files**: Drag and drop or click to browse
- **Google Drive**: Click "Google Drive URL" and paste a link
- **Text**: Click "Paste Text" to paste content directly

You can upload up to 5 documents at once. When multiple documents are provided, the AI merges them into a single cohesive project structure.

### 3. Review Extracted Data

The AI extracts and displays:

- Client name, website, and description
- Contact information found in the documents
- Project name, type, budget, and description
- Milestones with nested tasks

All fields are editable. You can add, remove, or reorder milestones and tasks before confirming.

### 4. Confirm and Create

Review the summary counts and click "Create Project" to generate everything in your workspace. You'll be redirected to the new project page.

## Multi-Document Support

SOWs often come with companion documents. Upload them together for a richer result:

- The AI merges overlapping milestones and tasks
- Discovery document details enrich task descriptions
- Duplicate tasks across documents are deduplicated

## Supported Input Formats

| Format | Max Size | Notes |
|--------|----------|-------|
| PDF | 20MB | Text extracted via PDF parser |
| Google Drive | N/A | Requires Google account connection |
| Text paste | N/A | Direct text input |

## How Tasks Are Created

- Tasks are assigned to milestones based on the document structure
- Subtasks are created as separate tasks with a `[Parent Title]` prefix
- All tasks are created with `source: sow_import` for tracking
- Priority and hour estimates are extracted when mentioned in the document
- All tasks start with `pending` status

## AI Extraction Details

The feature uses Claude (Sonnet model) with a 180-second timeout. The AI is instructed to:

- Extract action-oriented task titles (starting with verbs)
- Group tasks into logical milestones/phases
- Include acceptance criteria in task descriptions
- Set priority based on document context
- Extract budget and hour estimates when mentioned

## Limitations

- PDF text extraction quality depends on the PDF format (scanned images are not supported)
- Google Drive access requires the user to have a connected Google account
- AI extraction may miss or misinterpret ambiguous content
- Very large documents may be truncated

## API Endpoints

### Parse Documents

```
POST /api/sow-import/parse
```

Accepts multipart form data with an array of documents. Returns structured JSON with client, contacts, project, and milestones.

### Confirm Import

```
POST /api/sow-import/confirm
```

Accepts JSON with the reviewed/edited data. Creates all entities in a database transaction and returns the new project ID.
