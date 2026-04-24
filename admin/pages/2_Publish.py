"""Publish page - Export content to frontend."""

import sys
from pathlib import Path

# Add parent directory to path for imports
sys.path.insert(0, str(Path(__file__).parent.parent))

import streamlit as st
import os
from dotenv import load_dotenv

from core.publisher import export_to_json
from core.database import backup_database

load_dotenv()

st.set_page_config(
    page_title="Publish Content",
    page_icon="📤",
    layout="wide"
)

st.title("📤 Publish Content to Frontend")
st.caption("Export admin database to frontend JSON files")

# Get paths from environment or use defaults
FRONTEND_PATH = os.getenv("FRONTEND_PATH", "../frontend")

col1, col2 = st.columns([2, 1])

with col1:
    st.info(f"""
    **Target Directory:** `{FRONTEND_PATH}/data/content.json`

    This will export all active content blocks from the admin database
    to the frontend JSON file. The frontend will load content from this file.

    ⚠️ Make sure all content looks correct before publishing.
    """)

with col2:
    st.metric("Status", "Ready", "✓")

st.markdown("---")

# Preview current content
st.subheader("📋 Current Content Preview")

from core.crud import list_blocks

blocks = list_blocks(active_only=True)

if blocks:
    # Group by section
    sections = {
        "hero": [],
        "exam": [],
        "benefits": [],
        "resources": [],
        "support": [],
        "footer": []
    }

    for block in blocks:
        if "hero" in block.type:
            sections["hero"].append(block)
        elif "exam" in block.type:
            sections["exam"].append(block)
        elif "benefits" in block.type:
            sections["benefits"].append(block)
        elif "resource" in block.type:
            sections["resources"].append(block)
        elif "support" in block.type:
            sections["support"].append(block)
        elif "footer" in block.type:
            sections["footer"].append(block)

    for section_name, section_blocks in sections.items():
        if section_blocks:
            st.markdown(f"**{section_name.title()} Section ({len(section_blocks)} blocks)**")
            for block in section_blocks:
                with st.expander(f"{block.type.replace('_', ' ').title()} - {block.id[:8]}...", expanded=False):
                    st.json(block.content.model_dump())
else:
    st.warning("No active content blocks found. Go to the Home page to create content.")

st.markdown("---")

# Publish action
st.subheader("🚀 Publish Content")

col1, col2, col3 = st.columns([1, 1, 2])

with col1:
    if st.button("📦 Backup Database", type="secondary"):
        backup_path = backup_database()
        st.success(f"Database backed up to: `{backup_path}`")

with col2:
    if st.button("📤 Export to JSON", type="primary"):
        try:
            json_path = os.path.join(FRONTEND_PATH, "data", "content.json")
            output_path = export_to_json(json_path)

            st.success(f"""
            Content exported successfully!

            **Output file:** `{output_path}`
            **Total blocks:** {len(blocks)}
            """)
        except Exception as e:
            st.error(f"Export failed: {str(e)}")

with col3:
    st.markdown("""
    **What happens:**

    1. All active content blocks are exported to `content.json`
    2. JSON is formatted with proper indentation
    3. Frontend will automatically load new content on refresh

    The frontend will NOT break if this file is properly structured.
    """)

# Show a sample of what will be exported
st.markdown("---")
st.subheader("🔍 Export Preview")

if blocks:
    import json
    from datetime import datetime

    preview_data = {
        "last_updated": datetime.now().isoformat(),
        "blocks": [
            block.to_content_json()
            for block in blocks[:3]  # Show first 3 blocks
        ]
    }

    st.json(preview_data)

    if len(blocks) > 3:
        st.caption(f"... and {len(blocks) - 3} more blocks")
