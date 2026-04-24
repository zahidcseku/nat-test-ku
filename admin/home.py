"""Admin home page with all content sections.

This module provides a comprehensive UI for editing all home page content
organized by sections: Hero, Exam Ribbon, Benefits, Resources, Support, Footer.
"""

import streamlit as st
from datetime import datetime
from typing import Dict, Any

from core.models import (
    ContentBlock,
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
    list_blocks
)
from core.database import init_db


def render_hero_section():
    """Render hero content editing section."""
    st.subheader("🎯 Hero Section")

    blocks = get_blocks_by_section("hero")

    # Organize blocks by type
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
        if description_block:
            text = st.text_area("Description", value=description_block.content.text, height=100)
            if st.button("Update Description", key="update_hero_desc"):
                update_block(description_block.id, content=HeroDescriptionContent(text=text))
                st.success("Description updated!")
                st.rerun()
        else:
            text = st.text_area("Description", value="Experience a testing environment designed for absolute focus.", height=100)
            if st.button("Create Description", key="create_hero_desc"):
                create_block("hero_description", HeroDescriptionContent(text=text))
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


def render_exam_section():
    """Render exam ribbon editing section."""
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


def render_benefits_section():
    """Render benefits section editing."""
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
        if description_block:
            text = st.text_area("Description", value=description_block.content.text, height=100)
            if st.button("Update Benefits Description", key="update_benefits_desc"):
                update_block(description_block.id, content=DescriptionContent(text=text))
                st.success("Benefits description updated!")
                st.rerun()
        else:
            text = st.text_area("Description", value="We provide more than just a seat...", height=100)
            if st.button("Create Benefits Description", key="create_benefits_desc"):
                create_block("benefits_description", DescriptionContent(text=text))
                st.success("Benefits description created!")
                st.rerun()


def render_resources_section():
    """Render resources section editing."""
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
                    from core.crud import delete_block
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


def render_support_section():
    """Render support section editing."""
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
        if description_block:
            text = st.text_area("Description", value=description_block.content.text, height=100)
            if st.button("Update Support Description", key="update_support_desc"):
                update_block(description_block.id, content=DescriptionContent(text=text))
                st.success("Support description updated!")
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


def render_footer_section():
    """Render footer editing section."""
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


def main():
    """Main home page editor."""
    st.set_page_config(
        page_title="Home Content Editor",
        page_icon="🏠",
        layout="wide"
    )

    st.title("🏠 Home Page Content Editor")
    st.markdown("---")

    # Initialize database
    init_db()

    # Create tabs for each section
    tab1, tab2, tab3, tab4, tab5, tab6 = st.tabs([
        "🎯 Hero", "📅 Exam Ribbon", "✨ Benefits", "📚 Resources", "💬 Support", "📋 Footer"
    ])

    with tab1:
        render_hero_section()

    with tab2:
        render_exam_section()

    with tab3:
        render_benefits_section()

    with tab4:
        render_resources_section()

    with tab5:
        render_support_section()

    with tab6:
        render_footer_section()


if __name__ == "__main__":
    main()
