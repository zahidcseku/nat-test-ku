"""Test fixtures for admin tests."""

import pytest
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).parent.parent))

from core.database import init_db
from core.crud import create_block
from core.models import HeroContent


@pytest.fixture
def db_with_content(tmp_path, monkeypatch):
    """Fixture providing a database with sample content."""
    test_db = tmp_path / "test.db"
    monkeypatch.setenv("DATABASE_PATH", str(test_db))
    init_db()

    content = HeroContent(
        slogan="Test Slogan",
        description="Test description",
        image_url="/media/test.jpg",
        primary_link={"label": "Register", "url": "/register.html"},
        secondary_link={"label": "Learn", "url": "/about.html"}
    )

    block = create_block(
        block_type="hero",
        title="Test Hero",
        content=content
    )

    return block
