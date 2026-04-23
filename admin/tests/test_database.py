import pytest
import os
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).parent.parent))

from core.database import get_connection, init_db, get_db_path, backup_database

@pytest.fixture(autouse=True)
def isolate_tests(tmp_path, monkeypatch):
    """Use temporary database for each test."""
    test_db = tmp_path / "test.db"
    monkeypatch.setenv("DATABASE_PATH", str(test_db))
    yield
    if test_db.exists():
        test_db.unlink()

def test_init_db_creates_tables():
    """Test that init_db creates all required tables."""
    init_db()

    with get_connection() as conn:
        tables = conn.execute(
            "SELECT name FROM sqlite_master WHERE type='table'"
        ).fetchall()
        table_names = [t["name"] for t in tables]

        assert "content_blocks" in table_names
        assert "images" in table_names

def test_content_blocks_schema():
    """Test content_blocks table has correct columns."""
    init_db()

    with get_connection() as conn:
        columns = conn.execute("PRAGMA table_info(content_blocks)").fetchall()
        column_names = [c["name"] for c in columns]

        assert "id" in column_names
        assert "block_type" in column_names
        assert "title" in column_names
        assert "content" in column_names
        assert "display_order" in column_names
        assert "is_active" in column_names
        assert "created_at" in column_names
        assert "updated_at" in column_names

def test_images_schema():
    """Test images table has correct columns."""
    init_db()

    with get_connection() as conn:
        columns = conn.execute("PRAGMA table_info(images)").fetchall()
        column_names = [c["name"] for c in columns]

        assert "id" in column_names
        assert "original_filename" in column_names
        assert "original_path" in column_names
        assert "optimized_path" in column_names
        assert "alt_text" in column_names
        assert "uploaded_at" in column_names
        assert "file_size_bytes" in column_names
        assert "width" in column_names
        assert "height" in column_names

def test_backup_database():
    """Test that backup_database creates a backup file."""
    init_db()

    backup_path = backup_database()
    backup_file = Path(backup_path)

    assert backup_file.exists()
    assert backup_file.parent.name == "backups"
    assert "admin_" in backup_file.name
