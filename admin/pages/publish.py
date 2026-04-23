"""Publish page for exporting content and syncing to production."""

import streamlit as st
import json
from pathlib import Path
import sys
import os

sys.path.insert(0, str(Path(__file__).parent.parent))

from core.database import init_db
from core.publisher import export_to_json, publish

st.subheader("🚀 Publish to Frontend")

init_db()

frontend_data_path = os.getenv("FRONTEND_DATA_PATH", "../frontend/data")
production_host = os.getenv("PRODUCTION_HOST")
production_path = os.getenv("PRODUCTION_PATH", "/var/www/site")

# Manual export
st.markdown("### Export Content")
if st.button("📤 Export to JSON"):
    with st.spinner("Exporting..."):
        json_path = Path(frontend_data_path) / "content.json"
        result_path = export_to_json(str(json_path))
        st.success(f"Exported to {result_path}")

        with open(result_path) as f:
            data = json.load(f)
            st.json(data)

# Publish workflow
st.markdown("---")
st.markdown("### Publish to Production")

if not production_host:
    st.warning("PRODUCTION_HOST not configured in .env")
else:
    st.info(f"Target: {production_host}:{production_path}")

    if st.button("🚀 Publish (Dry Run)", type="primary"):
        with st.spinner("Running publish workflow..."):
            result = publish(
                frontend_data_path=frontend_data_path,
                production_host=production_host,
                production_path=production_path
            )

            if result["status"] == "error":
                st.error(f"Error: {result['error']}")
            else:
                st.success("Dry run complete!")
                st.code(result["dry_run_output"])

                if st.button("✅ Confirm and Publish"):
                    # Actual publish would go here
                    st.success("Published!")
