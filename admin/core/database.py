import sqlite3
import os
from pathlib import Path
from typing import Optional
from contextlib import contextmanager


def get_db_path() -> Path:
    """Get the database file path, ensuring directory exists."""
    DATABASE_PATH = os.getenv("DATABASE_PATH", "./data/admin.db")
    db_path = Path(DATABASE_PATH)
    db_path.parent.mkdir(parents=True, exist_ok=True)
    return db_path

@contextmanager
def get_connection():
    """Context manager for database connections."""
    conn = sqlite3.connect(get_db_path())
    conn.row_factory = sqlite3.Row
    # Enable foreign key support
    conn.execute("PRAGMA foreign_keys = ON")
    try:
        yield conn
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()

def init_db() -> None:
    """Initialize database schema matching content.json structure."""
    with get_connection() as conn:
        # Content blocks table - matches content.json structure
        conn.execute("""
            CREATE TABLE IF NOT EXISTS content_blocks (
                id TEXT PRIMARY KEY,
                block_type TEXT NOT NULL,
                content TEXT NOT NULL,
                display_order INTEGER NOT NULL DEFAULT 0,
                is_active BOOLEAN NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        """)

        # Images table
        conn.execute("""
            CREATE TABLE IF NOT EXISTS images (
                id TEXT PRIMARY KEY,
                original_filename TEXT NOT NULL,
                original_path TEXT NOT NULL,
                optimized_path TEXT,
                alt_text TEXT,
                uploaded_at TEXT NOT NULL,
                file_size_bytes INTEGER,
                width INTEGER,
                height INTEGER
            )
        """)

        # Exam dates table
        conn.execute("""
            CREATE TABLE IF NOT EXISTS exam_dates (
                id TEXT PRIMARY KEY,
                exam_date TEXT NOT NULL,
                registration_deadline TEXT NOT NULL
            )
        """)

        # Exam levels table (junction table for exam_dates <-> levels)
        conn.execute("""
            CREATE TABLE IF NOT EXISTS exam_levels (
                exam_date_id TEXT NOT NULL REFERENCES exam_dates(id) ON DELETE CASCADE,
                level TEXT NOT NULL CHECK(level IN ('1Q', '2Q', '3Q', '4Q', '5Q')),
                PRIMARY KEY (exam_date_id, level)
            )
        """)

        # Indexes
        conn.execute("CREATE INDEX IF NOT EXISTS idx_blocks_type ON content_blocks(block_type)")
        conn.execute("CREATE INDEX IF NOT EXISTS idx_blocks_active ON content_blocks(is_active)")
        conn.execute("CREATE INDEX IF NOT EXISTS idx_blocks_order ON content_blocks(display_order)")
        conn.execute("CREATE INDEX IF NOT EXISTS idx_exam_dates_date ON exam_dates(exam_date)")

def backup_database() -> str:
    """Create a backup of the database. Returns backup file path."""
    import shutil
    from datetime import datetime

    db_path = get_db_path()
    backup_dir = db_path.parent / "backups"
    backup_dir.mkdir(exist_ok=True)

    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    backup_path = backup_dir / f"admin_{timestamp}.db"

    shutil.copy2(db_path, backup_path)
    return str(backup_path)
