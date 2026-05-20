"""CRUD operations for exam dates.

This module provides all database operations for creating, reading,
updating, and deleting exam dates in the admin database.
"""

import uuid
from typing import List, Optional

from .database import get_connection, backup_database


def create_exam_date(
    exam_date: str,
    registration_deadline: str,
    levels: List[str]
) -> str:
    """Create a new exam date with auto-generated UUID.

    Args:
        exam_date: ISO format date string (e.g., '2026-07-15')
        registration_deadline: ISO format date string (e.g., '2026-06-30')
        levels: List of level strings (e.g., ['1Q', '2Q', '3Q'])

    Returns:
        The auto-generated UUID of the created exam date
    """
    backup_database()  # Backup before any write

    exam_id = str(uuid.uuid4())

    with get_connection() as conn:
        # Insert exam date
        conn.execute("""
            INSERT INTO exam_dates (id, exam_date, registration_deadline)
            VALUES (?, ?, ?)
        """, (exam_id, exam_date, registration_deadline))

        # Insert associated levels
        for level in levels:
            conn.execute("""
                INSERT INTO exam_levels (exam_date_id, level)
                VALUES (?, ?)
            """, (exam_id, level))

    return exam_id


def get_exam_date(exam_id: str) -> Optional[dict]:
    """Get a single exam date by ID with its levels."""
    with get_connection() as conn:
        exam = conn.execute("""
            SELECT id, exam_date, registration_deadline
            FROM exam_dates
            WHERE id = ?
        """, (exam_id,)).fetchone()

        if not exam:
            return None

        # Get levels for this exam
        levels = conn.execute("""
            SELECT level
            FROM exam_levels
            WHERE exam_date_id = ?
            ORDER BY level
        """, (exam_id,)).fetchall()

        return {
            "id": exam["id"],
            "exam_date": exam["exam_date"],
            "registration_deadline": exam["registration_deadline"],
            "levels": [level["level"] for level in levels]
        }


def get_all_exam_dates() -> List[dict]:
    """Get all exam dates with their levels."""
    with get_connection() as conn:
        exams = conn.execute("""
            SELECT id, exam_date, registration_deadline
            FROM exam_dates
            ORDER BY exam_date
        """).fetchall()

        result = []
        for exam in exams:
            # Get levels for this exam
            levels = conn.execute("""
                SELECT level
                FROM exam_levels
                WHERE exam_date_id = ?
                ORDER BY level
            """, (exam["id"],)).fetchall()

            result.append({
                "id": exam["id"],
                "exam_date": exam["exam_date"],
                "registration_deadline": exam["registration_deadline"],
                "levels": [level["level"] for level in levels]
            })

        return result


def update_exam_date(
    exam_id: str,
    exam_date: Optional[str] = None,
    registration_deadline: Optional[str] = None,
    levels: Optional[List[str]] = None
) -> bool:
    """Update an existing exam date.

    Args:
        exam_id: UUID of the exam date to update
        exam_date: New exam date (optional)
        registration_deadline: New registration deadline (optional)
        levels: New list of levels (optional)

    Returns:
        True if updated successfully, False if exam not found
    """
    backup_database()  # Backup before any write

    with get_connection() as conn:
        # Check if exam exists
        existing = conn.execute(
            "SELECT id FROM exam_dates WHERE id = ?", (exam_id,)
        ).fetchone()

        if not existing:
            return False

        # Update exam date fields if provided
        if exam_date or registration_deadline:
            updates = []
            params = []

            if exam_date:
                updates.append("exam_date = ?")
                params.append(exam_date)

            if registration_deadline:
                updates.append("registration_deadline = ?")
                params.append(registration_deadline)

            params.append(exam_id)

            conn.execute(f"""
                UPDATE exam_dates
                SET {', '.join(updates)}
                WHERE id = ?
            """, params)

        # Update levels if provided
        if levels is not None:
            # Delete existing levels
            conn.execute(
                "DELETE FROM exam_levels WHERE exam_date_id = ?",
                (exam_id,)
            )

            # Insert new levels
            for level in levels:
                conn.execute("""
                    INSERT INTO exam_levels (exam_date_id, level)
                    VALUES (?, ?)
                """, (exam_id, level))

    return True


def delete_exam_date(exam_id: str) -> bool:
    """Delete an exam date (and associated levels via cascade).

    Args:
        exam_id: UUID of the exam date to delete

    Returns:
        True if deleted successfully, False if exam not found
    """
    backup_database()  # Backup before any write

    with get_connection() as conn:
        # Check if exam exists
        existing = conn.execute(
            "SELECT id FROM exam_dates WHERE id = ?", (exam_id,)
        ).fetchone()

        if not existing:
            return False

        # Delete exam (levels will be cascade deleted)
        conn.execute("DELETE FROM exam_dates WHERE id = ?", (exam_id,))

    return True
