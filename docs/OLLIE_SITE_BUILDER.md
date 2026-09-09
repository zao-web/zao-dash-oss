# Ollie Site Builder

AI-powered WordPress site building using the Ollie theme. Analyzes briefs, migrates existing sites, and generates complete WordPress sites with patterns and custom blocks.

---

## Getting Started

### Entry Point: `/ollie` in the Zao Dashboard

1. Click **Ollie** in the sidebar navigation
2. Choose your source:
   - **Upload PDF**: Product brief, brand guidelines document
   - **Paste Text**: Copy/paste brief content directly
   - **Site URL**: Existing site to migrate (WordPress, Joomla, static)
   - **GitHub Repo**: Any codebase to analyze and convert to WordPress

3. Follow the 5-step wizard:
   - **Step 1**: Upload/enter source material
   - **Step 2**: Review extracted requirements (project name, colors, pages)
   - **Step 3**: Configure design system (base style, fonts, spacing)
   - **Step 4**: Select Ollie patterns for each page
   - **Step 5**: Execute build and download child theme

---

## What It Does

| Input | Process | Output |
|-------|---------|--------|
| Product Brief (PDF/text) | Extract requirements → Map to patterns | Ollie child theme |
| Live Site URL | Crawl → Analyze structure → Map content | Migrated pages with patterns |
| GitHub Repo | Deep code analysis → Map to WordPress | Migration plan + generated code |

---

## Architecture

### Agent Chain

```
ollie-brief-analyzer (Opus) - Orchestrator
    ├── ollie-design-system (Sonnet) → theme.json generation
    ├── ollie-page-builder (Sonnet) → pattern composition
    ├── ollie-migration (Opus) → WP/Joomla content extraction
    ├── ollie-code-migration (Opus) → GitHub repo analysis
    └── ollie-block-creator (Opus) → custom block development
```

### Tools

| Tool | Purpose |
|------|---------|
| `ollie_parse_brief` | Extract requirements from PDFs, Google Docs, text |
| `ollie_analyze_site` | Crawl and analyze existing websites |
| `ollie_analyze_repo` | Deep GitHub repo analysis (any framework) |
| `ollie_generate_theme_json` | Create theme.json with brand colors |
| `ollie_list_patterns` | Access 60+ Ollie patterns |
| `ollie_compose_page` | Combine patterns into pages |
| `ollie_create_project` | Initialize child theme workspace |

---

## API Reference

### Create Project
```bash
POST /api/ollie/projects
{
  "name": "Acme Corp",
  "type": "new|migration|redesign",
  "environment": "staging|production"
}
```

### Parse Brief
```bash
# File upload
POST /api/ollie/parse-brief
Content-Type: multipart/form-data
file: [PDF]

# Text content
POST /api/ollie/parse-brief
{ "content": "Brief text..." }
```

### Analyze Live Site
```bash
POST /api/ollie/analyze-site
{
  "url": "https://existing-site.com",
  "depth": 2,
  "extract_content": true
}
```

### Analyze GitHub Repository
```bash
POST /api/ollie/analyze-repo
{
  "repo_url": "https://github.com/owner/repo",
  "branch": "main",
  "depth": "deep",
  "focus_paths": ["src/", "lib/"]
}
```

**Response:**
```json
{
  "platform": {
    "type": "laravel|joomla|drupal|nextjs|wordpress|custom",
    "framework": "Laravel",
    "confidence": 95
  },
  "functionality": {
    "routes": [...],
    "components": [...],
    "hooks": [...],
    "api_endpoints": [...],
    "database_tables": [...]
  },
  "wordpress_mapping": {
    "blocks": [...],
    "patterns": [...],
    "plugins_needed": [...]
  },
  "recommendations": {
    "approach": "Moderate migration - some custom blocks needed",
    "effort_estimate": "Medium (1-2 weeks)",
    "priority_items": [...],
    "next_steps": [...]
  }
}
```

### Generate Theme
```bash
POST /api/ollie/generate-theme
{
  "project_id": "acme-abc123",
  "colors": {
    "primary": "#3B82F6",
    "secondary": "#1E1E26"
  },
  "base_style": "agency"
}
```

### List Patterns
```bash
GET /api/ollie/patterns
GET /api/ollie/patterns?category=heroes
GET /api/ollie/patterns?search=testimonial
```

### Compose Page
```bash
POST /api/ollie/compose-page
{
  "project_id": "acme-abc123",
  "page_title": "About Us",
  "page_slug": "about",
  "patterns": [
    "ollie/hero-light",
    "ollie/team-members",
    "ollie/testimonials-and-logos"
  ]
}
```

### Download Project
```bash
GET /api/ollie/projects/{projectId}/download
```

---

## Pattern Library

60+ patterns across categories:

