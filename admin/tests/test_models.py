"""Tests for Pydantic models.

This module contains comprehensive validation tests for all Pydantic models,
ensuring data integrity and proper validation behavior.
"""

import pytest
from datetime import datetime
from core.models import (
    HeroContent,
    BannerContent,
    HeadingTextContent,
    CardContent,
    FooterContent,
    ContentBlock,
    Image,
    ContentBlockCreate,
    ContentBlockUpdate,
    ImageCreate,
    ImageUpdate,
    content_block_to_dict,
    dict_to_content_block,
    image_to_dict,
    dict_to_image,
)


class TestHeroContent:
    """Tests for HeroContent model."""

    def test_valid_hero_content(self):
        """Test creating valid hero content."""
        hero = HeroContent(
            title="Welcome",
            subtitle="Join us for an amazing event",
            cta_text="Register Now",
            cta_link="/register"
        )
        assert hero.block_type == "hero"
        assert hero.title == "Welcome"
        assert hero.cta_link == "/register"

    def test_hero_content_with_background(self):
        """Test hero content with background image."""
        hero = HeroContent(
            title="Conference 2026",
            subtitle="Annual Research Symposium",
            background_image_id="img_123"
        )
        assert hero.background_image_id == "img_123"
        assert hero.cta_text is None

    def test_hero_content_invalid_link(self):
        """Test hero content rejects invalid links."""
        with pytest.raises(ValueError, match="Link must start with"):
            HeroContent(
                title="Test",
                subtitle="Test subtitle",
                cta_link="invalid-link"
            )

    def test_hero_content_empty_link(self):
        """Test hero content accepts empty link."""
        hero = HeroContent(
            title="Test",
            subtitle="Test subtitle",
            cta_link=""
        )
        assert hero.cta_link is None

    def test_hero_title_max_length(self):
        """Test hero title enforces max length."""
        with pytest.raises(ValueError):
            HeroContent(
                title="x" * 201,
                subtitle="Valid subtitle"
            )

    def test_hero_subtitle_max_length(self):
        """Test hero subtitle enforces max length."""
        with pytest.raises(ValueError):
            HeroContent(
                title="Valid title",
                subtitle="x" * 501
            )


class TestBannerContent:
    """Tests for BannerContent model."""

    def test_valid_banner_content(self):
        """Test creating valid banner content."""
        banner = BannerContent(
            text="Important announcement",
            link="/news/123"
        )
        assert banner.block_type == "banner"
        assert banner.text == "Important announcement"
        assert banner.is_dismissible is True

    def test_banner_with_mailto_link(self):
        """Test banner accepts mailto links."""
        banner = BannerContent(
            text="Contact us",
            link="mailto:info@example.com"
        )
        assert banner.link == "mailto:info@example.com"

    def test_banner_non_dismissible(self):
        """Test banner can be non-dismissible."""
        banner = BannerContent(
            text="Critical notice",
            is_dismissible=False
        )
        assert banner.is_dismissible is False

    def test_banner_invalid_link(self):
        """Test banner rejects invalid links."""
        with pytest.raises(ValueError, match="Link must start with"):
            BannerContent(
                text="Test",
                link="ftp://example.com"
            )

    def test_banner_text_max_length(self):
        """Test banner text enforces max length."""
        with pytest.raises(ValueError):
            BannerContent(text="x" * 501)


class TestHeadingTextContent:
    """Tests for HeadingTextContent model."""

    def test_valid_heading_text(self):
        """Test creating valid heading text content."""
        content = HeadingTextContent(
            heading="About the Event",
            text="This is a detailed description of the event.",
            display_order=1
        )
        assert content.block_type == "heading_text"
        assert content.heading == "About the Event"
        assert content.display_order == 1

    def test_heading_text_default_order(self):
        """Test heading text has default display order."""
        content = HeadingTextContent(
            heading="Heading",
            text="Content"
        )
        assert content.display_order == 0

    def test_heading_text_negative_order(self):
        """Test heading text rejects negative display order."""
        with pytest.raises(ValueError):
            HeadingTextContent(
                heading="Heading",
                text="Content",
                display_order=-1
            )

    def test_heading_max_length(self):
        """Test heading enforces max length."""
        with pytest.raises(ValueError):
            HeadingTextContent(
                heading="x" * 201,
                text="Valid text"
            )

    def test_text_max_length(self):
        """Test text enforces max length."""
        with pytest.raises(ValueError):
            HeadingTextContent(
                heading="Valid heading",
                text="x" * 5001
            )


