"""Home page content editor - Streamlit page."""

import sys
from pathlib import Path

# Add parent directory to path for imports
sys.path.insert(0, str(Path(__file__).parent.parent))

import streamlit as st
from core.models import (
    HeroBadgeContent,
    HeroHeadlineContent,
    HeroDescriptionContent,
    HeroCtaContent,
    ExamRibbonContent,
    HeadingContent,
    DescriptionContent,
    ResourceCardContent,
    SupportContactContent,
    FooterCopyrightContent,
    FooterLinksContent
)
from core.crud import (
    get_blocks_by_section,
    update_block,
    create_block,
    delete_block
)
from core.database import init_db


def rich_text_editor(key: str, initial_content: str = "", height: int = 200) -> str:
    """Embed a Quill.js rich text editor in Streamlit.

    NOTE: This is for local-only admin use by trusted administrators.
    The HTML is rendered on the frontend which already supports HTML content.
    """
    # Import Quill CSS and JS
    st.markdown("""
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
    """, unsafe_allow_html=True)

    # Generate unique IDs
    editor_id = f"quill-editor-{key}"
    hidden_id = f"quill-hidden-{key}"

    # Escape initial content for JavaScript
    initial_escaped = initial_content.replace("`", "\\`").replace('"', '\\"').replace('\n', '\\n')

    # Create editor
    editor_html = f"""
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body {{
                margin: 0;
                padding: 10px;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            }}
            #{editor_id} {{
                border: 1px solid #ccc;
                border-radius: 4px;
                background: white;
            }}
            .ql-toolbar {{
                border-top-left-radius: 4px !important;
                border-top-right-radius: 4px !important;
                border: 1px solid #ccc !important;
                border-bottom: none !important;
            }}
            .ql-container {{
                border: none !important;
                border-bottom-left-radius: 4px !important;
                border-bottom-right-radius: 4px !important;
                font-size: 16px !important;
                min-height: {height}px;
            }}
        </style>
        <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    </head>
    <body>
        <div id="{editor_id}"></div>
        <input type="hidden" id="{hidden_id}" value="">
        <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
        <script>
        (function() {{
            var quill = new Quill('#{editor_id}', {{
                modules: {{
                    toolbar: [
                        ['bold', 'italic', 'underline'],
                        ['link'],
                        [{{ 'list': 'ordered'}}, {{ 'list': 'bullet' }}]
                    ]
                }},
                theme: 'snow',
                placeholder: 'Start typing your description here...'
            }});

            // Set initial content
            quill.root.innerHTML = `{initial_escaped}`;

            // Save to hidden input on change
            quill.on('text-change', function() {{
                document.getElementById('{hidden_id}').value = quill.root.innerHTML;
            }});

            // Initial save
            document.getElementById('{hidden_id}').value = quill.root.innerHTML;

            // Function to get content (called from parent window)
            window.getQuillContent = function() {{
                return quill.root.innerHTML;
            }};

            // Function to set content (called from parent window)
            window.setQuillContent = function(html) {{
                quill.root.innerHTML = html;
                document.getElementById('{hidden_id}').value = html;
            }};
        }})();
        </script>
    </body>
    </html>
    """

    # Render the editor using iframe
    st.iframe(editor_html, height=height + 100, scrolling=False)

    # Text area to capture and display the output
    html_content = st.text_area(
        "Description HTML (auto-synced from editor above)",
        value=initial_content,
        key=f"{key}_html",
        height=150,
        help="The HTML from the rich text editor will appear here. You can also edit it directly."
    )

    return html_content


st.set_page_config(
    page_title="Home Content Editor",
    page_icon="🏠",
    layout="wide"
)

st.title("🏠 Home Page Content Editor")
st.caption("Manage all home page content sections")

# Initialize database
init_db()

# Create tabs for each section
tab1, tab2, tab3, tab4, tab5, tab6 = st.tabs([
    "🎯 Hero", "📅 Exam Ribbon", "✨ Benefits", "📚 Resources", "💬 Support", "📋 Footer"
])

