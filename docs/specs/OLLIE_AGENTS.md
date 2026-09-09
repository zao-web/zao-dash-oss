# Ollie Site Builder Agent System

Autonomous agents for building WordPress sites with the Ollie block theme based on product briefs, brand guidelines, and content migration requirements.

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                       OLLIE SITE BUILDER SYSTEM                             │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                    BRIEF ANALYZER (Orchestrator)                     │   │
│  ├─────────────────────────────────────────────────────────────────────┤   │
│  │  • Parses PDFs, Google Docs, emails                                 │   │
│  │  • Extracts brand guidelines, requirements, content                 │   │
│  │  • Creates project structure and delegates to specialized agents    │   │
│  │  • Model: Opus (complex reasoning)                                  │   │
│  └────────────────────────┬────────────────────────────────────────────┘   │
│                           │                                                 │
│           ┌───────────────┼───────────────┬───────────────┐                │
│           ▼               ▼               ▼               ▼                │
│  ┌─────────────┐ ┌─────────────┐ ┌─────────────┐ ┌─────────────┐          │
│  │   Design    │ │    Page     │ │  Migration  │ │   Block     │          │
│  │   System    │ │   Builder   │ │   Agent     │ │  Creator    │          │
│  │   Agent     │ │   Agent     │ │             │ │   Agent     │          │
│  ├─────────────┤ ├─────────────┤ ├─────────────┤ ├─────────────┤          │
│  │ theme.json  │ │ Pattern     │ │ WP/Joomla   │ │ Custom      │          │
│  │ Style vars  │ │ composition │ │ analysis    │ │ blocks for  │          │
│  │ Typography  │ │ Page layout │ │ Content     │ │ gaps in     │          │
│  │ Colors      │ │ Templates   │ │ extraction  │ │ core blocks │          │
│  └─────────────┘ └─────────────┘ └─────────────┘ └─────────────┘          │
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                         TOOL REGISTRY                                │   │
│  ├─────────────────────────────────────────────────────────────────────┤   │
│  │  Parse Brief │ Analyze Site │ Generate Theme │ Create Pattern │ ... │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                    APPROVAL & SAFETY LAYER                           │   │
│  ├─────────────────────────────────────────────────────────────────────┤   │
│  │  Staging: Autonomous │ Production: Human Approval Required          │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Agent Definitions

### 1. OllieBriefAnalyzerAgent (Orchestrator)

**Purpose:** Parse product briefs and orchestrate site building workflow.

| Property | Value |
|----------|-------|
| Model | opus |
| Trigger | manual |
| Budget | $15.00 |
| Approval | Yes (for production deployment) |

**Input Sources:**
- PDF documents (product briefs, brand guidelines)
- Google Docs (shared documents)
- Email content (client communications)
- Existing site URLs (for migration)

**Extracted Data:**
```json
{
  "project": {
    "name": "Client Site",
    "type": "migration|new|redesign",
    "environment": "staging|production"
  },
  "brand": {
    "colors": {
      "primary": "#5344F4",
      "secondary": "#1E1E26",
      "accent": "#e9e7ff"
    },
    "typography": {
      "headingFont": "Mona Sans",
      "bodyFont": "Mona Sans",
      "scale": "default|compact|spacious"
    },
    "logo": "url-or-base64",
    "voice": "professional|casual|technical"
  },
  "structure": {
    "pages": ["home", "about", "services", "contact"],
    "navigation": "standard|mega-menu|minimal"
  },
  "content": {
    "source": "provided|migrate|generate",
    "existingSiteUrl": "https://oldsite.com"
  },
  "integrations": ["forms", "analytics", "ecommerce"],
  "customRequirements": ["custom calculator widget", "video player"]
}
```

**Tools:**
- `parse_product_brief` - Extract structured data from documents
- `analyze_existing_site` - Crawl and analyze source sites
- `create_ollie_project` - Initialize child theme structure
- `assign_agent_task` - Delegate to specialized agents

---

### 2. OllieDesignSystemAgent

**Purpose:** Map brand guidelines to Ollie theme.json and create style variations.

| Property | Value |
|----------|-------|
| Model | sonnet |
| Trigger | chained (from BriefAnalyzer) |
| Budget | $5.00 |
| Approval | No (design tokens only) |

