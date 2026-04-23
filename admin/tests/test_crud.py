"""Tests for CRUD operations."""

import pytest
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).parent.parent))

from core.database import init_db
from core.crud import create_block, get_block, list_blocks, update_block, delete_block
from core.models import HeroContent, BannerContent, BlockType


@pytest.fixture(autouse=True)
def setup_database(tmp_path, monkeypatch):
    """Initialize database for each test with temporary file."""
    test_db = tmp_path / "test.db"
    monkeypatch.setenv("DATABASE_PATH", str(test_db))
    init_db()
    yield
    if test_db.exists():
        test_db.unlink()


def test_create_hero_block():
    """Test creating a hero block."""
    content = HeroContent(
        slogan="Test Slogan",
        description="Test description",
        image_url="/media/test.jpg",
        primary_link={"label": "Register", "url": "/register.html"},
        secondary_link={"label": "Learn More", "url": "/about.html"}
    )

    block = create_block(
        block_type="hero",
        title="Main Hero",
        content=content
    )

    assert block.id is not None
    assert block.block_type == "hero"
    assert block.title == "Main Hero"
    assert block.content.slogan == "Test Slogan"
    assert block.is_active is True


def test_get_block():
    """Test retrieving a block by ID."""
    content = HeroContent(
        slogan="Test",
        description="Test",
        image_url="/media/test.jpg",
        primary_link={"label": "Register", "url": "/register.html"},
        secondary_link={"label": "Learn", "url": "/about.html"}
    )

    created = create_block(block_type="hero", title="Test", content=content)
    retrieved = get_block(created.id)

    assert retrieved is not None
    assert retrieved.id == created.id
    assert retrieved.title == "Test"


def test_get_nonexistent_block():
    """Test getting a block that doesn't exist returns None."""
    result = get_block("nonexistent-id")
    assert result is None


def test_list_blocks_all():
    """Test listing all blocks."""
    content1 = HeroContent(
        slogan="Test1",
        description="Test",
        image_url="/media/test.jpg",
        primary_link={"label": "Register", "url": "/register.html"},
        secondary_link={"label": "Learn", "url": "/about.html"}
    )
    content2 = BannerContent(
        exam_date="2026-06-15",
        exam_info_url="/info.html",
        registration_url="/register.html"
    )

    create_block(block_type="hero", title="Hero 1", content=content1)
    create_block(block_type="banner", title="Banner 1", content=content2)

    blocks = list_blocks()
    assert len(blocks) == 2


def test_list_blocks_by_type():
    """Test filtering blocks by type."""
    content = HeroContent(
        slogan="Test",
        description="Test",
        image_url="/media/test.jpg",
        primary_link={"label": "Register", "url": "/register.html"},
        secondary_link={"label": "Learn", "url": "/about.html"}
    )

    create_block(block_type="hero", title="Hero", content=content)

    hero_blocks = list_blocks(block_type="hero")
    assert len(hero_blocks) == 1
    assert hero_blocks[0].block_type == "hero"


def test_list_active_blocks_only():
    """Test filtering for active blocks only."""
    content = HeroContent(
        slogan="Test",
        description="Test",
        image_url="/media/test.jpg",
        primary_link={"label": "Register", "url": "/register.html"},
        secondary_link={"label": "Learn", "url": "/about.html"}
    )

    block = create_block(block_type="hero", title="Hero", content=content)
    delete_block(block.id, hard_delete=False)

    active_blocks = list_blocks(active_only=True)
    assert len(active_blocks) == 0

    all_blocks = list_blocks(active_only=False)
    assert len(all_blocks) == 1


def test_update_block():
    """Test updating a block."""
    content = HeroContent(
        slogan="Test",
        description="Test",
        image_url="/media/test.jpg",
        primary_link={"label": "Register", "url": "/register.html"},
        secondary_link={"label": "Learn", "url": "/about.html"}
    )

    block = create_block(block_type="hero", title="Original", content=content)

    updated = update_block(block.id, title="Updated")
    assert updated.title == "Updated"


def test_update_block_not_found():
    """Test updating nonexistent block returns None."""
    result = update_block("nonexistent", title="Test")
    assert result is None


def test_soft_delete_block():
    """Test soft deleting a block."""
    content = HeroContent(
        slogan="Test",
        description="Test",
        image_url="/media/test.jpg",
        primary_link={"label": "Register", "url": "/register.html"},
        secondary_link={"label": "Learn", "url": "/about.html"}
    )

    block = create_block(block_type="hero", title="Test", content=content)
    delete_block(block.id, hard_delete=False)

    updated = get_block(block.id)
    assert updated is not None
    assert updated.is_active is False


def test_hard_delete_block():
    """Test hard deleting a block."""
    content = HeroContent(
        slogan="Test",
        description="Test",
        image_url="/media/test.jpg",
        primary_link={"label": "Register", "url": "/register.html"},
        secondary_link={"label": "Learn", "url": "/about.html"}
    )

    block = create_block(block_type="hero", title="Test", content=content)
    delete_block(block.id, hard_delete=True)

    result = get_block(block.id)
    assert result is None


def test_display_order_auto_increment():
    """Test that display_order auto-increments."""
    content = HeroContent(
        slogan="Test",
        description="Test",
        image_url="/media/test.jpg",
        primary_link={"label": "Register", "url": "/register.html"},
        secondary_link={"label": "Learn", "url": "/about.html"}
    )

    block1 = create_block(block_type="hero", title="1", content=content)
    block2 = create_block(block_type="hero", title="2", content=content)
    block3 = create_block(block_type="hero", title="3", content=content)

    assert block1.display_order == 1
    assert block2.display_order == 2
    assert block3.display_order == 3
