"""Content management page for Streamlit admin interface."""

import streamlit as st
from pathlib import Path
from typing import Optional
import sys

sys.path.insert(0, str(Path(__file__).parent.parent))

from core.database import init_db
from core.crud import create_block, get_block, list_blocks, update_block, delete_block
from core.models import (
    HeroContent, BannerContent, HeadingTextContent, CardContent, FooterContent
)

st.subheader("📝 Content Management")

# Initialize database
init_db()

# Sidebar: Block type selector
st.sidebar.header("Block Type")
block_types = ["hero", "banner", "heading_text", "card", "footer"]
selected_type = st.sidebar.radio("Select block type", block_types)

st.sidebar.markdown("---")
st.sidebar.markdown("### Create New")
if st.sidebar.button(f"➕ New {selected_type.replace('_', ' ').title()} Block"):
    st.session_state.editing_block = None
    st.session_state.creating_type = selected_type
    st.rerun()

# Main area: List or Edit form
if "creating_type" in st.session_state or "editing_block" in st.session_state:
    show_edit_form()
else:
    show_block_list(selected_type)


def show_block_list(block_type: str):
    """Display list of blocks of selected type."""
    blocks = list_blocks(block_type=block_type)

    st.markdown(f"### {block_type.replace('_', ' ').title()} Blocks")

    if not blocks:
        st.info(f"No {block_type} blocks found. Create one!")
        return

    for block in blocks:
        with st.expander(f"{'✅' if block.is_active else '⭕'} {block.title or 'Untitled'} (Order: {block.display_order})"):
            col1, col2, col3 = st.columns(3)

            with col1:
                if st.button("✏️ Edit", key=f"edit_{block.id}"):
                    st.session_state.editing_block = block.id
                    st.rerun()

            with col2:
                if st.button("🗑️ Delete", key=f"delete_{block.id}"):
                    if delete_block(block.id):
                        st.success("Block deleted (soft delete)")
                        st.rerun()

            with col3:
                new_status = not block.is_active
                if st.button(
                    f"{'Deactivate' if block.is_active else 'Activate'}",
                    key=f"toggle_{block.id}"
                ):
                    update_block(block.id, is_active=new_status)
                    st.success(f"Block {'deactivated' if block.is_active else 'activated'}")
                    st.rerun()

            # Preview
            st.markdown("**Preview:**")
            st.json(block.content.model_dump())


def show_edit_form():
    """Show form for creating/editing a block."""
    editing_id = st.session_state.get("editing_block")
    creating_type = st.session_state.get("creating_type")

    if editing_id:
        block = get_block(editing_id)
        if not block:
            st.error("Block not found")
            del st.session_state.editing_block
            st.rerun()
            return

        block_type = block.block_type
        st.markdown(f"### ✏️ Edit {block_type.replace('_', ' ').title()} Block")
    else:
        block_type = creating_type
        st.markdown(f"### ➕ Create {block_type.replace('_', ' ').title()} Block")
        block = None

    # Form based on block type
    with st.form(key="block_form"):
        title = st.text_input("Title (internal name)", value=block.title if block else "")
        display_order = st.number_input("Display Order", value=block.display_order if block else 1, min_value=1)

        content_data = get_content_form(block_type, block)

        col1, col2 = st.columns(2)
        with col1:
            submitted = st.form_submit_button("💾 Save")
        with col2:
            cancelled = st.form_submit_button("❌ Cancel")

        if cancelled:
            if "editing_block" in st.session_state:
                del st.session_state.editing_block
            if "creating_type" in st.session_state:
                del st.session_state.creating_type
            st.rerun()

        if submitted:
            try:
                if editing_id:
                    update_block(
                        block_id=editing_id,
                        title=title if title else None,
                        content=content_data,
                        display_order=display_order
                    )
                    st.success("Block updated!")
                    del st.session_state.editing_block
                else:
                    create_block(
                        block_type=block_type,
                        title=title if title else None,
                        content=content_data,
                        display_order=display_order
                    )
                    st.success("Block created!")
                    del st.session_state.creating_type
                st.rerun()
            except Exception as e:
                st.error(f"Error saving block: {e}")


def get_content_form(block_type: str, block: Optional) -> dict:
    """Return content form fields based on block type."""
    content = block.content if block else None

    if block_type == "hero":
        slogan = st.text_input(
            "Slogan",
            value=content.slogan if content else "",
            max_chars=200,
            help="Main headline (max 200 chars)"
        )
        description = st.text_area(
            "Description",
            value=content.description if content else "",
            max_chars=500,
            help="Supporting text (max 500 chars)"
        )
        image_url = st.text_input(
            "Image URL",
            value=content.image_url if content else ""
        )

        st.markdown("**Primary Link**")
        p_label = st.text_input("Label", value=content.primary_link["label"] if content else "")
        p_url = st.text_input("URL", value=content.primary_link["url"] if content else "")

        st.markdown("**Secondary Link**")
        s_label = st.text_input("Label", key="s_label", value=content.secondary_link["label"] if content else "")
        s_url = st.text_input("URL", key="s_url", value=content.secondary_link["url"] if content else "")

        return HeroContent(
            slogan=slogan,
            description=description,
            image_url=image_url,
            primary_link={"label": p_label, "url": p_url},
            secondary_link={"label": s_label, "url": s_url}
        )

    elif block_type == "banner":
        exam_date = st.date_input(
            "Exam Date",
            value=content.exam_date if content else None
        )
        exam_info_url = st.text_input(
            "Exam Info URL",
            value=content.exam_info_url if content else ""
        )
        registration_url = st.text_input(
            "Registration URL",
            value=content.registration_url if content else ""
        )

        return BannerContent(
            exam_date=exam_date.isoformat(),
            exam_info_url=exam_info_url,
            registration_url=registration_url
        )

    elif block_type == "heading_text":
        heading = st.text_input("Heading", value=content.heading if content else "")
        body_text = st.text_area("Body Text", value=content.body_text if content else "")

        return HeadingTextContent(heading=heading, body_text=body_text)

    elif block_type == "card":
        title = st.text_input("Title", value=content.title if content else "")
        description = st.text_area(
            "Description",
            value=content.description if content else "",
            max_chars=300
        )
        link_url = st.text_input("Link URL", value=content.link_url if content else "")
        icon_name = st.text_input(
            "Icon Name (optional)",
            value=content.icon_name if content else ""
        )

        return CardContent(
            title=title,
            description=description,
            link_url=link_url,
            icon_name=icon_name if icon_name else None
        )

    elif block_type == "footer":
        copyright_text = st.text_input(
            "Copyright Text",
            value=content.copyright_text if content else ""
        )

        st.markdown("**Links**")
        links = []
        num_links = st.number_input("Number of links", value=len(content.links) if content else 2, min_value=0)

        for i in range(num_links):
            col1, col2 = st.columns(2)
            with col1:
                label = st.text_input(
                    f"Link {i+1} Label",
                    key=f"link_label_{i}",
                    value=content.links[i]["label"] if content and i < len(content.links) else ""
                )
            with col2:
                url = st.text_input(
                    f"Link {i+1} URL",
                    key=f"link_url_{i}",
                    value=content.links[i]["url"] if content and i < len(content.links) else ""
                )
            if label or url:
                links.append({"label": label, "url": url})

        return FooterContent(copyright_text=copyright_text, links=links)
