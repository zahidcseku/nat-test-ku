"""Tests for Pydantic models.

This module contains comprehensive validation tests for all Pydantic models,
ensuring data integrity and proper validation behavior.
"""

import pytest
import sqlite3
from core.models import (
    HeroContent,
    BannerContent,
    HeadingTextContent,
    CardContent,
    FooterContent,
    ContentBlock,
    Image,
)


class TestHeroContent:
    """Tests for HeroContent model."""

    def test_hero_content_valid(self):
        """Test valid hero content."""
        content = HeroContent(
            slogan="Test Slogan",
            description="Test description",
            image_url="/media/test.jpg",
            primary_link={"label": "Register", "url": "/register.html"},
            secondary_link={"label": "Learn More", "url": "/about.html"}
        )
        assert content.slogan == "Test Slogan"
        assert content.primary_link["label"] == "Register"

    def test_hero_content_invalid_url(self):
        """Test hero content rejects invalid URLs."""
        with pytest.raises(ValueError, match="URL must start with"):
            HeroContent(
                slogan="Test",
                description="Test",
                image_url="/media/test.jpg",
                primary_link={"label": "Register", "url": "invalid-url"},
                secondary_link={"label": "Learn", "url": "/about.html"}
            )

    def test_hero_content_missing_link_keys(self):
        """Test hero content rejects links without label/url."""
        with pytest.raises(ValueError, match="Link must have"):
            HeroContent(
                slogan="Test",
                description="Test",
                image_url="/media/test.jpg",
                primary_link={"label": "Register"},
                secondary_link={"label": "Learn", "url": "/about.html"}
            )

    def test_hero_slogan_max_length(self):
        """Test slogan enforces max length."""
        long_slogan = "x" * 201
        with pytest.raises(ValueError):
            HeroContent(
                slogan=long_slogan,
                description="Test",
                image_url="/media/test.jpg",
                primary_link={"label": "Register", "url": "/register.html"},
                secondary_link={"label": "Learn", "url": "/about.html"}
            )

    def test_hero_description_max_length(self):
        """Test description enforces max length."""
        long_description = "x" * 501
        with pytest.raises(ValueError):
            HeroContent(
                slogan="Test",
                description=long_description,
                image_url="/media/test.jpg",
                primary_link={"label": "Register", "url": "/register.html"},
                secondary_link={"label": "Learn", "url": "/about.html"}
            )


class TestBannerContent:
    """Tests for BannerContent model."""

    def test_banner_content_valid(self):
        """Test valid banner content."""
        content = BannerContent(
            exam_date="2026-06-15",
            exam_info_url="/resources/exam-info.html",
            registration_url="/registration.html"
        )
        assert content.exam_date == "2026-06-15"

    def test_banner_content_invalid_url(self):
        """Test banner content rejects invalid URLs."""
        with pytest.raises(ValueError, match="URL must start with"):
            BannerContent(
                exam_date="2026-06-15",
                exam_info_url="invalid-url",
                registration_url="/registration.html"
            )

    def test_banner_content_invalid_registration_url(self):
        """Test banner content rejects invalid registration URLs."""
        with pytest.raises(ValueError, match="URL must start with"):
            BannerContent(
                exam_date="2026-06-15",
                exam_info_url="/resources/exam-info.html",
                registration_url="ftp://example.com"
            )


class TestCardContent:
    """Tests for CardContent model."""

    def test_card_content_valid(self):
        """Test valid card content."""
        content = CardContent(
            title="Resources",
            description="Access study materials",
            link_url="/resources.html",
            icon_name="library_books"
        )
        assert content.title == "Resources"
        assert content.icon_name == "library_books"

    def test_card_content_optional_icon(self):
        """Test card content works without icon."""
        content = CardContent(
            title="Resources",
            description="Access study materials",
            link_url="/resources.html"
        )
        assert content.icon_name is None

    def test_card_content_invalid_url(self):
        """Test card content rejects invalid URLs."""
        with pytest.raises(ValueError, match="URL must start with"):
            CardContent(
                title="Resources",
                description="Access study materials",
                link_url="javascript:alert('xss')"
            )

    def test_card_description_max_length(self):
        """Test card description enforces max length."""
        long_desc = "x" * 301
        with pytest.raises(ValueError):
            CardContent(
                title="Test",
                description=long_desc,
                link_url="/resources.html"
            )


