"""Pytest configuration and fixtures."""

import pytest
from pathlib import Path


@pytest.fixture(autouse=True)
def isolate_tests(tmp_path, monkeypatch):
    """Use temporary database for each test."""
    test_db = tmp_path / "test.db"
    monkeypatch.setenv("DATABASE_PATH", str(test_db))
    yield
    # Cleanup is automatic with tmp_path
