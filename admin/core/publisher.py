"""Publisher for exporting content to JSON and syncing to production.

This module handles the export of content blocks to JSON format
and provides rsync functionality for deploying to production.
"""

import json
import subprocess
from pathlib import Path
from typing import List, Dict, Any
from datetime import datetime

from .crud import list_blocks
from .database import backup_database


def export_to_json(output_path: str) -> str:
    """Export all active blocks to JSON file matching content.json structure."""
    blocks = list_blocks(active_only=True)

    export_data = {
        "last_updated": datetime.now().isoformat(),
        "blocks": [
            block.to_content_json()
            for block in blocks
        ]
    }

    output_file = Path(output_path)
    output_file.parent.mkdir(parents=True, exist_ok=True)

    with open(output_file, 'w') as f:
        json.dump(export_data, f, indent=2)

    return str(output_file)


def rsync_to_production(
    local_path: str,
    production_host: str,
    production_path: str,
    dry_run: bool = True
) -> Dict[str, Any]:
    """Run rsync to production server."""

    cmd = [
        "rsync",
        "-avz",
        "--delete",
        local_path,
        f"{production_host}:{production_path}"
    ]

    if dry_run:
        cmd.insert(1, "--dry-run")

    result = subprocess.run(
        cmd,
        capture_output=True,
        text=True
    )

    return {
        "success": result.returncode == 0,
        "stdout": result.stdout,
        "stderr": result.stderr,
        "dry_run": dry_run
    }


def publish(
    frontend_data_path: str,
    production_host: str,
    production_path: str
) -> Dict[str, Any]:
    """Full publish workflow: export JSON and rsync to production."""

    # Export to JSON
    json_path = Path(frontend_data_path) / "content.json"
    export_path = export_to_json(str(json_path))

    # Dry run rsync
    dry_run_result = rsync_to_production(
        local_path=f"{frontend_data_path}/",
        production_host=production_host,
        production_path=production_path,
        dry_run=True
    )

    if not dry_run_result["success"]:
        return {
            "status": "error",
            "stage": "dry_run",
            "error": dry_run_result["stderr"]
        }

    return {
        "status": "success",
        "stage": "dry_run_complete",
        "json_path": export_path,
        "dry_run_output": dry_run_result["stdout"]
    }