class TestHeadingTextContent:
    """Tests for HeadingTextContent model."""

    def test_heading_text_content_valid(self):
        """Test valid heading+text content."""
        content = HeadingTextContent(
            heading="About Us",
            body_text="This is the body text"
        )
        assert content.heading == "About Us"
        assert content.body_text == "This is the body text"


class TestFooterContent:
    """Tests for FooterContent model."""

    def test_footer_content_valid(self):
        """Test valid footer content."""
        content = FooterContent(
            copyright_text="© 2026 NAT-TEST",
            links=[
                {"label": "Privacy", "url": "/privacy.html"},
                {"label": "Terms", "url": "/terms.html"}
            ]
        )
        assert len(content.links) == 2
        assert content.copyright_text == "© 2026 NAT-TEST"

    def test_footer_content_empty_links(self):
        """Test footer content with empty links."""
        content = FooterContent(
            copyright_text="© 2026 NAT-TEST",
            links=[]
        )
        assert len(content.links) == 0


class TestContentBlock:
    """Tests for ContentBlock model."""

    def test_content_block_from_db_row(self):
        """Test creating ContentBlock from database row."""
        # Create a mock row object that behaves like sqlite3.Row
        class MockRow:
            def __init__(self, data, columns):
                self._data = data
                self._columns = columns

            def __getitem__(self, key):
                if isinstance(key, int):
                    return self._data[key]
                return self._data[self._columns.index(key)]

        row = MockRow(
            (
                "block_123",
                "hero",
                "Welcome Hero",
                '{"slogan":"Welcome","description":"Join us","image_url":"/media/test.jpg","primary_link":{"label":"Register","url":"/register.html"},"secondary_link":{"label":"Learn","url":"/about.html"}}',
                0,
                1,
                "2026-04-23T10:00:00",
                "2026-04-23T10:00:00"
            ),
            ["id", "block_type", "title", "content", "display_order", "is_active", "created_at", "updated_at"]
        )

        block = ContentBlock.from_db_row(row)
        assert block.id == "block_123"
        assert block.block_type == "hero"
        assert block.title == "Welcome Hero"
        assert isinstance(block.content, HeroContent)
        assert block.content.slogan == "Welcome"
        assert block.display_order == 0
        assert block.is_active is True

    def test_content_block_to_db_dict(self):
        """Test converting ContentBlock to database dictionary."""
        block = ContentBlock(
            id="block_456",
            block_type="card",
            title="Resources",
            content=CardContent(
                title="Resources",
                description="Access materials",
                link_url="/resources.html"
            ),
            display_order=1,
            is_active=True,
            created_at="2026-04-23T10:00:00",
            updated_at="2026-04-23T10:00:00"
        )

        db_dict = block.to_db_dict()
        assert db_dict["id"] == "block_456"
        assert db_dict["block_type"] == "card"
        assert db_dict["title"] == "Resources"
        assert db_dict["display_order"] == 1
        assert db_dict["is_active"] == 1
        assert isinstance(db_dict["content"], str)

    def test_content_block_to_db_dict_inactive(self):
        """Test converting inactive ContentBlock to database dictionary."""
        block = ContentBlock(
            id="block_789",
            block_type="banner",
            title="Exam Notice",
            content=BannerContent(
                exam_date="2026-06-15",
                exam_info_url="/info.html",
                registration_url="/register.html"
            ),
            display_order=0,
            is_active=False,
            created_at="2026-04-23T10:00:00",
            updated_at="2026-04-23T10:00:00"
        )

        db_dict = block.to_db_dict()
        assert db_dict["is_active"] == 0


class TestImage:
    """Tests for Image model."""

    def test_image_valid(self):
        """Test creating valid image."""
        image = Image(
            id="img_123",
            original_filename="photo.jpg",
            original_path="/uploads/photo.jpg",
            uploaded_at="2026-04-23T10:00:00"
        )
        assert image.id == "img_123"
        assert image.original_filename == "photo.jpg"
        assert image.alt_text is None

    def test_image_with_metadata(self):
        """Test image with optional metadata."""
        image = Image(
            id="img_456",
            original_filename="banner.png",
            original_path="/uploads/banner.png",
            optimized_path="/optimized/banner.webp",
            alt_text="Event banner",
            file_size_bytes=102400,
            width=1920,
            height=1080,
            uploaded_at="2026-04-23T10:00:00"
        )
        assert image.optimized_path == "/optimized/banner.webp"
        assert image.file_size_bytes == 102400
        assert image.width == 1920
        assert image.height == 1080
