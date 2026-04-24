"""Seed admin database with initial content matching content.json."""

import sys
from pathlib import Path

# Add parent directory to path
sys.path.insert(0, str(Path(__file__).parent))

from core.database import init_db
from core.crud import create_block
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


def seed_database():
    """Seed the database with initial content."""
    init_db()

    print("🌱 Seeding database with initial content...")

    # Hero Section
    create_block("hero_badge", HeroBadgeContent(
        text="Official Assessment Partner"
    ))
    print("✓ Created hero badge")

    create_block("hero_headline", HeroHeadlineContent(
        line1="The Standard of",
        line2_italic="Academic Excellence.",
        line2_text="",
        full_html="The Standard of<br><span class=\"italic font-normal text-secondary\">Academic Excellence.</span>"
    ))
    print("✓ Created hero headline")

    create_block("hero_description", HeroDescriptionContent(
        text="Experience a testing environment designed for absolute focus. The National Assessment Test Centre provides rigorous, high-stakes examination facilities for the leaders of tomorrow."
    ))
    print("✓ Created hero description")

    create_block("hero_cta_primary", HeroCtaContent(
        label="Begin Registration",
        url="/registration.html",
        icon="arrow_forward"
    ))
    print("✓ Created hero primary CTA")

    create_block("hero_cta_secondary", HeroCtaContent(
        label="Download Prospectus",
        url="#",
        icon="download"
    ))
    print("✓ Created hero secondary CTA")

    # Exam Ribbon
    create_block("exam_ribbon", ExamRibbonContent(
        exam_date="October 14, 2024",
        registration_status="Registration closes in 12 days",
        exam_info_url="/resources.html",
        registration_url="/registration.html"
    ))
    print("✓ Created exam ribbon")

    # Benefits Section
    create_block("benefits_heading", HeadingContent(
        line1="Why Candidates",
        line2_italic="Choose NAT",
        line2_text="",
        full_html="Why Candidates<br><span class=\"italic text-secondary\">Choose NAT</span>"
    ))
    print("✓ Created benefits heading")

    create_block("benefits_description", DescriptionContent(
        text="We provide more than just a seat; we offer an ecosystem designed to minimize distractions and maximize performance."
    ))
    print("✓ Created benefits description")

    # Resources Section
    create_block("resources_heading", HeadingContent(
        line1="Preparation",
        line2_italic="Resources",
        line2_text="",
        full_html="Preparation<br><span class=\"italic text-secondary\">Resources</span>"
    ))
    print("✓ Created resources heading")

    create_block("resource_card", ResourceCardContent(
        badge_type="PDF",
        title="2024 Candidate Handbook",
        url="/resources.html",
        icon="download"
    ))
    print("✓ Created resource card 1")

    create_block("resource_card", ResourceCardContent(
        badge_type="CHECKLIST",
        title="Examination Day Checklist",
        url="/resources.html",
        icon="download"
    ))
    print("✓ Created resource card 2")

    create_block("resource_card", ResourceCardContent(
        badge_type="PROTOCOL",
        title="Security & Privacy Protocols",
        url="/resources.html",
        icon="description"
    ))
    print("✓ Created resource card 3")

    # Support Section
    create_block("support_heading", HeadingContent(
        line1="Need",
        line2_italic="Assistance?",
        line2_text="",
        full_html="Need<br><span class=\"italic font-normal\">Assistance?</span>"
    ))
    print("✓ Created support heading")

    create_block("support_description", DescriptionContent(
        text="Our support team is available Monday through Friday, 8:00 AM to 6:00 PM, to answer any questions regarding registration, accessibility accommodations, or result interpretation."
    ))
    print("✓ Created support description")

    create_block("support_contact", SupportContactContent(
        label="Call us",
        value="+1 (800) NAT-CORE",
        icon="call"
    ))
    print("✓ Created support contact (phone)")

    create_block("support_contact", SupportContactContent(
        label="Email us",
        value="registrar@natcentre.edu",
        icon="mail"
    ))
    print("✓ Created support contact (email)")

    # Footer
    create_block("footer_copyright", FooterCopyrightContent(
        text="© 2024 National Assessment Test Centre.\nPursuing Academic Excellence."
    ))
    print("✓ Created footer copyright")

    create_block("footer_links", FooterLinksContent(
        links=[
            {"label": "Privacy Policy", "url": "#"},
            {"label": "Terms of Service", "url": "#"},
            {"label": "Accessibility", "url": "#"},
            {"label": "Staff Portal", "url": "#"}
        ]
    ))
    print("✓ Created footer links")

    print("\n✅ Database seeded successfully!")


if __name__ == "__main__":
    seed_database()
