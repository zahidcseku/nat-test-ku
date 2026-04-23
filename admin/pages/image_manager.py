"""Image manager page for Streamlit admin interface."""

import streamlit as st
from pathlib import Path
import sys
from io import BytesIO
from datetime import datetime
import os

sys.path.insert(0, str(Path(__file__).parent.parent))

from core.database import init_db, get_connection
from core.image_processor import process_image

st.subheader("🖼️ Image Manager")

init_db()

# Tabs for upload, browse, crop
tab1, tab2, tab3 = st.tabs(["Upload", "Browse", "Crop"])

with tab1:
    st.markdown("### Upload Image")

    uploaded_file = st.file_uploader(
        "Choose an image",
        type=['png', 'jpg', 'jpeg', 'webp'],
        help="Upload images for use in content blocks"
    )

    if uploaded_file:
        col1, col2 = st.columns(2)

        with col1:
            st.image(uploaded_file, caption="Preview", use_column_width=True)

        with col2:
            file_bytes = uploaded_file.read()
            original_filename = uploaded_file.name

            frontend_path = Path(os.getenv("FRONTEND_PATH", "../frontend"))

            if st.button("Process & Save"):
                with st.spinner("Processing image..."):
                    result = process_image(
                        file_bytes=file_bytes,
                        original_filename=original_filename,
                        output_dir=frontend_path
                    )

                with get_connection() as conn:
                    conn.execute("""
                        INSERT INTO images (id, original_filename, original_path, optimized_path,
                                          alt_text, uploaded_at, file_size_bytes, width, height)
                        VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?)
                    """, (
                        result["id"],
                        result["original_filename"],
                        result["original_path"],
                        result["optimized_path"],
                        datetime.now().isoformat(),
                        result["file_size_bytes"],
                        result["width"],
                        result["height"]
                    ))

                st.success(f"Image processed! ID: {result['id']}")
                st.json(result)

with tab2:
    st.markdown("### Browse Images")

    with get_connection() as conn:
        images = conn.execute("SELECT * FROM images ORDER BY uploaded_at DESC").fetchall()

    if not images:
        st.info("No images uploaded yet.")
    else:
        for img in images:
            with st.expander(f"📷 {img['original_filename']} ({img['width']}x{img['height']})"):
                col1, col2 = st.columns(2)

                with col1:
                    frontend_path = Path(os.getenv("FRONTEND_PATH", "../frontend"))
                    img_path = frontend_path / img["optimized_path"]
                    if img_path.exists():
                        st.image(str(img_path), caption="Optimized", use_column_width=True)
                    else:
                        st.warning(f"Image file not found at {img['optimized_path']}")

                with col2:
                    st.markdown("**Details:**")
                    st.text(f"ID: {img['id']}")
                    st.text(f"Size: {img['file_size_bytes']} bytes")
                    st.text(f"Uploaded: {img['uploaded_at']}")
                    st.text(f"Path: {img['optimized_path']}")

                    copy_button = st.button(
                        f"Copy Path: {img['optimized_path']}",
                        key=f"copy_{img['id']}"
                    )
                    if copy_button:
                        st.code(img['optimized_path'])

with tab3:
    st.markdown("### Crop Image")
    st.info("Crop functionality - select image and specify crop area")
    # This would integrate with a JavaScript cropper library
    # For MVP, basic manual crop box input:
    st.text_input("Image ID to crop", key="crop_image_id")
    col1, col2, col3, col4 = st.columns(4)
    with col1:
        left = st.number_input("Left", value=0, min_value=0)
    with col2:
        top = st.number_input("Top", value=0, min_value=0)
    with col3:
        right = st.number_input("Right", value=500, min_value=1)
    with col4:
        bottom = st.number_input("Bottom", value=500, min_value=1)

    st.button("Crop Image", key="do_crop")