| Category | Examples |
|----------|----------|
| Heroes | hero-dark, hero-light, hero-call-to-action-buttons |
| Headers | header-dark, header-light, header-with-banner |
| Footers | footer-dark, footer-light, footer-centered |
| Features | feature-boxes-with-icon, features-with-emojis |
| Cards | card-pricing, card-testimonial, card-blog-post |
| Testimonials | testimonials-and-logos, single-testimonial |
| Pricing | pricing-table, pricing-3-column |
| CTAs | text-call-to-action, card-big-text-call-to-action |
| Blog | blog-post-columns, post-loop-grid |
| Pages | page-home, page-about, page-pricing |

---

## Style Variations

| Style | Colors | Character |
|-------|--------|-----------|
| Default | Purple (#5344F4) | Clean, modern |
| Agency | Green (#198754) | Bold, professional |
| Creator | Orange/warm | Playful, energetic |
| Startup | Blue | Tech-forward |
| Studio | Neutral | Minimal, refined |

---

## Supported Platforms (Code Migration)

The repo analyzer can understand and map:

| Source | Detection | WordPress Mapping |
|--------|-----------|-------------------|
| WordPress | wp-config.php, themes/, plugins/ | Direct pattern mapping |
| Joomla | configuration.php, components/ | Extension → Plugin mapping |
| Drupal | sites/, *.module | Content type → CPT |
| Laravel | artisan, routes/ | Routes → REST API |
| Next.js | next.config.js | Components → Blocks |
| Vue/Nuxt | nuxt.config.js | Components → Blocks |
| React | package.json + JSX | Components → Blocks |
| Static HTML | *.html | Structure → Patterns |

---

## Staging Deployment with SpinupWP

The Website Builder integrates with SpinupWP to automatically provision WordPress staging sites when no existing site is connected.

### How It Works

```
Project created → Agents build pages → Deploy phase
                                           ↓
                               ┌─────────────────────────┐
                               │ Has WordPressSite?      │
                               │   YES → Deploy directly │
                               │   NO  → SpinupWP auto-  │
                               │         provisions site │
                               └─────────────────────────┘
```

### What Gets Provisioned

When SpinupWP creates a staging site:

1. **Fresh WordPress install** on your SpinupWP server
2. **Ollie theme** installed and activated
3. **Ollie Pro plugin** (if configured)
4. **Core plugins**: Yoast SEO, LiteSpeed Cache
5. **Optimized settings**: Permalinks, timezone, comments disabled
6. **Application password** for REST API access
7. **Auto-linked** to your Website Project

### Configuration

Add to `.env`:
```bash
SPINUPWP_API_TOKEN=your_token_here
SPINUPWP_STAGING_DOMAIN=staging.yourdomain.com
OLLIE_PRO_PLUGIN_URL=https://... # Optional
```

### Agent Tool

The `SpinupWpProvisionSiteTool` is available to Website Builder agents:

```
Tool: spinup_wp_provision_site
Inputs:
  - project_id (required)
  - admin_email (required)
  - domain (optional, auto-generated)
  - install_ollie_pro (optional, default: false)
Output:
  - site_url, admin_url, credentials
```

See [SpinupWP Integration Spec](./specs/SPINUPWP_INTEGRATION.md) for full documentation.

---

## Approval Gates

| Environment | Behavior |
|-------------|----------|
| Staging | Autonomous execution |
| Production | Requires human approval |

Agents requiring approval:
- `ollie-brief-analyzer` (orchestrator decisions)
- `ollie-page-builder` (content creation)
- `ollie-migration` (content changes)
- `ollie-block-creator` (code generation)

---

## Project Output

Generated child theme structure:

```
ollie-projects/{project-id}/
├── manifest.json          # Project metadata
├── theme.json            # Design tokens (colors, fonts, spacing)
├── style.css             # Child theme header
├── functions.php         # Block/pattern registration
├── pages/
│   ├── home.html         # Page content (block markup)
│   ├── home.json         # Page metadata
│   ├── about.html
│   └── about.json
├── patterns/             # Custom patterns
├── blocks/               # Custom blocks (if needed)
└── assets/               # Images, fonts
```

---

## CLI Commands

```bash
# Sync agent definitions
php artisan agents:sync

# Clear caches
php artisan cache:clear

# Trigger agent manually
php artisan agents:run ollie-brief-analyzer --input='{"brief_content": "..."}'
```

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Tools not found | `php artisan cache:clear` |
| Patterns not loading | Check Ollie theme in ~/Downloads/ollie |
| Build fails | Check `storage/logs/laravel.log` |
| GitHub API rate limit | Add token in Settings → Integrations |
| PDF parsing fails | Install `smalot/pdfparser` via Composer |
| SpinupWP not configured | Add `SPINUPWP_API_TOKEN` to `.env` |
| No SpinupWP servers | Sync servers via API or Settings UI |
| Staging site provisioning slow | SpinupWP provisioning takes 2-5 minutes |

---

## See Also

- [SpinupWP Integration](./specs/SPINUPWP_INTEGRATION.md) - Staging site provisioning
- [Ollie Agents Spec](./specs/OLLIE_AGENTS.md) - Agent definitions
- [WordPress MCP Integration](./specs/WORDPRESS_MCP_INTEGRATION.md) - WordPress API
