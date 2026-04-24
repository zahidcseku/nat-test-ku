"""Pydantic models for content validation and serialization.

This module defines the data validation layer using Pydantic models.
These models match the content.json structure exactly and provide automatic
validation for all content types used throughout the admin application.
"""

from pydantic import BaseModel, Field, field_validator
from typing import Literal, Optional, Dict, Any, List
from datetime import datetime
import json
import sqlite3


# Block type enum - matches content.json structure
BlockType = Literal[
    "hero_badge",
    "hero_headline",
    "hero_description",
    "hero_cta_primary",
    "hero_cta_secondary",
    "exam_ribbon",
    "benefits_heading",
    "benefits_description",
    "resources_heading",
    "resource_card",
    "support_heading",
    "support_description",
    "support_contact",
    "footer_copyright",
    "footer_links"
]


# Content models for each type

class HeroBadgeContent(BaseModel):
    """Hero badge text content."""
    text: str = Field(..., max_length=200)


class HeroHeadlineContent(BaseModel):
    """Hero headline with HTML structure for styling."""
    line1: str
    line2_italic: str
    line2_text: str = ""
    full_html: str  # Complete HTML with styling preserved


class HeroDescriptionContent(BaseModel):
    """Hero section description text."""
    text: str = Field(..., max_length=1000)


class HeroCtaContent(BaseModel):
    """Hero CTA button content."""
    label: str = Field(..., max_length=100)
    url: str
    icon: str

    @field_validator("url")
    @classmethod
    def validate_url(cls, v: str) -> str:
        if not v.startswith(("http://", "https://", "/")):
            raise ValueError("URL must start with http://, https://, or /")
        return v


class ExamRibbonContent(BaseModel):
    """Exam ribbon with date and action links."""
    exam_date: str  # Display text, not just YYYY-MM-DD
    registration_status: str
    exam_info_url: str
    registration_url: str

    @field_validator("exam_info_url", "registration_url")
    @classmethod
    def validate_url(cls, v: str) -> str:
        if not v.startswith(("http://", "https://", "/")):
            raise ValueError("URL must start with http://, https://, or /")
        return v


class HeadingContent(BaseModel):
    """Generic heading with HTML structure for italic styling."""
    line1: str
    line2_italic: str
    line2_text: str = ""
    full_html: str  # Complete HTML with styling preserved


class DescriptionContent(BaseModel):
    """Generic description text."""
    text: str = Field(..., max_length=1000)


class ResourceCardContent(BaseModel):
    """Resource card content."""
    badge_type: str  # PDF, CHECKLIST, PROTOCOL, etc.
    title: str
    url: str
    icon: str

    @field_validator("url")
    @classmethod
    def validate_url(cls, v: str) -> str:
        if not v.startswith(("http://", "https://", "/")):
            raise ValueError("URL must start with http://, https://, or /")
        return v


class SupportContactContent(BaseModel):
    """Support contact information (phone/email)."""
    label: str  # "Call us" or "Email us"
    value: str  # Phone number or email address
    icon: str  # Material Symbol icon name


class FooterCopyrightContent(BaseModel):
    """Footer copyright text."""
    text: str  # Can contain \n for line breaks


class FooterLinksContent(BaseModel):
    """Footer navigation links."""
    links: List[Dict[str, str]] = Field(..., min_length=1)

    @field_validator("links")
    @classmethod
    def validate_links(cls, v: List[Dict[str, str]]) -> List[Dict[str, str]]:
        for link in v:
            if "label" not in link or "url" not in link:
                raise ValueError("Each link must have 'label' and 'url' keys")
            if not link["url"].startswith(("http://", "https://", "/")):
                raise ValueError("URL must start with http://, https://, or /")
        return v


# Union type for all content types
ContentData = (
    HeroBadgeContent |
    HeroHeadlineContent |
    HeroDescriptionContent |
    HeroCtaContent |
    ExamRibbonContent |
    HeadingContent |
    DescriptionContent |
    ResourceCardContent |
    SupportContactContent |
    FooterCopyrightContent |
    FooterLinksContent
)


class ContentBlock(BaseModel):
    """Complete content block model matching content.json structure."""
    id: str
    type: BlockType
    display_order: int
    is_active: bool
    content: ContentData
    created_at: Optional[str] = None
    updated_at: Optional[str] = None

    @classmethod
    def from_db_row(cls, row: sqlite3.Row) -> "ContentBlock":
        """Create ContentBlock from database row."""
        content_dict = json.loads(row["content"])
        return cls(
            id=row["id"],
            type=row["block_type"],
            display_order=row["display_order"],
            is_active=bool(row["is_active"]),
            content=content_dict,
            created_at=row["created_at"] if "created_at" in row.keys() else None,
            updated_at=row["updated_at"] if "updated_at" in row.keys() else None
        )

    def to_db_dict(self) -> Dict[str, Any]:
        """Convert to dictionary for database insertion."""
        return {
            "id": self.id,
            "block_type": self.type,
            "content": json.dumps(self.content.model_dump()),
            "display_order": self.display_order,
            "is_active": 1 if self.is_active else 0,
            "created_at": self.created_at or datetime.now().isoformat(),
            "updated_at": self.updated_at or datetime.now().isoformat()
        }

    def to_content_json(self) -> Dict[str, Any]:
        """Convert to content.json format."""
        return {
            "id": self.id,
            "type": self.type,
            "display_order": self.display_order,
            "is_active": self.is_active,
            "content": self.content.model_dump()
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
