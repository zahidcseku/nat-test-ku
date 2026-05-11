# NAT-TEST Admin Interface

Local-only Streamlit administration interface for managing NAT-TEST Centre website content.

## Quick Start

The easiest way to get started:

```bash
cd admin
python3 seed_content.py  # Initialize database
streamlit run main.py     # Start admin interface
```

The app will be available at http://127.0.0.1:8501

## Setup

### 1. Install Dependencies

```bash
pip install --break-system-packages pydantic python-dotenv streamlit st-tiny-editor
```

### 2. Configure Environment (Optional)

Create `.env` file:

```bash
DATABASE_PATH=./data/admin.db
FRONTEND_PATH=../frontend
```

### 3. Initialize Database

```bash
python3 seed_content.py
```

This creates the admin database with initial content matching `frontend/data/content.json`.

## Pages

### 1. Home Content Editor (🏠)

Six tabs for managing different sections:

- **🎯 Hero**: Badge, headline, description, primary/secondary CTAs
- **📅 Exam Ribbon**: Exam date, registration status, action links
- **✨ Benefits**: Section heading and description
- **📚 Resources**: Heading and resource cards (PDF, checklist, protocol)
- **💬 Support**: Heading, description, contact information
- **📋 Footer**: Copyright text and navigation links

#### Content Fields Explained

**Headings with HTML:**
- `line1`: First line (plain text)
- `line2_italic`: Second line (will be italicized)
- `full_html`: Complete HTML with styling classes
  - Hero/Benefits/Resources: `<span class="italic text-secondary">`
  - Support: `<span class="italic font-normal">`

**URLs:**
- Must start with `/`, `#`, `http://`, or `https://`
- `/page.html` = internal link
- `#` = anchor/placeholder
- `https://example.com` = external link

**Icons:**
- Uses Material Symbols Outlined
- Common icons: `arrow_forward`, `download`, `library_books`, `description`, `call`, `mail`, `event`, `info`, `edit_note`

### 2. Publish Page (📤)

Export content from admin database to frontend JSON:

**Features:**
- Preview all active content blocks before publishing
- Automatic database backup before export
- Export to `../frontend/data/content.json`
- Shows export preview and block count

**Publish Workflow:**
1. Review content preview
2. Click "📦 Backup Database" (optional but recommended)
3. Click "📤 Export to JSON"
4. Content is now available to frontend

## Database Schema

### content_blocks Table

```sql
CREATE TABLE content_blocks (
    id TEXT PRIMARY KEY,
    block_type TEXT NOT NULL,
    content TEXT NOT NULL,  -- JSON string
    display_order INTEGER NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
)
```

### Block Types

- `hero_badge`: Small badge text above headline
- `hero_headline`: Main headline with HTML styling
- `hero_description`: Hero section description
- `hero_cta_primary`: Primary call-to-action button
- `hero_cta_secondary`: Secondary call-to-action button
- `exam_ribbon`: Exam date and registration links
- `benefits_heading`: Benefits section heading
- `benefits_description`: Benefits section description
- `resources_heading`: Resources section heading
- `resource_card`: Individual resource card (PDF, checklist, etc.)
- `support_heading`: Support section heading
- `support_description`: Support section description
- `support_contact`: Contact information (phone/email)
- `footer_copyright`: Footer copyright text
- `footer_links`: Footer navigation links

## Content JSON Structure

Exported `content.json` format:

```json
{
  "last_updated": "2026-04-24T10:20:00.552991",
  "blocks": [
    {
      "id": "unique-id-here",
      "type": "hero_badge",
      "display_order": 1,
      "is_active": true,
      "content": {
        "text": "Official Assessment Partner"
      }
    }
  ]
}
```

## Safety Features

### Automatic Backups

Every write operation creates a backup in `./data/backups/admin_YYYYMMDD_HHMMSS.db`

### Content Validation

- Pydantic models validate all content before saving
- URLs must match approved patterns
- Required fields are enforced
- HTML is preserved safely

### Local-Only Design

- Admin interface runs on `127.0.0.1` only
- Never expose Streamlit on `0.0.0.0`
- No network authentication needed
- Database and `.env` are gitignored

## Troubleshooting

### Database Locked Error

```bash
rm -f data/admin.db
python3 seed_content.py
```

### Import Errors

```bash
pip install --break-system-packages pydantic python-dotenv streamlit
```

### Content Not Appearing on Frontend

1. Check browser console for errors
2. Verify `content-loader.js` is included in `index.html`
3. Ensure JSON is valid: `cat ../frontend/data/content.json | jq .`
4. Check content-loader selectors match HTML structure

### Frontend Broken After Publish

The content loader uses specific CSS selectors. If HTML structure changes:

1. Update selectors in `frontend/content-loader.js`
2. Match parent containers to avoid conflicts
3. Test with browser DevTools: `document.querySelector('your-selector')`

## Security Notes

⚠️ **CRITICAL**: This admin interface is designed for local use only.

**DO NOT:**
- Run on `0.0.0.0` or expose to network
- Deploy to any server
- Commit `admin.db`, `.env`, or any content from `data/backups/`
- Share screenshots containing sensitive configuration

**DO:**
- Run locally on developer's machine only
- Keep `.env` file secure and gitignored
- Regularly backup database before major changes
- Test exports on local frontend first

## File Structure

```
admin/
├── main.py                 # Streamlit app entry point
├── seed_content.py         # Database initialization
├── .env                    # Configuration (gitignored)
├── data/
│   ├── admin.db           # SQLite database (gitignored)
│   └── backups/           # Database backups
├── core/
│   ├── database.py        # Database connection & schema
│   ├── crud.py            # CRUD operations
│   ├── models.py          # Pydantic validation models
│   └── publisher.py       # Export to JSON
└── pages/
    ├── 1_Home.py          # Home page editor
    └── 2_Publish.py       # Publish to frontend
```