class TestCardContent:
    """Tests for CardContent model."""

    def test_valid_card_content(self):
        """Test creating valid card content."""
        card = CardContent(
            title="Research Track",
            description="Present your research papers",
            link="/tracks/research",
            display_order=2
        )
        assert card.block_type == "card"
        assert card.title == "Research Track"
        assert card.link == "/tracks/research"

    def test_card_with_image(self):
        """Test card with image reference."""
        card = CardContent(
            title="Workshop",
            description="Hands-on sessions",
            image_id="img_456"
        )
        assert card.image_id == "img_456"
        assert card.link is None

    def test_card_invalid_link(self):
        """Test card rejects invalid links."""
        with pytest.raises(ValueError, match="Link must start with"):
            CardContent(
                title="Test",
                description="Test description",
                link="javascript:alert('xss')"
            )

    def test_card_title_max_length(self):
        """Test card title enforces max length."""
        with pytest.raises(ValueError):
            CardContent(
                title="x" * 201,
                description="Valid description"
            )

    def test_card_description_max_length(self):
        """Test card description enforces max length."""
        with pytest.raises(ValueError):
            CardContent(
                title="Valid title",
                description="x" * 1001
            )


class TestFooterContent:
    """Tests for FooterContent model."""

    def test_valid_footer_content(self):
        """Test creating valid footer content."""
        footer = FooterContent(
            text="© 2026 Conference. All rights reserved.",
            copyright_text="Conference Organizing Committee"
        )
        assert footer.block_type == "footer"
        assert footer.text == "© 2026 Conference. All rights reserved."
        assert footer.links == []

    def test_footer_with_links(self):
        """Test footer with valid links."""
        footer = FooterContent(
            text="Contact us",
            links=[
                {"url": "/contact", "text": "Contact"},
                {"url": "https://twitter.com/conf", "text": "Twitter"}
            ]
        )
        assert len(footer.links) == 2
        assert footer.links[0]["url"] == "/contact"

    def test_footer_invalid_link_url(self):
        """Test footer rejects invalid link URLs."""
        with pytest.raises(ValueError, match="Link URL must start with"):
            FooterContent(
                text="Footer",
                links=[{"url": "invalid-url", "text": "Link"}]
            )

    def test_footer_link_without_url(self):
        """Test footer requires url field in links."""
        with pytest.raises(ValueError, match="must have a 'url' field"):
            FooterContent(
                text="Footer",
                links=[{"text": "Link without URL"}]
            )

    def test_footer_non_dict_link(self):
        """Test footer requires dict for links."""
        with pytest.raises(ValueError, match="should be a valid dictionary"):
            FooterContent(
                text="Footer",
                links=["not-a-dict"]
            )

    def test_footer_text_max_length(self):
        """Test footer text enforces max length."""
        with pytest.raises(ValueError):
            FooterContent(text="x" * 1001)


class TestContentBlock:
    """Tests for ContentBlock model."""

    def test_valid_content_block(self):
        """Test creating valid content block."""
        now = datetime.now().isoformat()
        block = ContentBlock(
            id="block_123",
            block_type="hero",
            title="Welcome",
            content='{"title":"Welcome","subtitle":"Join us"}',
            display_order=1,
            is_active=True,
            created_at=now,
            updated_at=now
        )
        assert block.id == "block_123"
        assert block.block_type == "hero"
        assert block.is_active is True

    def test_content_block_invalid_block_type(self):
        """Test content block rejects invalid block type."""
        with pytest.raises(ValueError):
            ContentBlock(
                id="block_123",
                block_type="invalid_type",
                content="{}",
                created_at="2026-04-23T00:00:00",
                updated_at="2026-04-23T00:00:00"
            )

    def test_content_block_invalid_datetime(self):
        """Test content block rejects invalid datetime format."""
        with pytest.raises(ValueError, match="Datetime must be in ISO 8601 format"):
            ContentBlock(
                id="block_123",
                block_type="banner",
                content="{}",
                created_at="invalid-date",
                updated_at="2026-04-23T00:00:00"
            )

    def test_content_block_negative_order(self):
        """Test content block rejects negative display order."""
        now = datetime.now().isoformat()
        with pytest.raises(ValueError):
            ContentBlock(
                id="block_123",
                block_type="card",
                content="{}",
                display_order=-1,
                created_at=now,
                updated_at=now
            )

    def test_content_block_optional_title(self):
        """Test content block title is optional."""
        now = datetime.now().isoformat()
        block = ContentBlock(
            id="block_123",
            block_type="heading_text",
            content="{}",
            title=None,
            created_at=now,
            updated_at=now
        )
        assert block.title is None


