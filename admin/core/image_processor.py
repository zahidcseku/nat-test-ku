"""Image processing utilities for upload, optimization, and cropping.

This module provides functions to process uploaded images including
optimization, format conversion to WebP, resizing, and cropping.
"""

from PIL import Image
import io
import uuid
from pathlib import Path
from typing import Optional, Tuple
from datetime import datetime


def process_image(
    file_bytes: bytes,
    original_filename: str,
    output_dir: Path,
    max_size: Tuple[int, int] = (1920, 1080),
    quality: int = 85
) -> dict:
    """
    Process uploaded image: optimize, resize if needed, convert to WebP.

    Returns dict with paths and metadata.
    """
    img_id = str(uuid.uuid4())

    # Create directories
    original_dir = output_dir / "images" / "original"
    optimized_dir = output_dir / "images" / "optimized"

    original_dir.mkdir(parents=True, exist_ok=True)
    optimized_dir.mkdir(parents=True, exist_ok=True)

    # Save original
    original_filename = f"{img_id}_{original_filename}"
    original_path = original_dir / original_filename

    with open(original_path, 'wb') as f:
        f.write(file_bytes)

    # Open and process image
    img = Image.open(io.BytesIO(file_bytes))

    # Convert RGBA to RGB if necessary
    if img.mode == 'RGBA':
        img = img.convert('RGB')

    # Resize if larger than max_size
    img.thumbnail(max_size, Image.Resampling.LANCZOS)

    # Save optimized version
    optimized_filename = f"{img_id}.webp"
    optimized_path = optimized_dir / optimized_filename

    img.save(optimized_path, 'WebP', quality=quality)

    # Get metadata
    original_size = len(file_bytes)
    optimized_file_size = optimized_path.stat().st_size
    width, height = img.size

    return {
        "id": img_id,
        "original_filename": original_filename,
        "original_path": str(original_path.relative_to(output_dir)),
        "optimized_path": str(optimized_path.relative_to(output_dir)),
        "file_size_bytes": original_size,
        "optimized_size_bytes": optimized_file_size,
        "width": width,
        "height": height
    }


def crop_image(
    image_path: Path,
    crop_box: Tuple[int, int, int, int],
    output_path: Path,
    output_size: Optional[Tuple[int, int]] = None
) -> Path:
    """
    Crop image to specified box and optionally resize.

    crop_box: (left, top, right, bottom)
    """
    img = Image.open(image_path)

    # Crop
    cropped = img.crop(crop_box)

    # Resize if specified
    if output_size:
        cropped = cropped.resize(output_size, Image.Resampling.LANCZOS)

    # Save
    output_path.parent.mkdir(parents=True, exist_ok=True)
    cropped.save(output_path, 'WebP', quality=85)

    return output_path