with tab1:
    st.subheader("🎯 Hero Section")
    blocks = get_blocks_by_section("hero")

    badge_block = next((b for b in blocks if b.type == "hero_badge"), None)
    headline_block = next((b for b in blocks if b.type == "hero_headline"), None)
    description_block = next((b for b in blocks if b.type == "hero_description"), None)
    cta_primary_block = next((b for b in blocks if b.type == "hero_cta_primary"), None)
    cta_secondary_block = next((b for b in blocks if b.type == "hero_cta_secondary"), None)

    with st.expander("Badge", expanded=True):
        if badge_block:
            text = st.text_input("Badge Text", value=badge_block.content.text)
            if st.button("Update Badge", key="update_badge"):
                update_block(badge_block.id, content=HeroBadgeContent(text=text))
                st.success("Badge updated!")
                st.rerun()
        else:
            text = st.text_input("Badge Text", value="Official Assessment Partner")
            if st.button("Create Badge", key="create_badge"):
                create_block("hero_badge", HeroBadgeContent(text=text))
                st.success("Badge created!")
                st.rerun()

    with st.expander("Headline", expanded=True):
        if headline_block:
            line1 = st.text_input("Line 1", value=headline_block.content.line1)
            line2 = st.text_input("Line 2 (Italic)", value=headline_block.content.line2_italic)
            if st.button("Update Headline", key="update_headline"):
                full_html = f"{line1}<br><span class=\"italic font-normal text-secondary\">{line2}</span>"
                update_block(headline_block.id, content=HeroHeadlineContent(
                    line1=line1,
                    line2_italic=line2,
                    full_html=full_html
                ))
                st.success("Headline updated!")
                st.rerun()
        else:
            line1 = st.text_input("Line 1", value="The Standard of")
            line2 = st.text_input("Line 2 (Italic)", value="Academic Excellence.")
            if st.button("Create Headline", key="create_headline"):
                full_html = f"{line1}<br><span class=\"italic font-normal text-secondary\">{line2}</span>"
                create_block("hero_headline", HeroHeadlineContent(
                    line1=line1,
                    line2_italic=line2,
                    full_html=full_html
                ))
                st.success("Headline created!")
                st.rerun()

    with st.expander("Description", expanded=True):
        st.markdown("**Rich Text Editor** - Use the toolbar to format text (bold, italic, links, lists)")

        if description_block:
            # Get initial HTML content if it exists, otherwise use text
            initial_html = getattr(description_block.content, 'html', None) or description_block.content.text

            # Rich text editor
            html_content = rich_text_editor(
                key="hero_desc",
                initial_content=initial_html,
                height=200
            )

            # Also store plain text version (strip HTML tags for fallback)
            import re
            plain_text = re.sub('<[^<]+?>', '', html_content) if html_content else ""

            col1, col2 = st.columns(2)
            with col1:
                if st.button("✅ Update Description", key="update_hero_desc", type="primary"):
                    # Store both text and html versions
                    update_block(description_block.id, content=HeroDescriptionContent(text=plain_text, html=html_content))
                    st.success("Description updated!")
                    st.rerun()
            with col2:
                if st.button("🗑️ Clear", key="clear_hero_desc"):
                    st.session_state[f"hero_desc_content"] = ""
                    st.rerun()
        else:
            # Rich text editor for new description
            html_content = rich_text_editor(
                key="hero_desc_new",
                initial_content="",
                height=200
            )

            import re
            plain_text = re.sub('<[^<]+?>', '', html_content) if html_content else ""

            if st.button("✅ Create Description", key="create_hero_desc", type="primary"):
                create_block("hero_description", HeroDescriptionContent(text=plain_text, html=html_content))
                st.success("Description created!")
                st.rerun()

    col1, col2 = st.columns(2)
    with col1:
        with st.expander("Primary CTA", expanded=True):
            if cta_primary_block:
                label = st.text_input("Button Text", value=cta_primary_block.content.label)
                url = st.text_input("URL", value=cta_primary_block.content.url)
                icon = st.text_input("Icon", value=cta_primary_block.content.icon)
                if st.button("Update Primary CTA", key="update_primary_cta"):
                    update_block(cta_primary_block.id, content=HeroCtaContent(label=label, url=url, icon=icon))
                    st.success("Primary CTA updated!")
                    st.rerun()
            else:
                label = st.text_input("Button Text", value="Begin Registration")
                url = st.text_input("URL", value="registration.html")
                icon = st.text_input("Icon", value="arrow_forward")
                if st.button("Create Primary CTA", key="create_primary_cta"):
                    create_block("hero_cta_primary", HeroCtaContent(label=label, url=url, icon=icon))
                    st.success("Primary CTA created!")
                    st.rerun()

    with col2:
        with st.expander("Secondary CTA", expanded=True):
            if cta_secondary_block:
                label = st.text_input("Button Text", value=cta_secondary_block.content.label)
                url = st.text_input("URL", value=cta_secondary_block.content.url)
                icon = st.text_input("Icon", value=cta_secondary_block.content.icon)
                if st.button("Update Secondary CTA", key="update_secondary_cta"):
                    update_block(cta_secondary_block.id, content=HeroCtaContent(label=label, url=url, icon=icon))
                    st.success("Secondary CTA updated!")
                    st.rerun()
            else:
                label = st.text_input("Button Text", value="Download Prospectus")
                url = st.text_input("URL", value="#")
                icon = st.text_input("Icon", value="download")
                if st.button("Create Secondary CTA", key="create_secondary_cta"):
                    create_block("hero_cta_secondary", HeroCtaContent(label=label, url=url, icon=icon))
                    st.success("Secondary CTA created!")
                    st.rerun()

