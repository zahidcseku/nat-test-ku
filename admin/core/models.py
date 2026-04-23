"""Pydantic models for content validation and serialization.

This module defines the data validation layer using Pydantic models.
These models match the database schema and provide automatic validation
for all content types used throughout the admin application.
"""

from pydantic import BaseModel, Field, field_validator, ConfigDict
from typing import Optional, Literal, Union
from datetime import datetime
import re


# Base content block types
class HeroContent(BaseModel):
    """Hero section content with title, subtitle, and background."""
    block_type: Literal["hero"] = "hero"
    title: str = Field(..., max_length=200)
    subtitle: str = Field(..., max_length=500)
    background_image_id: Optional[str] = None
    cta_text: Optional[str] = Field(None, max_length=100)
    cta_link: Optional[str] = None

    @field_validator("cta_link")
    @classmethod
    def validate_hero_link(cls, v: Optional[str]) -> Optional[str]:
        if v is None or v == "":
            return None
        # Allow relative URLs or absolute URLs with http/https
        if not re.match(r'^(/|https?://)', v):
            raise ValueError("Link must start with / or http:// or https://")
        return v


class BannerContent(BaseModel):
    """Banner/announcement content with text and link."""
    block_type: Literal["banner"] = "banner"
    text: str = Field(..., max_length=500)
    link: Optional[str] = None
    is_dismissible: bool = True

    @field_validator("link")
    @classmethod
    def validate_banner_link(cls, v: Optional[str]) -> Optional[str]:
        if v is None or v == "":
            return None
        if not re.match(r'^(/|https?://|mailto:)', v):
            raise ValueError("Link must start with /, http://, https://, or mailto:")
        return v


class HeadingTextContent(BaseModel):
    """Heading and text block for general content sections."""
    block_type: Literal["heading_text"] = "heading_text"
    heading: str = Field(..., max_length=200)
    text: str = Field(..., max_length=5000)
    display_order: int = Field(default=0, ge=0)


class CardContent(BaseModel):
    """Card content with title, description, and optional link/image."""
    block_type: Literal["card"] = "card"
    title: str = Field(..., max_length=200)
    description: str = Field(..., max_length=1000)
    link: Optional[str] = None
    image_id: Optional[str] = None
    display_order: int = Field(default=0, ge=0)

    @field_validator("link")
    @classmethod
    def validate_card_link(cls, v: Optional[str]) -> Optional[str]:
        if v is None or v == "":
            return None
        if not re.match(r'^(/|https?://)', v):
            raise ValueError("Link must start with / or http:// or https://")
        return v


class FooterContent(BaseModel):
    """Footer content with links and copyright."""
    block_type: Literal["footer"] = "footer"
    text: str = Field(..., max_length=1000)
    links: list[dict] = Field(default_factory=list)
    copyright_text: Optional[str] = Field(None, max_length=200)

    @field_validator("links")
    @classmethod
    def validate_footer_links(cls, v: list) -> list:
        if not v:
            return []
        for link in v:
            if not isinstance(link, dict):
                raise ValueError("Each link must be a dictionary")
            if "url" not in link:
                raise ValueError("Each link must have a 'url' field")
            url = link["url"]
            if not re.match(r'^(/|https?://|mailto:)', url):
                raise ValueError(f"Link URL must start with /, http://, https://, or mailto:")
        return v


# Union type for all content block types
ContentBlockData = Union[
    HeroContent,
    BannerContent,
    HeadingTextContent,
    CardContent,
    FooterContent
]


# Main ContentBlock model
class ContentBlock(BaseModel):
    """Main content block model with metadata and typed content data."""
    model_config = ConfigDict(from_attributes=True)

    id: str = Field(..., min_length=1)
    block_type: Literal["hero", "banner", "heading_text", "card", "footer"]
    title: Optional[str] = Field(None, max_length=200)
    content: str = Field(..., min_length=1)
    display_order: int = Field(default=0, ge=0)
    is_active: bool = True
    created_at: str
    updated_at: str

    @field_validator("created_at", "updated_at")
    @classmethod
    def validate_datetime(cls, v: str) -> str:
        """Validate datetime is in ISO format."""
        try:
            datetime.fromisoformat(v.replace("Z", "+00:00"))
        except ValueError:
            raise ValueError("Datetime must be in ISO 8601 format")
        return v


# Image model
class Image(BaseModel):
    """Image metadata model."""
    model_config = ConfigDict(from_attributes=True)

    id: str = Field(..., min_length=1)
    original_filename: str = Field(..., min_length=1, max_length=255)
    original_path: str = Field(..., min_length=1)
    optimized_path: Optional[str] = None
    alt_text: Optional[str] = Field(None, max_length=500)
    uploaded_at: str
    file_size_bytes: Optional[int] = Field(None, ge=0)
    width: Optional[int] = Field(None, ge=0)
    height: Optional[int] = Field(None, ge=0)

    @field_validator("uploaded_at")
    @classmethod
    def validate_datetime(cls, v: str) -> str:
        """Validate datetime is in ISO format."""
        try:
            datetime.fromisoformat(v.replace("Z", "+00:00"))
        except ValueError:
            raise ValueError("Datetime must be in ISO 8601 format")
        return v


# Helper models for create/update operations
class ContentBlockCreate(BaseModel):
    """Model for creating a new content block."""
    block_type: Literal["hero", "banner", "heading_text", "card", "footer"]
    title: Optional[str] = Field(None, max_length=200)
    content: str = Field(..., min_length=1)
    display_order: int = Field(default=0, ge=0)


class ContentBlockUpdate(BaseModel):
    """Model for updating an existing content block."""
    title: Optional[str] = Field(None, max_length=200)
    content: Optional[str] = Field(None, min_length=1)
    display_order: Optional[int] = Field(None, ge=0)
    is_active: Optional[bool] = None


class ImageCreate(BaseModel):
    """Model for creating a new image record."""
    original_filename: str = Field(..., min_length=1, max_length=255)
    original_path: str = Field(..., min_length=1)
    optimized_path: Optional[str] = None
    alt_text: Optional[str] = Field(None, max_length=500)
    file_size_bytes: Optional[int] = Field(None, ge=0)
    width: Optional[int] = Field(None, ge=0)
    height: Optional[int] = Field(None, ge=0)


class ImageUpdate(BaseModel):
    """Model for updating an existing image record."""
    alt_text: Optional[str] = Field(None, max_length=500)
    optimized_path: Optional[str] = None


# Serialization helpers
def content_block_to_dict(block: ContentBlock) -> dict:
    """Convert a ContentBlock model to dictionary for JSON export."""
    return block.model_dump(mode="json")


def dict_to_content_block(data: dict) -> ContentBlock:
    """Convert a dictionary to a ContentBlock model."""
    return ContentBlock(**data)


def image_to_dict(image: Image) -> dict:
    """Convert an Image model to dictionary for JSON export."""
    return image.model_dump(mode="json")


def dict_to_image(data: dict) -> Image:
    """Convert a dictionary to an Image model."""
    return Image(**data)
