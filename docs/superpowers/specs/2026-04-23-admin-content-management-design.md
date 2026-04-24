# Admin Content Management System Design

**Date:** 2026-04-23
**Status:** Approved
**Phase:** MVP - Home Page Content Management

## Overview

Local-only Streamlit admin interface for managing home page content on the NAT-TEST Centre website. The system uses a flexible content blocks approach, allowing admins to create, edit, and manage all home page sections through a unified interface.

## Architecture

**Key Principle:** Single SQLite database on developer laptop → JSON export → rsync to production server. The admin app is NEVER exposed to the network.

**Technology Stack:**
- Streamlit (UI framework)
- SQLite (local database)
- Pydantic (validation)
- Pillow (image processing)
- Python 3.11+

## Database Schema

### content_blocks Table

```sql
CREATE TABLE content_blocks (
    id TEXT PRIMARY KEY,              -- UUID
    block_type TEXT NOT NULL,         -- 'hero', 'banner', 'heading_text', 'card', 'footer'
    title TEXT,                       -- Optional display name for admin UI
    content TEXT NOT NULL,            -- JSON string with block-specific data
    display_order INTEGER NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,         -- ISO 8601 timestamp
    updated_at TEXT NOT NULL          -- ISO 8601 timestamp
);

CREATE INDEX idx_blocks_type ON content_blocks(block_type);
CREATE INDEX idx_blocks_active ON content_blocks(is_active);
CREATE INDEX idx_blocks_order ON content_blocks(display_order);
```

### images Table

```sql
CREATE TABLE images (
    id TEXT PRIMARY KEY,
    original_filename TEXT NOT NULL,
    original_path TEXT NOT NULL,      -- /media/images/original/filename.ext
    optimized_path TEXT,              -- /media/images/optimized/filename.webp
    alt_text TEXT,
    uploaded_at TEXT NOT NULL,
    file_size_bytes INTEGER,
    width INTEGER,
    height INTEGER
);
```

## Content Block Types and JSON Schemas

### Hero Block
```json
{
  "slogan": "Excellence through Assessment",
  "description": "Join Bangladesh's premier national testing platform",
  "image_url": "/media/images/optimized/hero-home.webp",
  "primary_link": {
    "label": "Register Now",
    "url": "/registration.html"
  },
  "secondary_link": {
    "label": "Learn More",
    "url": "/resources.html"
  }
}
```

### Banner Block
```json
{
  "exam_date": "2026-06-15",
  "exam_info_url": "/resources/exam-info.html",
  "registration_url": "/registration.html"
}
```

### Heading+Text Block
```json
{
  "heading": "About NAT-TEST",
  "body_text": "The National Assessment Test Centre promotes academic excellence..."
}
```

### Card Block
```json
{
  "title": "Resources",
  "description": "Access study materials, practice tests, and preparation guides",
  "link_url": "/resources.html",
  "icon_name": "library_books"
}
```

### Footer Block
```json
{
  "copyright_text": "© 2026 NAT-TEST Centre. All rights reserved.",
  "links": [
    {"label": "Privacy Policy", "url": "/privacy.html"},
    {"label": "Terms of Service", "url": "/terms.html"},
    {"label": "Contact", "url": "/contact.html"}
  ]
}
```

## Streamlit UI Structure

### Page: Content Management

**Sidebar Components:**
- Block type selector (radio buttons: Hero, Banner, Heading+Text, Card, Footer)
- "Create New [Type]" button
- Live Preview toggle
- Image Manager button

**Main Area - List View:**
- Table with columns: title, type, display_order, is_active, updated_at
- Action buttons: Edit, Duplicate, Delete
- Drag-and-drop reordering
- Toggle for is_active status

**Main Area - Edit/Create Form:**
- Dynamic form fields based on block_type
- Input validation with inline error messages
- JSON preview toggle for advanced editing
- Save, Cancel, Preview buttons

**Live Preview Panel:**
- Real-time rendering of block as it appears on home page
- Updates as form fields change

## Image Management

### Storage Structure
```
frontend/media/
  images/
    original/     # All uploaded originals (preserved)
    optimized/    # Processed/cropped versions (used on site)
```

### Workflow

1. **Upload:**
   - Drag-drop or file picker
   - Original saved to `original/` with unique filename
   - Record in `images` table

2. **Crop/Resize:**
   - Open cropper UI after upload
   - Select crop area with aspect ratio presets (16:9, 4:3, 1:1, custom)
   - Specify output size (e.g., "1200x600 for hero")
   - Save optimized version to `optimized/`
   - Update `optimized_path` in database

3. **Image Picker:**
   - Shows thumbnails of all images from DB
   - Filter: all, optimized only, originals only
   - Search by filename
   - Preview on hover
   - Click to select (stores optimized_path in content)

