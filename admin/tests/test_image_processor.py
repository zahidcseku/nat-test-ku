"""Tests for image processing functionality."""

import pytest
from PIL import Image
import io
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).parent.parent))

from core.image_processor import process_image, crop_image


def create_test_image(width=1920, height=1080, color='red'):
    """Create a test image."""
    img = Image.new('RGB', (width, height), color)
    byte_arr = io.BytesIO()
    img.save(byte_arr, format='PNG')
    return byte_arr.getvalue()


@pytest.fixture(autouse=True)
def setup_directory(tmp_path):
    """Use temporary directory for image tests."""
    yield tmp_path


def test_process_image_creates_files(tmp_path):
    """Test that process_image creates original and optimized files."""
    file_bytes = create_test_image()

    result = process_image(
        file_bytes=file_bytes,
        original_filename="test.png",
        output_dir=tmp_path
    )

    original_path = tmp_path / result["original_path"]
    optimized_path = tmp_path / result["optimized_path"]

    assert original_path.exists()
    assert optimized_path.exists()


def test_process_image_converts_to_webp(tmp_path):
    """Test that optimized image is WebP format."""
    file_bytes = create_test_image()

    result = process_image(
        file_bytes=file_bytes,
        original_filename="test.png",
        output_dir=tmp_path
    )

    optimized_path = tmp_path / result["optimized_path"]
    assert optimized_path.suffix == ".webp"


def test_process_image_returns_metadata(tmp_path):
    """Test that process_image returns correct metadata."""
    file_bytes = create_test_image(width=1920, height=1080)

    result = process_image(
        file_bytes=file_bytes,
        original_filename="test.png",
        output_dir=tmp_path
    )

    assert "id" in result
    assert result["width"] == 1920
    assert result["height"] == 1080
    assert result["file_size_bytes"] > 0


def test_process_image_handles_rgba(tmp_path):
    """Test that RGBA images are converted to RGB."""
    img = Image.new('RGBA', (100, 100), (255, 0, 0, 128))
    byte_arr = io.BytesIO()
    img.save(byte_arr, format='PNG')

    result = process_image(
        file_bytes=byte_arr.getvalue(),
        original_filename="test.png",
        output_dir=tmp_path
    )

    optimized_path = tmp_path / result["optimized_path"]
    img = Image.open(optimized_path)
    assert img.mode == 'RGB'


def test_crop_image(tmp_path):
    """Test image cropping."""
    # Create test image
    test_image_path = tmp_path / "test.png"
    img = Image.new('RGB', (1000, 1000), 'red')
    img.save(test_image_path)

    output_path = tmp_path / "cropped.webp"

    # Crop to center 500x500
    crop_box = (250, 250, 750, 750)
    result = crop_image(test_image_path, crop_box, output_path)

    assert result.exists()

    cropped_img = Image.open(result)
    assert cropped_img.size == (500, 500)


def test_crop_image_with_resize(tmp_path):
    """Test cropping with resize."""
    test_image_path = tmp_path / "test.png"
    img = Image.new('RGB', (1000, 1000), 'red')
    img.save(test_image_path)

    output_path = tmp_path / "cropped_resized.webp"

    crop_box = (250, 250, 750, 750)
    result = crop_image(
        test_image_path,
        crop_box,
        output_path,
        output_size=(200, 200)
    )

    cropped_img = Image.open(result)
    assert cropped_img.size == (200, 200)
