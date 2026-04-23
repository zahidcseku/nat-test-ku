"""Pydantic models for content validation and serialization.

This module defines the data validation layer using Pydantic models.
These models match the database schema and provide automatic validation
for all content types used throughout the admin application.
"""

from pydantic import BaseModel, Field, field_validator
from typing import Literal, Optional, Dict, Any
from datetime import datetime
import json
import sqlite3


# Block type enum
BlockType = Literal["hero", "banner", "heading_text", "card", "footer"]


# Content block JSON schemas for each type
class HeroContent(BaseModel):
    """Hero section content with slogan, description, image, and links."""
    slogan: str = Field(..., max_length=200)
    description: str = Field(..., max_length=500)
    image_url: str
    primary_link: Dict[str, str] = Field(..., min_length=1)
    secondary_link: Dict[str, str] = Field(..., min_length=1)

    @field_validator("primary_link", "secondary_link")
    @classmethod
    def validate_link(cls, v: Dict[str, str]) -> Dict[str, str]:
        if "label" not in v or "url" not in v:
            raise ValueError("Link must have 'label' and 'url' keys")
        if not v["url"].startswith(("http://", "https://", "/")):
            raise ValueError("URL must start with http://, https://, or /")
        return v


class BannerContent(BaseModel):
    """Banner content with exam date and URLs."""
    exam_date: str  # YYYY-MM-DD format
    exam_info_url: str
    registration_url: str

    @field_validator("exam_info_url", "registration_url")
    @classmethod
    def validate_url(cls, v: str) -> str:
        if not v.startswith(("http://", "https://", "/")):
            raise ValueError("URL must start with http://, https://, or /")
        return v


class HeadingTextContent(BaseModel):
    """Heading and text block for general content sections."""
    heading: str
    body_text: str


class CardContent(BaseModel):
    """Card content with title, description, link, and optional icon."""
    title: str
    description: str = Field(..., max_length=300)
    link_url: str
    icon_name: Optional[str] = None

    @field_validator("link_url")
    @classmethod
    def validate_url(cls, v: str) -> str:
        if not v.startswith(("http://", "https://", "/")):
            raise ValueError("URL must start with http://, https://, or /")
        return v


class FooterContent(BaseModel):
    """Footer content with copyright and links."""
    copyright_text: str
    links: list[Dict[str, str]]


# Union type for all content types
ContentData = HeroContent | BannerContent | HeadingTextContent | CardContent | FooterContent


class ContentBlock(BaseModel):
    """Complete content block model."""
    id: str
    block_type: BlockType
    title: Optional[str] = None
    content: ContentData
    display_order: int = 0
    is_active: bool = True
    created_at: str
    updated_at: str

    @classmethod
    def from_db_row(cls, row: sqlite3.Row) -> "ContentBlock":
        """Create ContentBlock from database row."""
        content_dict = json.loads(row["content"])
        return cls(
            id=row["id"],
            block_type=row["block_type"],
            title=row["title"],
            content=content_dict,
            display_order=row["display_order"],
            is_active=bool(row["is_active"]),
            created_at=row["created_at"],
            updated_at=row["updated_at"]
        )

    def to_db_dict(self) -> Dict[str, Any]:
        """Convert to dictionary for database insertion."""
        return {
            "id": self.id,
            "block_type": self.block_type,
            "title": self.title,
            "content": self.content.model_dump_json(),
            "display_order": self.display_order,
            "is_active": 1 if self.is_active else 0,
            "created_at": self.created_at,
            "updated_at": self.updated_at
        }


class Image(BaseModel):
    """Image model."""
    id: str
    original_filename: str
    original_path: str
    optimized_path: Optional[str] = None
    alt_text: Optional[str] = None
    uploaded_at: str
    file_size_bytes: Optional[int] = None
    width: Optional[int] = None
    height: Optional[int] = None