### Image Processing
- Convert to WebP format with JPEG fallback
- Resize if >2MB
- Maintain aspect ratio or force dimensions based on crop
- Generate responsive sizes if needed

## CRUD Operations

### Create
- Generate UUID for new block
- Validate required fields based on block_type
- Set display_order = max(existing) + 1
- Set is_active = True
- Validate JSON structure before saving

### Read
- List all blocks, filterable by type and active status
- Load single block by ID for editing
- Preview mode loads block without saving

### Update
- Validate all fields before save
- Update updated_at timestamp
- Check image references exist

### Delete
- Soft delete (set is_active=False) by default
- Hard delete with confirmation after 30 days
- Check image usage before allowing image deletion

## Validation Rules

### Hero Block
- slogan: required, max 200 chars
- description: required, max 500 chars
- image_url: required, must exist in filesystem
- primary_link.label: required
- primary_link.url: required, valid URL
- secondary_link.label: required
- secondary_link.url: required, valid URL

### Banner Block
- exam_date: required, must be future date
- exam_info_url: required, valid URL
- registration_url: required, valid URL

### Card Block
- title: required
- description: required, max 300 chars
- link_url: required, valid URL
- icon_name: optional

### All URLs
Must start with `http://`, `https://`, or `/` (relative paths)

## Frontend Integration

### Publish Workflow

1. Admin clicks "Publish to Frontend"
2. System generates `frontend/data/content.json`
3. Shows diff preview (what's changing)
4. User confirms
5. Runs rsync dry-run, shows file changes
6. User confirms
7. rsync to production server

### Export Format: `frontend/data/content.json`

```json
{
  "last_updated": "2026-04-23T10:30:00Z",
  "blocks": [
    {
      "id": "uuid-here",
      "type": "hero",
      "display_order": 1,
      "is_active": true,
      "content": { ... }
    }
  ]
}
```

### Frontend Consumption

- Home page fetches `/data/content.json` on load
- Renders blocks in display_order sequence
- Skips blocks where is_active=false
- Each block type has dedicated render function

## Error Handling

### Validation Errors
- Inline error messages next to invalid fields
- Red highlighting of problematic fields
- Block save until validation passes
- Specific error messages (e.g., "Image URL not found")

### Missing Images
- Show placeholder in admin if image_path doesn't exist
- Don't publish blocks with missing images
- Error message lists problematic blocks

### Publish Failures
- If rsync fails, show error and keep old content.json
- Log all publish attempts with timestamp and result
- Option to retry failed publish

### Database Protection
- Automatic backup before any write operation
- Backups stored in `admin/data/backups/`
- Recovery: restore from last backup

## File Structure

```
/admin
  main.py                 # Streamlit app entry point
  requirements.txt        # Python dependencies
  .env                    # SMTP credentials, paths (gitignored)

  /core
    database.py           # SQLite connection, CRUD operations
    models.py             # Pydantic models for validation
    publisher.py          # Export to JSON, rsync operations
    image_processor.py    # Image optimization, cropping

  /pages
    content.py            # Content management page
    image_manager.py      # Image upload, crop, picker UI

  /templates
    /emails               # Email templates (for registration workflow)

  /data
    admin.db              # Local SQLite database (gitignored)
    backups/              # DB backups (gitignored)

  /tests
    test_database.py
    test_publisher.py
    test_models.py
```

## Dependencies

```
streamlit>=1.28.0
pydantic>=2.0.0
pillow>=10.0.0
python-dotenv>=1.0.0
pytest>=7.4.0
```

## Testing Strategy

### Unit Tests
- Database CRUD operations
- Pydantic model validation
- JSON serialization/deserialization
- Image processing (resize, crop, format conversion)

### Integration Tests
- Publish workflow (DB → content.json)
- Image picker (DB load → UI display)
- Block ordering (display_order changes)

### Manual Testing
- Streamlit UI (create, edit, delete blocks)
- Image upload and crop workflow
- Publish and verify frontend updates
- Error scenarios (invalid URLs, missing images)

### Test Data
- Seed script to populate admin.db with sample content
- Test images in various formats/sizes

## Security Considerations

- Bind Streamlit to 127.0.0.1 only (never 0.0.0.0)
- Never deploy admin app to network
- SMTP credentials in .env (gitignored)
- admin.db and backups gitignored
- No authentication needed (local only)

## Future Enhancements (Out of Scope for MVP)

- Version history for blocks
- Multi-language support (English, Bangla, Japanese)
- Scheduled publishing (publish at future date)
- Content staging (preview changes before publishing)
- Bulk edit operations
- Content search and filtering

## Success Criteria

- Admin can create, edit, and delete all home page content blocks
- Image upload with crop/resize functionality works
- Publish workflow generates valid content.json
- Frontend correctly renders published content
- All operations validated with clear error messages
- No data loss (backups before writes)