with tab2:
    st.subheader("📅 Exam Ribbon")
    blocks = get_blocks_by_section("exam")
    ribbon_block = next((b for b in blocks if b.type == "exam_ribbon"), None)

    if ribbon_block:
        exam_date = st.text_input("Exam Date", value=ribbon_block.content.exam_date)
        status = st.text_input("Registration Status", value=ribbon_block.content.registration_status)
        info_url = st.text_input("Exam Info URL", value=ribbon_block.content.exam_info_url)
        reg_url = st.text_input("Registration URL", value=ribbon_block.content.registration_url)

        if st.button("Update Exam Ribbon", key="update_exam"):
            update_block(ribbon_block.id, content=ExamRibbonContent(
                exam_date=exam_date,
                registration_status=status,
                exam_info_url=info_url,
                registration_url=reg_url
            ))
            st.success("Exam ribbon updated!")
            st.rerun()
    else:
        exam_date = st.text_input("Exam Date", value="October 14, 2024")
        status = st.text_input("Registration Status", value="Registration closes in 12 days")
        info_url = st.text_input("Exam Info URL", value="resources.html")
        reg_url = st.text_input("Registration URL", value="registration.html")

        if st.button("Create Exam Ribbon", key="create_exam"):
            create_block("exam_ribbon", ExamRibbonContent(
                exam_date=exam_date,
                registration_status=status,
                exam_info_url=info_url,
                registration_url=reg_url
            ))
            st.success("Exam ribbon created!")
            st.rerun()

with tab3:
    st.subheader("✨ Benefits Section")
    blocks = get_blocks_by_section("benefits")

    heading_block = next((b for b in blocks if b.type == "benefits_heading"), None)
    description_block = next((b for b in blocks if b.type == "benefits_description"), None)

    with st.expander("Heading", expanded=True):
        if heading_block:
            line1 = st.text_input("Line 1", value=heading_block.content.line1)
            line2 = st.text_input("Line 2 (Italic)", value=heading_block.content.line2_italic)
            if st.button("Update Benefits Heading", key="update_benefits_heading"):
                full_html = f"{line1}<br><span class=\"italic text-secondary\">{line2}</span>"
                update_block(heading_block.id, content=HeadingContent(
                    line1=line1,
                    line2_italic=line2,
                    full_html=full_html
                ))
                st.success("Benefits heading updated!")
                st.rerun()
        else:
            line1 = st.text_input("Line 1", value="Why Candidates")
            line2 = st.text_input("Line 2 (Italic)", value="Choose NAT")
            if st.button("Create Benefits Heading", key="create_benefits_heading"):
                full_html = f"{line1}<br><span class=\"italic text-secondary\">{line2}</span>"
                create_block("benefits_heading", HeadingContent(
                    line1=line1,
                    line2_italic=line2,
                    full_html=full_html
                ))
                st.success("Benefits heading created!")
                st.rerun()

    with st.expander("Description", expanded=True):
        st.markdown("**Rich Text Editor** - Use the toolbar to format text (bold, italic, links, lists)")

        if description_block:
            # Get initial HTML content if it exists, otherwise use text
            initial_html = getattr(description_block.content, 'html', None) or description_block.content.text

            # Rich text editor
            html_content = rich_text_editor(
                key="benefits_desc",
                initial_content=initial_html,
                height=200
            )

            # Also store plain text version (strip HTML tags for fallback)
            import re
            plain_text = re.sub('<[^<]+?>', '', html_content) if html_content else ""

            col1, col2 = st.columns(2)
            with col1:
                if st.button("✅ Update Description", key="update_benefits_desc", type="primary"):
                    update_block(description_block.id, content=DescriptionContent(text=plain_text, html=html_content))
                    st.success("Benefits description updated!")
                    st.rerun()
            with col2:
                if st.button("🗑️ Clear", key="clear_benefits_desc"):
                    st.session_state[f"benefits_desc_content"] = ""
                    st.rerun()
        else:
            # Rich text editor for new description
            html_content = rich_text_editor(
                key="benefits_desc_new",
                initial_content="",
                height=200
            )

            import re
            plain_text = re.sub('<[^<]+?>', '', html_content) if html_content else ""

            if st.button("✅ Create Description", key="create_benefits_desc", type="primary"):
                create_block("benefits_description", DescriptionContent(text=plain_text, html=html_content))
                st.success("Benefits description created!")
                st.rerun()

