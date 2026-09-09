# Site Builder

Autonomous website building system that creates professional WordPress sites from domain names and business briefs. Features AI research, content generation, and WordPress MCP integration for complete website automation.

---

## Getting Started

### Entry Point: `/site-builder` in the Zao Dashboard

1. Click **Site Builder** in the AI Tools section of the sidebar
2. Or use Command Palette (⌘K) and type "site builder"

### Quick Start Workflow

1. **Create Project**: Enter domain name and business brief
2. **AI Research**: System analyzes company and extracts brand assets
3. **Site Building**: Ollie agents create WordPress theme and content
4. **Content Sync**: Content flows to WordPress site automatically
5. **Client Access**: Professional site ready with full editing control

---

## What It Builds

| Input | Process | Output |
|-------|---------|--------|
| Domain + Brief | Research → Build → Deploy | Live WordPress site |
| `acme.com` + "Local bakery" | Archive.org search, brand analysis, Ollie theme | Professional site with content |
| `defunctcorp.com` + "Manufacturing" | Historical research, content generation | Modern site from legacy data |

---

## Architecture

### Agent Chain

```
site-builder-orchestrator (Opus) - Master coordinator
    ├── research-agent (Sonnet) → Company analysis & branding
    ├── ollie-brief-analyzer (Opus) → Requirements extraction
    ├── ollie-design-system (Sonnet) → Theme customization
    ├── ollie-page-builder (Sonnet) → Page composition
    ├── content-sync-agent (Sonnet) → WordPress MCP integration
    └── wordpress-mcp-agent (Sonnet) → Site provisioning
```

### Key Components

#### Research Agent
- **Archive.org Integration**: Finds defunct company information
- **Brand Analysis**: Extracts colors, logos, voice from websites
- **Industry Research**: Understands market positioning

#### Content Sync Agent  
- **WordPress MCP**: Leverages existing WordPress integration
- **Multi-Site Support**: Pushes content to multiple sites
- **Draft Management**: Creates posts for client review

#### Orchestrator Agent
- **Project Tracking**: Monitors 7-stage build process
- **Quality Assurance**: Validates deliverables
- **Client Communication**: Provides status updates

---

## API Reference

### Create Project
```bash
POST /api/site-builder/projects
{
  "domain": "example.com",
  "brief": "Build a professional website for a local business",
  "company_type": "active|defunct|startup|enterprise", 
  "target_hosting": "wordpress_com|self_hosted|existing_site",
  "environment": "staging|production",
  "timeline": "rush|standard|extended"
}
```

### Get Project Status
```bash
GET /api/site-builder/projects/{id}/status
Response: {
  "status": "research|building|content_sync|qa|complete",
  "progress_percentage": 75,
  "current_phase": "Content Generation",
  "estimated_completion": "2024-01-15T14:30:00Z"
}
```

### List Projects
```bash
GET /api/site-builder/projects
Response: [
  {
    "id": 123,
    "domain": "example.com", 
    "status": "complete",
    "created_at": "2024-01-10T10:00:00Z"
  }
]
```

---

## Business Use Cases

### $500 Website Service Automation

**Before**: Manual WordPress development
- Research company manually
- Design site from scratch  
- Build pages individually
- Client testing and revisions
- **Time**: 2-3 weeks
- **Cost**: High manual effort

**After**: Autonomous building
- Enter domain + brief
- AI handles research and building
- Professional site in 30 minutes
- Client gets editing access immediately
- **Time**: 30 minutes
- **Cost**: $10-20 per site

### Content Pipeline Revenue

**Ongoing Content Management**:
- Blog posts, case studies, landing pages
- All created by existing agents
- Automatically published to client sites
- **Revenue**: $50-200/month per client

### Client Types Supported

#### Active Companies
- Research current website and branding
- Migrate existing content
- Enhance with modern design

#### Defunct Companies  
- Archive.org research for historical data
- Reconstruct brand identity
- Modern website from legacy information

#### Startups
- Generate brand from industry best practices
- Create initial content and messaging
- Professional presence quickly

#### Enterprise
- Complex requirements and compliance
- Multiple stakeholders
- Comprehensive content strategies

---

## Technical Details

### Hosting Compatibility

Works with **any WordPress hosting**:
- WordPress.com (free tier available)
- SiteGround, Kinsta, Cloudways
- Self-hosted WordPress installations
- Existing client WordPress sites

### Content Pipeline

```
Agent Output → ContentSyncAgent → WordPress MCP → Client Site
     ↓              ↓                     ↓              ↓
Blog Post     Draft Creation       REST API       Live Content
Case Study    Media Upload        createPost()   Client Edit  
Landing Page  Category Sync       updatePost()   Immediate Update
```

### Security & Permissions

- **Client Isolation**: Each project is user-scoped
- **WordPress Access**: Secure credential management
- **Content Ownership**: Clients maintain full editing rights
- **API Security**: MCP authentication and validation

---

## Testing & Validation

### Test Command
```bash
php artisan site-builder:test example.com --brief="Test website building"
```

### Quality Checks
- **Brand Compliance**: Colors, fonts, voice match research
- **Technical Validation**: Links, images, mobile responsiveness
- **Content Accuracy**: Facts verified against research
- **Performance**: Fast loading, SEO optimization

---

## Troubleshooting

### Common Issues

**Research Fails**
- Domain not found: Check spelling and DNS
- Archive.org empty: Provide more context in brief
- Brand assets missing: Use industry defaults with client approval

**WordPress Connection Issues**
- MCP not configured: Check WordPress plugin installation
- Authentication failed: Verify API credentials
- Site not accessible: Confirm hosting and DNS

**Content Sync Problems**
- Draft not created: Check WordPress user permissions
- Media upload fails: Verify file sizes and formats
- Categories missing: Automatic category creation enabled

### Support Escalation

1. **Automatic Recovery**: System retries failed operations
2. **Manual Intervention**: Complex issues flagged for review
3. **Client Communication**: Status updates and next steps provided

---

## Future Enhancements

### Planned Features
- **Multi-language Support**: International site building
- **E-commerce Integration**: WooCommerce store setup
- **Performance Optimization**: Automatic Cloudflare APO configuration
- **Analytics Integration**: Google Analytics and Search Console setup
- **SEO Automation**: Meta descriptions, schema markup, sitemaps

### Integration Opportunities
- **CRM Integration**: Client data from HubSpot, Salesforce
- **Content Management**: Integration with Notion, Google Docs
- **Asset Libraries**: Brand asset management systems
- **Deployment Automation**: CI/CD pipeline integration

---

## Cost Analysis

### Per Site Build Costs
- **Research Agent**: $2-5 (archive.org, brand analysis)
- **WordPress Setup**: $2-5 (site provisioning, theme installation)
- **Content Generation**: $3-5 (Ollie agents, content sync)
- **Quality Assurance**: $1-3 (validation, testing)
- **Total**: $8-18 per site

### Revenue Opportunities
- **Site Building**: $500 per site (vs $8-18 cost)
- **Content Management**: $50-200/month per client
- **Maintenance**: $25-100/month ongoing support
- **Hosting**: $3-30/month (client pays hosting directly)

### ROI Calculation
- **First Site**: 27x return ($500 revenue / $18 cost)
- **Ongoing**: 3-10x monthly ($150 avg revenue / $30 avg cost)
- **Scalability**: Zero marginal cost per additional site

---

*Built on the existing Ollie theme system and WordPress MCP integration for maximum compatibility and performance.*
