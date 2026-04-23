"""CRUD operations for content blocks.

This module provides all database operations for creating, reading,
updating, and deleting content blocks in the admin database.
"""

import sqlite3
import json
import uuid
from datetime import datetime
from typing import List, Optional

from .database import get_connection, backup_database
from .models import ContentBlock, BlockType, ContentData


def create_block(
    block_type: BlockType,
    title: Optional[str],
    content: ContentData,
    display_order: Optional[int] = None
) -> ContentBlock:
    """Create a new content block."""
    backup_database()  # Backup before any write

    block_id = str(uuid.uuid4())
    now = datetime.now().isoformat()

    if display_order is None:
        # Get max display_order and add 1
        with get_connection() as conn:
            max_order = conn.execute(
                "SELECT COALESCE(MAX(display_order), 0) as max_order FROM content_blocks"
            ).fetchone()["max_order"]
            display_order = max_order + 1

    content_json = content.model_dump_json()

    with get_connection() as conn:
        conn.execute("""
            INSERT INTO content_blocks (id, block_type, title, content, display_order, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 1, ?, ?)
        """, (block_id, block_type, title, content_json, display_order, now, now))

    return ContentBlock(
        id=block_id,
        block_type=block_type,
        title=title,
        content=content,
        display_order=display_order,
        is_active=True,
        created_at=now,
        updated_at=now
    )


def get_block(block_id: str) -> Optional[ContentBlock]:
    """Get a single content block by ID."""
    with get_connection() as conn:
        row = conn.execute(
            "SELECT * FROM content_blocks WHERE id = ?",
            (block_id,)
        ).fetchone()

    if not row:
        return None

    return ContentBlock.from_db_row(row)


def list_blocks(
    block_type: Optional[BlockType] = None,
    active_only: bool = False
) -> List[ContentBlock]:
    """List content blocks with optional filters."""
    query = "SELECT * FROM content_blocks WHERE 1=1"
    params = []

    if block_type:
        query += " AND block_type = ?"
        params.append(block_type)

    if active_only:
        query += " AND is_active = 1"

    query += " ORDER BY display_order"

    with get_connection() as conn:
        rows = conn.execute(query, params).fetchall()

    return [ContentBlock.from_db_row(row) for row in rows]


def update_block(
    block_id: str,
    title: Optional[str] = None,
    content: Optional[ContentData] = None,
    display_order: Optional[int] = None,
    is_active: Optional[bool] = None
) -> Optional[ContentBlock]:
    """Update a content block."""
    backup_database()

    block = get_block(block_id)
    if not block:
        return None

    updates = []
    params = []

    if title is not None:
        updates.append("title = ?")
        params.append(title)

    if content is not None:
        updates.append("content = ?")
        params.append(content.model_dump_json())

    if display_order is not None:
        updates.append("display_order = ?")
        params.append(display_order)

    if is_active is not None:
        updates.append("is_active = ?")
        params.append(1 if is_active else 0)

    updates.append("updated_at = ?")
    now = datetime.now().isoformat()
    params.append(now)
    params.append(block_id)

    with get_connection() as conn:
        conn.execute(
            f"UPDATE content_blocks SET {', '.join(updates)} WHERE id = ?",
            params
        )

    return get_block(block_id)


def delete_block(block_id: str, hard_delete: bool = False) -> bool:
    """Delete a content block (soft delete by default)."""
    block = get_block(block_id)
    if not block:
        return False

    backup_database()

    if hard_delete:
        with get_connection() as conn:
            conn.execute("DELETE FROM content_blocks WHERE id = ?", (block_id,))
    else:
        with get_connection() as conn:
            conn.execute(
                "UPDATE content_blocks SET is_active = 0 WHERE id = ?",
                (block_id,)
            )

    return True