**Capabilities:**
- Generate custom `theme.json` with brand colors
- Create style variations matching brand identity
- Configure typography (using Ollie's Mona Sans variants)
- Set spacing scale appropriate to design aesthetic
- Configure shadow presets for brand mood

**Ollie Design Token Mapping:**

```json
{
  "colors": {
    "primary": "Brand color → buttons, accents",
    "primary-accent": "Light brand → hover states, backgrounds",
    "primary-alt": "Secondary brand → highlights",
    "primary-alt-accent": "Dark secondary → contrast elements",
    "main": "Text/contrast color",
    "main-accent": "Muted text color",
    "base": "Background color",
    "secondary": "Secondary text",
    "tertiary": "Subtle backgrounds",
    "border-light": "Light mode borders",
    "border-dark": "Dark mode borders"
  },
  "typography": {
    "primary": "Mona Sans (default)",
    "expanded": "Mona Sans Expanded (headlines)",
    "condensed": "Mona Sans Condensed (compact)",
    "narrow": "Mona Sans Narrow (emphasis)"
  },
  "spacing": {
    "small": "clamp(.5rem, 2.5vw, 1rem)",
    "medium": "clamp(1.5rem, 4vw, 2rem)",
    "large": "clamp(2rem, 5vw, 3rem)",
    "x-large": "clamp(3rem, 7vw, 5rem)",
    "xx-large": "clamp(4rem, 9vw, 7rem)",
    "xxx-large": "clamp(5rem, 12vw, 9rem)",
    "xxxx-large": "clamp(6rem, 14vw, 13rem)"
  }
}
```

**Tools:**
- `generate_theme_json` - Create customized theme.json
- `create_style_variation` - Generate named style variant
- `validate_color_contrast` - Ensure WCAG accessibility
- `preview_design_system` - Generate visual preview

---

### 3. OlliePageBuilderAgent

**Purpose:** Build pages using Ollie pattern library composition.

| Property | Value |
|----------|-------|
| Model | sonnet |
| Trigger | chained (from BriefAnalyzer) |
| Budget | $8.00 |
| Approval | Yes (creates content) |

**Pattern Library Categories:**

| Category | Patterns | Use Cases |
|----------|----------|-----------|
| Heroes | hero-dark, hero-light, hero-call-to-action-buttons | Page headers, landing sections |
| Headers | header-dark, header-light, header-*-with-banner | Site navigation |
| Footers | footer-dark, footer-light, footer-*-centered | Site footer |
| Cards | card-pricing-table, card-testimonial, card-call-to-action | Content blocks |
| Features | feature-boxes-with-icon, features-with-emojis | Service/product features |
| Testimonials | testimonials-and-logos, testimonial-highlight | Social proof |
| Pricing | pricing-table-3-column, pricing-table-with-testimonials | Pricing pages |
| Blog | blog-post-columns, post-loop-grid-default | Blog layouts |
| CTAs | text-call-to-action, card-call-to-action-with-buttons | Conversion sections |
| Pages | page-home, page-about, page-pricing, page-features | Full page templates |

**Page Composition Strategy:**
```
1. Analyze page purpose (home, about, services, etc.)
2. Select appropriate header pattern
3. Choose hero section based on content type
4. Stack content sections using appropriate patterns
5. Add CTA patterns strategically
6. Apply footer pattern
7. Customize text content
8. Apply brand colors via theme.json tokens
```

**Tools:**
- `list_ollie_patterns` - Get available patterns with metadata
- `compose_page` - Combine patterns into page template
- `create_page_template` - Register custom template
- `populate_pattern_content` - Replace placeholder text
- `preview_page` - Generate visual preview

---

### 4. OllieMigrationAgent

**Purpose:** Analyze and migrate content from existing sites.

| Property | Value |
|----------|-------|
| Model | opus |
| Trigger | chained (from BriefAnalyzer) |
| Budget | $10.00 |
| Approval | Yes (modifies content) |

**Supported Sources:**
- WordPress (REST API, WXR export, direct database)
- Joomla (database analysis, content extraction)
- Static HTML sites
- Squarespace/Wix (limited - HTML scraping)

**Migration Workflow:**

```
WordPress Migration:
1. Connect to source site via REST API or WXR
2. Extract content inventory (pages, posts, media)
3. Analyze theme structure and customizations
4. Map content to Ollie patterns
5. Identify custom functionality requiring block creation
6. Create migration manifest
7. Execute staged migration
8. Validate content integrity

Joomla Migration:
1. Clone Joomla codebase to workspace
2. Analyze database schema and content types
3. Extract articles, categories, modules
4. Map Joomla extensions to WordPress equivalents
5. Identify custom code requiring porting
6. Create block equivalents for custom modules
7. Generate content migration scripts
8. Execute migration in staging
```

**Content Mapping:**

| Source | Target Pattern |
|--------|---------------|
| Homepage hero | ollie/hero-* |
| About page | ollie/page-about sections |
| Team section | ollie/team-members |
| Testimonials | ollie/testimonials-* |
| Pricing tables | ollie/pricing-table-* |
| Blog posts | WordPress posts + ollie/blog-* |
| Contact forms | Core form blocks + plugin |

**Tools:**
- `analyze_wordpress_site` - Inventory WP site content
- `analyze_joomla_site` - Analyze Joomla codebase
- `extract_site_content` - Pull content from source
- `map_content_to_patterns` - Match content to Ollie patterns
- `generate_migration_manifest` - Create migration plan
- `execute_migration` - Run content migration
- `validate_migration` - Check migrated content

---

### 5. OllieBlockCreatorAgent

**Purpose:** Create custom blocks when core blocks are insufficient.

| Property | Value |
|----------|-------|
| Model | opus |
| Trigger | chained (from Migration or PageBuilder) |
| Budget | $8.00 |
| Approval | Yes (creates code) |

**When to Create Custom Blocks:**
- Complex interactive widgets (calculators, configurators)
- Custom data displays (real-time feeds, dashboards)
- Third-party integrations not available as plugins
- Unique layout patterns beyond Ollie's library
- Dynamic content requiring server-side rendering

**Block Creation Standards:**
```
1. Use @wordpress/create-block scaffolding
2. Follow WordPress Block API best practices
3. Implement block.json with proper attributes
4. Support theme.json color and spacing tokens
5. Ensure responsive design
6. Add editor preview matching frontend
7. Include block variations where appropriate
8. Register in child theme (not plugin)
```

**Child Theme Structure:**
```
ollie-child/
├── style.css              # Theme declaration
├── theme.json             # Design token overrides
├── functions.php          # Block registration
├── blocks/                # Custom blocks
│   ├── calculator/
│   │   ├── block.json
│   │   ├── index.js
│   │   ├── edit.js
│   │   ├── save.js
│   │   ├── style.scss
│   │   └── editor.scss
│   └── video-player/
│       └── ...
├── patterns/              # Custom patterns
│   └── custom-hero.php
└── parts/                 # Custom template parts
    └── header-custom.html
```

**Tools:**
- `create_block` - Scaffold new block
- `generate_block_code` - Write block implementation
- `create_block_variation` - Add block variation
- `register_block_pattern` - Create custom pattern
- `validate_block` - Test block functionality

---

## Tool Specifications

### ParseProductBriefTool

```php
class ParseProductBriefTool extends BaseTool
{
    public function id(): string { return 'parse_product_brief'; }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'source_type' => [
                    'type' => 'string',
                    'enum' => ['pdf', 'google_doc', 'email', 'url'],
                ],
                'source' => [
                    'type' => 'string',
                    'description' => 'File path, Google Doc ID, or URL',
                ],
            ],
            'required' => ['source_type', 'source'],
        ];
    }

    public function execute(array $params): array
    {
        // Parse document and extract structured brief data
        // Returns: project info, brand guidelines, requirements
    }
}
```

### AnalyzeExistingSiteTool

```php
class AnalyzeExistingSiteTool extends BaseTool
{
    public function id(): string { return 'analyze_existing_site'; }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'Site URL to analyze',
                ],
                'platform' => [
                    'type' => 'string',
                    'enum' => ['wordpress', 'joomla', 'static', 'unknown'],
                ],
                'depth' => [
                    'type' => 'string',
                    'enum' => ['shallow', 'deep'],
                    'description' => 'Analysis depth',
                ],
            ],
            'required' => ['url'],
        ];
    }

    public function execute(array $params): array
    {
        // Crawl site, detect platform, extract structure
        // Returns: sitemap, content inventory, tech stack
    }
}
```

### GenerateThemeJsonTool

```php
class GenerateThemeJsonTool extends BaseTool
{
    public function id(): string { return 'generate_theme_json'; }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'brand_colors' => [
                    'type' => 'object',
                    'description' => 'Brand color palette',
                ],
                'typography' => [
                    'type' => 'object',
                    'description' => 'Typography preferences',
                ],
                'spacing_scale' => [
                    'type' => 'string',
                    'enum' => ['compact', 'default', 'spacious'],
                ],
                'base_style' => [
                    'type' => 'string',
                    'enum' => ['default', 'agency', 'creator', 'startup', 'studio'],
                ],
            ],
            'required' => ['brand_colors'],
        ];
    }

    public function execute(array $params): array
    {
        // Generate theme.json based on brand requirements
        // Returns: complete theme.json content
    }
}
```

### ComposePageTool

```php
class ComposePageTool extends BaseTool
{
    public function id(): string { return 'compose_page'; }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'page_type' => [
                    'type' => 'string',
                    'description' => 'Type of page (home, about, services, etc.)',
                ],
                'sections' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'purpose' => ['type' => 'string'],
                            'content' => ['type' => 'object'],
                        ],
                    ],
                ],
                'header_style' => [
                    'type' => 'string',
                    'enum' => ['light', 'dark', 'transparent'],
                ],
                'footer_style' => [
                    'type' => 'string',
                    'enum' => ['light', 'dark', 'minimal'],
                ],
            ],
            'required' => ['page_type', 'sections'],
        ];
    }

    public function execute(array $params): array
    {
        // Compose page using Ollie patterns
        // Returns: complete page block markup
    }
}
```

---

## UI Integration

### Project Wizard Flow

```
┌─────────────────────────────────────────────────────────────────┐
│  Step 1: Upload Brief                                           │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  📄 Drag & drop PDF, paste Google Doc link, or email    │   │
│  │                                                          │   │
│  │  [Upload PDF] [Paste Link] [Enter Text]                 │   │
│  └─────────────────────────────────────────────────────────┘   │
├─────────────────────────────────────────────────────────────────┤
│  Step 2: Review Extracted Requirements                          │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  Brand Colors: ● Primary ● Secondary ● Accent           │   │
│  │  Typography: Heading: Mona Sans | Body: Mona Sans       │   │
│  │  Pages: Home, About, Services, Contact                  │   │
│  │  Migration Source: https://oldsite.com                  │   │
│  │                                                          │   │
│  │  [Edit] [Confirm]                                       │   │
│  └─────────────────────────────────────────────────────────┘   │
├─────────────────────────────────────────────────────────────────┤
│  Step 3: Agent Execution                                        │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  ✅ Brief analyzed                                       │   │
│  │  ✅ Design system created                                │   │
│  │  🔄 Building pages... (3/6)                             │   │
│  │  ⏳ Content migration pending                            │   │
│  │  ⏳ Custom blocks pending                                │   │
│  │                                                          │   │
│  │  [View Logs] [Pause] [Cancel]                           │   │
│  └─────────────────────────────────────────────────────────┘   │
├─────────────────────────────────────────────────────────────────┤
│  Step 4: Review & Approve                                       │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  🌐 Staging Preview: https://staging.client.example.com      │   │
│  │                                                          │   │
│  │  [View Site] [Review Changes] [Request Revisions]       │   │
│  │                                                          │   │
│  │  [Deploy to Production] (requires approval)             │   │
│  └─────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
```

### Dashboard Components

**OllieSiteBuilder.vue** - Main wizard interface
**OlliePatternPicker.vue** - Visual pattern selector
**OllieBrandEditor.vue** - Brand customization UI
**OllieMigrationProgress.vue** - Migration status display
**OlliePreview.vue** - Live site preview iframe

---

## Ollie Pattern Reference

### Pattern Categories

| Category | Count | Example Patterns |
|----------|-------|------------------|
| Heroes | 5 | hero-dark, hero-light, hero-call-to-action-buttons |
| Headers | 6 | header-dark, header-light, header-*-with-banner |
| Footers | 6 | footer-dark-centered, footer-light-minimal |
| Cards | 18 | card-pricing-table, card-testimonial, card-contact |
| Features | 4 | feature-boxes-with-button, features-with-emojis |
| Testimonials | 5 | testimonials-and-logos, testimonial-highlight |
| Pricing | 4 | pricing-table-3-column, pricing-table-with-testimonials |
| Blog | 5 | blog-post-columns, post-loop-grid-default |
| CTAs | 3 | text-call-to-action, card-call-to-action-with-buttons |
| Pages | 8 | page-home, page-about, page-pricing, page-features |
| Menus | 12 | menu-card-*, menu-mobile-*, menu-panel-* |
| Templates | 12 | template-page-*, template-post-* |

### Pattern Composition Examples

**Homepage:**
```php
<!-- wp:pattern {"slug":"ollie/hero-text-image-and-logos"} /-->
<!-- wp:pattern {"slug":"ollie/feature-boxes-with-icon-dark"} /-->
<!-- wp:pattern {"slug":"ollie/numbers"} /-->
<!-- wp:pattern {"slug":"ollie/testimonials-and-logos"} /-->
<!-- wp:pattern {"slug":"ollie/pricing-table"} /-->
<!-- wp:pattern {"slug":"ollie/text-call-to-action"} /-->
```

**About Page:**
```php
<!-- wp:pattern {"slug":"ollie/hero-light"} /-->
<!-- wp:pattern {"slug":"ollie/text-and-image-columns-with-icons"} /-->
<!-- wp:pattern {"slug":"ollie/team-members"} /-->
<!-- wp:pattern {"slug":"ollie/testimonial-highlight"} /-->
<!-- wp:pattern {"slug":"ollie/text-call-to-action-buttons"} /-->
```

---

## Implementation Checklist

- [ ] Create agent definition classes
- [ ] Implement tool classes
- [ ] Write SKILL.md prompts
- [ ] Build Vue wizard components
- [ ] Add API routes
- [ ] Create database migrations (if needed)
- [ ] Write documentation
- [ ] Add tests
- [ ] Deploy and validate

---

## See Also

- [Agent System Documentation](../AGENTS.md)
- [Ollie Theme](https://olliewp.com/docs/)
- [WordPress Block API](https://developer.wordpress.org/block-editor/)
