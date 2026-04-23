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

# Page navigation
pg = st.navigation([
    st.Page("main.py", title="Home", icon="🏠"),
    st.Page("pages/content.py", title="Content", icon="📝"),
    st.Page("pages/image_manager.py", title="Images", icon="🖼️"),
    st.Page("pages/publish.py", title="Publish", icon="🚀"),
])

st.sidebar.success("Navigate using the menu above")
st.sidebar.markdown("---")
st.sidebar.markdown("### 🎓 NAT-TEST Admin")
st.sidebar.caption("Local-only administration interface")

pg.run()