with tab4:
    st.subheader("📚 Resources Section")
    blocks = get_blocks_by_section("resources")

    heading_block = next((b for b in blocks if b.type == "resources_heading"), None)
    card_blocks = [b for b in blocks if b.type == "resource_card"]

    with st.expander("Heading", expanded=True):
        if heading_block:
            line1 = st.text_input("Line 1", value=heading_block.content.line1)
            line2 = st.text_input("Line 2 (Italic)", value=heading_block.content.line2_italic)
            if st.button("Update Resources Heading", key="update_resources_heading"):
                full_html = f"{line1}<br><span class=\"italic text-secondary\">{line2}</span>"
                update_block(heading_block.id, content=HeadingContent(
                    line1=line1,
                    line2_italic=line2,
                    full_html=full_html
                ))
                st.success("Resources heading updated!")
                st.rerun()
        else:
            line1 = st.text_input("Line 1", value="Preparation")
            line2 = st.text_input("Line 2 (Italic)", value="Resources")
            if st.button("Create Resources Heading", key="create_resources_heading"):
                full_html = f"{line1}<br><span class=\"italic text-secondary\">{line2}</span>"
                create_block("resources_heading", HeadingContent(
                    line1=line1,
                    line2_italic=line2,
                    full_html=full_html
                ))
                st.success("Resources heading created!")
                st.rerun()

    st.markdown("### Resource Cards")

    for idx, card in enumerate(card_blocks):
        with st.expander(f"Card {idx + 1}: {card.content.title}", expanded=True):
            badge_type = st.text_input("Badge Type", value=card.content.badge_type, key=f"card_{idx}_badge")
            title = st.text_input("Title", value=card.content.title, key=f"card_{idx}_title")
            url = st.text_input("URL", value=card.content.url, key=f"card_{idx}_url")
            icon = st.text_input("Icon", value=card.content.icon, key=f"card_{idx}_icon")

            col1, col2 = st.columns(2)
            with col1:
                if st.button("Update Card", key=f"update_card_{idx}"):
                    update_block(card.id, content=ResourceCardContent(
                        badge_type=badge_type,
                        title=title,
                        url=url,
                        icon=icon
                    ))
                    st.success("Card updated!")
                    st.rerun()
            with col2:
                if st.button("Delete Card", key=f"delete_card_{idx}"):
                    delete_block(card.id)
                    st.success("Card deleted!")
                    st.rerun()

    if st.button("➕ Add New Resource Card"):
        create_block("resource_card", ResourceCardContent(
            badge_type="PDF",
            title="New Resource",
            url="resources.html",
            icon="download"
        ))
        st.success("Card created!")
        st.rerun()

