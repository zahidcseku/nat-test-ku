import streamlit as st
from dotenv import load_dotenv
import os

load_dotenv()

st.set_page_config(
    page_title="NAT-TEST Admin",
    page_icon="🎓",
    layout="wide",
    initial_sidebar_state="expanded"
)

# Home page
st.title("🎓 NAT-TEST Centre Admin")

st.sidebar.success("Navigate using the menu above")
st.sidebar.markdown("---")
st.sidebar.markdown("### 🎓 NAT-TEST Admin")
st.sidebar.caption("Local-only administration interface")

st.markdown("""
## Welcome to the NAT-TEST Centre administration interface.

Use the pages in the sidebar to:
- **Content**: Manage home page content blocks
- **Images**: Upload and manage images
- **Publish**: Export content to frontend and sync to production

## Getting Started

1. Navigate to **Content** to create and edit content blocks
2. Use **Images** to upload and optimize images
3. Go to **Publish** to export changes to the frontend

## Security Note

⚠️ This admin interface is for **local use only** and should never be exposed to the network.
""")
