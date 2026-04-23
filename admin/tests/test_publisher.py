"""Tests for publisher functionality."""

import pytest
import json
import tempfile
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).parent.parent))

from core.database import init_db
from core.publisher import export_to_json
from core.crud import create_block, delete_block
from core.models import HeroContent


@pytest.fixture(autouse=True)
def setup_database(tmp_path, monkeypatch):
    """Initialize database for each test with temporary file."""
    test_db = tmp_path / "test.db"
    monkeypatch.setenv("DATABASE_PATH", str(test_db))
    init_db()
    yield


def test_export_to_json_creates_file():
    """Test that export creates a JSON file."""
    content = HeroContent(
        slogan="Test",
        description="Test",
        image_url="/media/test.jpg",
        primary_link={"label": "Register", "url": "/register.html"},
        secondary_link={"label": "Learn", "url": "/about.html"}
    )
    create_block(block_type="hero", title="Hero", content=content)

    with tempfile.TemporaryDirectory() as tmpdir:
        output_path = Path(tmpdir) / "content.json"
        result_path = export_to_json(str(output_path))

        assert Path(result_path).exists()


def test_export_to_json_format():
    """Test that export produces correct JSON format."""
    content = HeroContent(
        slogan="Test Slogan",
        description="Test description",
        image_url="/media/test.jpg",
        primary_link={"label": "Register", "url": "/register.html"},
        secondary_link={"label": "Learn", "url": "/about.html"}
    )
    block = create_block(block_type="hero", title="Hero", content=content)

    with tempfile.TemporaryDirectory() as tmpdir:
        output_path = Path(tmpdir) / "content.json"
        export_to_json(str(output_path))

        with open(output_path) as f:
            data = json.load(f)

        assert "last_updated" in data
        assert "blocks" in data
        assert len(data["blocks"]) == 1
        assert data["blocks"][0]["type"] == "hero"
        assert data["blocks"][0]["content"]["slogan"] == "Test Slogan"


def test_export_only_active_blocks():
    """Test that export only includes active blocks."""
    content = HeroContent(
        slogan="Test",
        description="Test",
        image_url="/media/test.jpg",
        primary_link={"label": "Register", "url": "/register.html"},
        secondary_link={"label": "Learn", "url": "/about.html"}
    )

    active_block = create_block(block_type="hero", title="Active", content=content)
    inactive_block = create_block(block_type="hero", title="Inactive", content=content)

    delete_block(inactive_block.id, hard_delete=False)

    with tempfile.TemporaryDirectory() as tmpdir:
        output_path = Path(tmpdir) / "content.json"
        export_to_json(str(output_path))

        with open(output_path) as f:
            data = json.load(f)

        assert len(data["blocks"]) == 1
        assert data["blocks"][0]["id"] == active_block.id