with tab5:
    st.subheader("💬 Support Section")
    blocks = get_blocks_by_section("support")

    heading_block = next((b for b in blocks if b.type == "support_heading"), None)
    description_block = next((b for b in blocks if b.type == "support_description"), None)
    contact_blocks = [b for b in blocks if b.type == "support_contact"]

    with st.expander("Heading", expanded=True):
        if heading_block:
            line1 = st.text_input("Line 1", value=heading_block.content.line1)
            line2 = st.text_input("Line 2 (Italic)", value=heading_block.content.line2_italic)
            if st.button("Update Support Heading", key="update_support_heading"):
                full_html = f"{line1}<br><span class=\"italic font-normal\">{line2}</span>"
                update_block(heading_block.id, content=HeadingContent(
                    line1=line1,
                    line2_italic=line2,
                    full_html=full_html
                ))
                st.success("Support heading updated!")
                st.rerun()

    with st.expander("Description", expanded=True):
        st.markdown("**Rich Text Editor** - Use the toolbar to format text (bold, italic, links, lists)")

        if description_block:
            # Get initial HTML content if it exists, otherwise use text
            initial_html = getattr(description_block.content, 'html', None) or description_block.content.text

            # Rich text editor
            html_content = rich_text_editor(
                key="support_desc",
                initial_content=initial_html,
                height=200
            )

            # Also store plain text version (strip HTML tags for fallback)
            import re
            plain_text = re.sub('<[^<]+?>', '', html_content) if html_content else ""

            col1, col2 = st.columns(2)
            with col1:
                if st.button("✅ Update Description", key="update_support_desc", type="primary"):
                    update_block(description_block.id, content=DescriptionContent(text=plain_text, html=html_content))
                    st.success("Support description updated!")
                    st.rerun()
            with col2:
                if st.button("🗑️ Clear", key="clear_support_desc"):
                    st.session_state[f"support_desc_content"] = ""
                    st.rerun()
        else:
            # Rich text editor for new description
            html_content = rich_text_editor(
                key="support_desc_new",
                initial_content="",
                height=200
            )

            import re
            plain_text = re.sub('<[^<]+?>', '', html_content) if html_content else ""

            if st.button("✅ Create Description", key="create_support_desc", type="primary"):
                create_block("support_description", DescriptionContent(text=plain_text, html=html_content))
                st.success("Support description created!")
                st.rerun()

    st.markdown("### Contact Information")

    for idx, contact in enumerate(contact_blocks):
        with st.expander(f"Contact {idx + 1}: {contact.content.label}", expanded=True):
            label = st.text_input("Label", value=contact.content.label, key=f"contact_{idx}_label")
            value = st.text_input("Value", value=contact.content.value, key=f"contact_{idx}_value")
            icon = st.text_input("Icon", value=contact.content.icon, key=f"contact_{idx}_icon")

            if st.button("Update Contact", key=f"update_contact_{idx}"):
                update_block(contact.id, content=SupportContactContent(
                    label=label,
                    value=value,
                    icon=icon
                ))
                st.success("Contact updated!")
                st.rerun()

with tab6:
    st.subheader("📋 Footer Section")
    blocks = get_blocks_by_section("footer")

    copyright_block = next((b for b in blocks if b.type == "footer_copyright"), None)
    links_block = next((b for b in blocks if b.type == "footer_links"), None)

    with st.expander("Copyright Text", expanded=True):
        if copyright_block:
            text = st.text_area("Copyright Text", value=copyright_block.content.text, height=100)
            if st.button("Update Copyright", key="update_copyright"):
                update_block(copyright_block.id, content=FooterCopyrightContent(text=text))
                st.success("Copyright updated!")
                st.rerun()
        else:
            text = st.text_area("Copyright Text", value="© 2024 National Assessment Test Centre.\nPursuing Academic Excellence.", height=100)
            if st.button("Create Copyright", key="create_copyright"):
                create_block("footer_copyright", FooterCopyrightContent(text=text))
                st.success("Copyright created!")
                st.rerun()

    with st.expander("Footer Links", expanded=True):
        if links_block:
            st.markdown("Edit links (one per line, format: Label|URL):")
            links_text = st.text_area(
                "Links",
                value="\n".join([f"{link['label']}|{link['url']}" for link in links_block.content.links]),
                height=150
            )

            if st.button("Update Links", key="update_links"):
                links = []
                for line in links_text.strip().split("\n"):
                    if "|" in line:
                        label, url = line.split("|", 1)
                        links.append({"label": label.strip(), "url": url.strip()})

                update_block(links_block.id, content=FooterLinksContent(links=links))
                st.success("Links updated!")
                st.rerun()
        else:
            st.markdown("Add links (one per line, format: Label|URL):")
            links_text = st.text_area(
                "Links",
                value="Privacy Policy|#\nTerms of Service|#\nAccessibility|#\nStaff Portal|#",
                height=150
            )

            if st.button("Create Links", key="create_links"):
                links = []
                for line in links_text.strip().split("\n"):
                    if "|" in line:
                        label, url = line.split("|", 1)
                        links.append({"label": label.strip(), "url": url.strip()})

                create_block("footer_links", FooterLinksContent(links=links))
                st.success("Links created!")
                st.rerun()
