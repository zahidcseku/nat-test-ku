import pytest
import sqlite3
import re
from datetime import datetime
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).parent.parent))

from core.database import get_connection, init_db, get_db_path
from core.exam_dates_crud import (
    create_exam_date,
    get_exam_date,
    get_all_exam_dates,
    update_exam_date,
    delete_exam_date
)

@pytest.fixture(autouse=True)
def isolate_tests(tmp_path, monkeypatch):
    """Use temporary database for each test."""
    test_db = tmp_path / "test.db"
    monkeypatch.setenv("DATABASE_PATH", str(test_db))
    yield
    if test_db.exists():
        test_db.unlink()


def test_create_exam_date_generates_uuid():
    """Test that creating an exam date auto-generates a UUID."""
    init_db()

    exam_id = create_exam_date(
        exam_date="2026-07-15",
        registration_deadline="2026-06-30",
        levels=["1Q", "2Q", "3Q"]
    )

    # Verify it's a valid UUID format
    uuid_pattern = re.compile(
        r'^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$',
        re.IGNORECASE
    )
    assert uuid_pattern.match(exam_id), f"Generated ID {exam_id} is not a valid UUID"


def test_create_exam_date_with_levels():
    """Test creating an exam date with multiple levels."""
    init_db()

    exam_id = create_exam_date(
        exam_date="2026-07-15",
        registration_deadline="2026-06-30",
        levels=["1Q", "2Q", "3Q"]
    )

    # Verify exam date was created
    exam = get_exam_date(exam_id)
    assert exam is not None
    assert exam["exam_date"] == "2026-07-15"
    assert exam["registration_deadline"] == "2026-06-30"
    assert set(exam["levels"]) == {"1Q", "2Q", "3Q"}


def test_get_exam_date_not_found():
    """Test getting a non-existent exam date returns None."""
    init_db()

    exam = get_exam_date("non-existent-id")
    assert exam is None


def test_get_all_exam_dates():
    """Test retrieving all exam dates."""
    init_db()

    # Create multiple exam dates
    create_exam_date("2026-07-15", "2026-06-30", ["1Q"])
    create_exam_date("2026-08-20", "2026-07-31", ["4Q", "5Q"])

    exams = get_all_exam_dates()
    assert len(exams) == 2
    assert exams[0]["exam_date"] == "2026-07-15"
    assert exams[1]["exam_date"] == "2026-08-20"


def test_update_exam_date():
    """Test updating an existing exam date."""
    init_db()

    exam_id = create_exam_date("2026-07-15", "2026-06-30", ["1Q"])

    # Update exam date and levels
    success = update_exam_date(
        exam_id,
        exam_date="2026-08-15",
        levels=["2Q", "3Q"]
    )

    assert success is True

    # Verify updates
    exam = get_exam_date(exam_id)
    assert exam["exam_date"] == "2026-08-15"
    assert exam["registration_deadline"] == "2026-06-30"  # Unchanged
    assert set(exam["levels"]) == {"2Q", "3Q"}


def test_update_exam_date_not_found():
    """Test updating a non-existent exam date returns False."""
    init_db()

    success = update_exam_date("non-existent-id", exam_date="2026-08-15")
    assert success is False


def test_delete_exam_date():
    """Test deleting an exam date."""
    init_db()

    exam_id = create_exam_date("2026-07-15", "2026-06-30", ["1Q"])

    # Delete exam date
    success = delete_exam_date(exam_id)
    assert success is True

    # Verify it's gone
    exam = get_exam_date(exam_id)
    assert exam is None

    # Verify levels were cascade deleted
    with get_connection() as conn:
        levels = conn.execute(
            "SELECT COUNT(*) as count FROM exam_levels WHERE exam_date_id = ?",
            (exam_id,)
        ).fetchone()
        assert levels["count"] == 0


def test_delete_exam_date_not_found():
    """Test deleting a non-existent exam date returns False."""
    init_db()

    success = delete_exam_date("non-existent-id")
    assert success is False
