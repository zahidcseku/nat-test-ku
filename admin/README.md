# NAT-TEST Admin

Local-only Streamlit admin interface for managing NAT-TEST Centre website content.

## Quick Start

The easiest way to get started:

```bash
./start_admin.sh
```

This script will:
- Create `.env` from template if needed
- Install dependencies automatically
- Initialize database with seed content
- Start the admin interface

The app will be available at http://127.0.0.1:8501

## Manual Setup

If you prefer to set up manually:

1. Install dependencies:
```bash
pip install -r requirements.txt
```

2. Create environment file:
```bash
cp .env.example .env
# Edit .env with your configuration
```

3. Initialize database:
```bash
python scripts/seed_content.py
```

4. Run the app:
```bash
streamlit run main.py
```

## Pages

- **Home**: Overview and navigation
- **Content**: Manage content blocks (hero, banner, cards, etc.)
- **Images**: Upload and manage images
- **Publish**: Export content to JSON and sync to production

## Security

⚠️ **This app is for local use only. Never expose it to the network.**

- Streamlit binds to 127.0.0.1 by default
- Do not change to 0.0.0.0
- Do not deploy to any server
- .env file contains sensitive credentials (gitignored)
- admin.db contains local data (gitignored)

## Development

Run tests:
```bash
pytest tests/ -v
```

## Publishing Content

1. Edit content blocks in the Content page
2. Go to Publish page
3. Review the changes
4. Click "Publish to Frontend" to generate content.json
5. Review rsync dry-run output
6. Confirm to sync to production server