class TestImage:
    """Tests for Image model."""

    def test_valid_image(self):
        """Test creating valid image."""
        now = datetime.now().isoformat()
        image = Image(
            id="img_123",
            original_filename="photo.jpg",
            original_path="/uploads/photo.jpg",
            uploaded_at=now
        )
        assert image.id == "img_123"
        assert image.original_filename == "photo.jpg"
        assert image.alt_text is None

    def test_image_with_metadata(self):
        """Test image with optional metadata."""
        now = datetime.now().isoformat()
        image = Image(
            id="img_456",
            original_filename="banner.png",
            original_path="/uploads/banner.png",
            optimized_path="/optimized/banner.webp",
            alt_text="Event banner",
            file_size_bytes=102400,
            width=1920,
            height=1080,
            uploaded_at=now
        )
        assert image.optimized_path == "/optimized/banner.webp"
        assert image.file_size_bytes == 102400
        assert image.width == 1920

    def test_image_invalid_datetime(self):
        """Test image rejects invalid datetime format."""
        with pytest.raises(ValueError, match="Datetime must be in ISO 8601 format"):
            Image(
                id="img_123",
                original_filename="test.jpg",
                original_path="/uploads/test.jpg",
                uploaded_at="not-a-date"
            )

    def test_image_negative_size(self):
        """Test image rejects negative file size."""
        now = datetime.now().isoformat()
        with pytest.raises(ValueError):
            Image(
                id="img_123",
                original_filename="test.jpg",
                original_path="/uploads/test.jpg",
                file_size_bytes=-100,
                uploaded_at=now
            )

    def test_image_negative_dimensions(self):
        """Test image rejects negative dimensions."""
        now = datetime.now().isoformat()
        with pytest.raises(ValueError):
            Image(
                id="img_123",
                original_filename="test.jpg",
                original_path="/uploads/test.jpg",
                width=-1920,
                uploaded_at=now
            )

    def test_image_filename_max_length(self):
        """Test image filename enforces max length."""
        now = datetime.now().isoformat()
        with pytest.raises(ValueError):
            Image(
                id="img_123",
                original_filename="x" * 256,
                original_path="/uploads/test.jpg",
                uploaded_at=now
            )


class TestContentBlockCreate:
    """Tests for ContentBlockCreate model."""

    def test_valid_create(self):
        """Test valid content block creation."""
        create = ContentBlockCreate(
            block_type="hero",
            title="Welcome",
            content='{"title":"Welcome"}',
            display_order=1
        )
        assert create.block_type == "hero"
        assert create.display_order == 1

    def test_create_defaults(self):
        """Test content block create has defaults."""
        create = ContentBlockCreate(
            block_type="banner",
            content="{}"
        )
        assert create.title is None
        assert create.display_order == 0


class TestContentBlockUpdate:
    """Tests for ContentBlockUpdate model."""

    def test_valid_update(self):
        """Test valid content block update."""
        update = ContentBlockUpdate(
            title="Updated Title",
            is_active=False
        )
        assert update.title == "Updated Title"
        assert update.is_active is False

    def test_update_all_fields(self):
        """Test updating all fields."""
        update = ContentBlockUpdate(
            title="New Title",
            content="New content",
            display_order=5,
            is_active=True
        )
        assert update.content == "New content"
        assert update.display_order == 5


class TestImageCreate:
    """Tests for ImageCreate model."""

    def test_valid_image_create(self):
        """Test valid image creation."""
        create = ImageCreate(
            original_filename="test.jpg",
            original_path="/uploads/test.jpg"
        )
        assert create.original_filename == "test.jpg"
        assert create.alt_text is None


class TestImageUpdate:
    """Tests for ImageUpdate model."""

    def test_valid_image_update(self):
        """Test valid image update."""
        update = ImageUpdate(
            alt_text="Updated alt text",
            optimized_path="/optimized/test.webp"
        )
        assert update.alt_text == "Updated alt text"


class TestSerializationHelpers:
    """Tests for serialization helper functions."""

    def test_content_block_to_dict(self):
        """Test converting content block to dict."""
        now = datetime.now().isoformat()
        block = ContentBlock(
            id="block_123",
            block_type="hero",
            content="{}",
            created_at=now,
            updated_at=now
        )
        data = content_block_to_dict(block)
        assert isinstance(data, dict)
        assert data["id"] == "block_123"
        assert data["block_type"] == "hero"

    def test_dict_to_content_block(self):
        """Test converting dict to content block."""
        now = datetime.now().isoformat()
        data = {
            "id": "block_456",
            "block_type": "banner",
            "content": "{}",
            "created_at": now,
            "updated_at": now
        }
        block = dict_to_content_block(data)
        assert isinstance(block, ContentBlock)
        assert block.id == "block_456"

    def test_image_to_dict(self):
        """Test converting image to dict."""
        now = datetime.now().isoformat()
        image = Image(
            id="img_123",
            original_filename="test.jpg",
            original_path="/uploads/test.jpg",
            uploaded_at=now
        )
        data = image_to_dict(image)
        assert isinstance(data, dict)
        assert data["id"] == "img_123"

    def test_dict_to_image(self):
        """Test converting dict to image."""
        now = datetime.now().isoformat()
        data = {
            "id": "img_456",
            "original_filename": "test.png",
            "original_path": "/uploads/test.png",
            "uploaded_at": now
        }
        image = dict_to_image(data)
        assert isinstance(image, Image)
        assert image.id == "img_456"
