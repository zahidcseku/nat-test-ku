"""Seed script to populate initial content for the home page."""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent.parent))

from core.database import init_db
from core.crud import create_block
from core.models import HeroContent, BannerContent, HeadingTextContent, CardContent, FooterContent


def seed_home_page_content():
    """Seed initial home page content."""
    init_db()

    # Hero block
    hero = create_block(
        block_type="hero",
        title="Home Page Hero",
        content=HeroContent(
            slogan="Excellence through Assessment",
            description="Join Bangladesh's premier national testing platform for academic excellence",
            image_url="/media/images/optimized/hero-home.webp",
            primary_link={"label": "Register Now", "url": "/registration.html"},
            secondary_link={"label": "Learn More", "url": "/resources.html"}
        ),
        display_order=1
    )
    print(f"Created hero block: {hero.id}")

    # Banner block
    banner = create_block(
        block_type="banner",
        title="Next Exam Banner",
        content=BannerContent(
            exam_date="2026-06-15",
            exam_info_url="/resources/exam-info.html",
            registration_url="/registration.html"
        ),
        display_order=2
    )
    print(f"Created banner block: {banner.id}")

    # Heading + Text block
    heading = create_block(
        block_type="heading_text",
        title="About Section",
        content=HeadingTextContent(
            heading="About NAT-TEST",
            body_text="The National Assessment Test Centre promotes academic excellence through standardized testing."
        ),
        display_order=3
    )
    print(f"Created heading block: {heading.id}")

    # Resources card
    resources_card = create_block(
        block_type="card",
        title="Resources Card",
        content=CardContent(
            title="Resources",
            description="Access study materials, practice tests, and preparation guides",
            link_url="/resources.html",
            icon_name="library_books"
        ),
        display_order=4
    )
    print(f"Created resources card: {resources_card.id}")

    # Assistance card
    assistance_card = create_block(
        block_type="card",
        title="Assistance Card",
        content=CardContent(
            title="Get Assistance",
            description="Contact our support team for help with registration and exams",
            link_url="/contact.html",
            icon_name="support_agent"
        ),
        display_order=5
    )
    print(f"Created assistance card: {assistance_card.id}")

    # Footer block
    footer = create_block(
        block_type="footer",
        title="Footer",
        content=FooterContent(
            copyright_text="© 2026 NAT-TEST Centre. All rights reserved.",
            links=[
                {"label": "Privacy Policy", "url": "/privacy.html"},
                {"label": "Terms of Service", "url": "/terms.html"},
                {"label": "Contact", "url": "/contact.html"}
            ]
        ),
        display_order=6
    )
    print(f"Created footer block: {footer.id}")

    print("\n✅ Seed complete! 6 blocks created.")


if __name__ == "__main__":
    seed_home_page_content()
