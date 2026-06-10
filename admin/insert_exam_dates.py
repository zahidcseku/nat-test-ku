#!/usr/bin/env python3
"""
Insert exam dates into remote database.
Supports both SQLite and MySQL databases.
"""

import sys
import uuid
from datetime import datetime
from pathlib import Path
from typing import List, Optional, Dict, Any

# Database type configuration
DB_TYPE = "mysql"  # Options: "sqlite" or "mysql"

# ========================================
# DATABASE CONNECTION DETAILS
# ========================================

# SQLite Configuration
SQLITE_DB_PATH = "./data/admin.db"  # Path to SQLite database file

# MySQL Configuration
MYSQL_CONFIG = {
    "host": "localhost",        # MySQL server hostname
    "port": 3306,               # MySQL server port
    "user": "root",             # MySQL username
    "password": "",             # MySQL password (leave empty if none)
    "database": "exam_dates",   # MySQL database name
    "charset": "utf8mb4",
    "collation": "utf8mb4_unicode_ci"
}

# ========================================
# EXAM DATE DATA
# ========================================

# Define exam dates to insert
EXAM_DATES_TO_INSERT = [
    {
        "exam_date": "2026-07-15",
        "registration_deadline": "2026-06-30",
        "levels": ["1Q", "2Q", "3Q"]
    },
    {
        "exam_date": "2026-08-20",
        "registration_deadline": "2026-07-31",
        "levels": ["4Q", "5Q"]
    },
    {
        "exam_date": "2026-09-10",
        "registration_deadline": "2026-08-25",
        "levels": ["1Q", "2Q", "3Q", "4Q", "5Q"]
    }
]

# ========================================
# DATABASE CONNECTION CLASSES
# ========================================

class DatabaseConnection:
    """Base class for database connections."""

    def __init__(self):
        self.conn = None

    def connect(self):
        raise NotImplementedError

    def close(self):
        if self.conn:
            self.conn.close()

    def insert_exam_date(self, exam_id: str, exam_date: str, deadline: str, levels: List[str]):
        raise NotImplementedError

    def get_exam_dates(self) -> List[Dict[str, Any]]:
        raise NotImplementedError


class SQLiteConnection(DatabaseConnection):
    """SQLite database connection."""

    def __init__(self, db_path: str):
        self.db_path = db_path
        super().__init__()

    def connect(self):
        import sqlite3
        self.conn = sqlite3.connect(self.db_path)
        self.conn.row_factory = sqlite3.Row
        # Enable foreign keys
        self.conn.execute("PRAGMA foreign_keys = ON")
        print(f"✅ Connected to SQLite database: {self.db_path}")

    def insert_exam_date(self, exam_id: str, exam_date: str, deadline: str, levels: List[str]):
        """Insert an exam date with levels into SQLite database."""
        cursor = self.conn.cursor()

        # Insert exam date
        cursor.execute("""
            INSERT INTO exam_dates (id, exam_date, registration_deadline)
            VALUES (?, ?, ?)
        """, (exam_id, exam_date, deadline))

        # Insert levels
        for level in levels:
            cursor.execute("""
                INSERT INTO exam_levels (exam_date_id, level)
                VALUES (?, ?)
            """, (exam_id, level))

        self.conn.commit()

    def get_exam_dates(self) -> List[Dict[str, Any]]:
        """Get all exam dates from SQLite database."""
        cursor = self.conn.cursor()
        exams = cursor.execute("""
            SELECT id, exam_date, registration_deadline
            FROM exam_dates
            ORDER BY exam_date
        """).fetchall()

        result = []
        for exam in exams:
            levels = cursor.execute("""
                SELECT level FROM exam_levels WHERE exam_date_id = ? ORDER BY level
            """, (exam["id"],)).fetchall()

            result.append({
                "id": exam["id"],
                "exam_date": exam["exam_date"],
                "registration_deadline": exam["registration_deadline"],
                "levels": [level["level"] for level in levels]
            })

        return result


class MySQLConnection(DatabaseConnection):
    """MySQL database connection."""

    def __init__(self, config: dict):
        self.config = config
        super().__init__()

    def connect(self):
        try:
            import mysql.connector
            self.conn = mysql.connector.connect(**self.config)
            print(f"✅ Connected to MySQL database: {self.config['database']}@{self.config['host']}")
        except ImportError:
            print("❌ Error: mysql-connector-python not installed")
            print("   Install with: pip install mysql-connector-python")
            sys.exit(1)
        except Exception as e:
            print(f"❌ Error connecting to MySQL: {e}")
            sys.exit(1)

    def insert_exam_date(self, exam_id: str, exam_date: str, deadline: str, levels: List[str]):
        """Insert an exam date with levels into MySQL database."""
        cursor = self.conn.cursor()

        # Insert exam date
        cursor.execute("""
            INSERT INTO exam_dates (id, exam_date, registration_deadline)
            VALUES (%s, %s, %s)
        """, (exam_id, exam_date, deadline))

        # Insert levels
        for level in levels:
            cursor.execute("""
                INSERT INTO exam_levels (exam_date_id, level)
                VALUES (%s, %s)
            """, (exam_id, level))

        self.conn.commit()

    def get_exam_dates(self) -> List[Dict[str, Any]]:
        """Get all exam dates from MySQL database."""
        cursor = self.conn.cursor(dictionary=True)

        exams = cursor.execute("""
            SELECT id, exam_date, registration_deadline
            FROM exam_dates
            ORDER BY exam_date
        """).fetchall()

        result = []
        for exam in exams:
            cursor.execute("""
                SELECT level FROM exam_levels WHERE exam_date_id = %s ORDER BY level
            """, (exam["id"],))
            levels = cursor.fetchall()

            result.append({
                "id": exam["id"],
                "exam_date": exam["exam_date"].isoformat() if exam["exam_date"] else exam["exam_date"],
                "registration_deadline": exam["registration_deadline"].isoformat() if exam["registration_deadline"] else exam["registration_deadline"],
                "levels": [level["level"] for level in levels]
            })

        return result


# ========================================
# MAIN FUNCTIONS
# ========================================

def get_database_connection() -> DatabaseConnection:
    """Get database connection based on DB_TYPE configuration."""
    if DB_TYPE == "sqlite":
        return SQLiteConnection(SQLITE_DB_PATH)
    elif DB_TYPE == "mysql":
        return MySQLConnection(MYSQL_CONFIG)
    else:
        print(f"❌ Error: Unsupported database type '{DB_TYPE}'")
        print("   Supported types: 'sqlite', 'mysql'")
        sys.exit(1)


def insert_exam_dates(db_conn: DatabaseConnection, exam_dates: List[Dict]):
    """Insert exam dates into database."""
    print(f"\n📊 Inserting {len(exam_dates)} exam dates...")

    for i, exam_data in enumerate(exam_dates, 1):
        # Generate UUID for exam date
        exam_id = str(uuid.uuid4())

        try:
            db_conn.insert_exam_date(
                exam_id=exam_id,
                exam_date=exam_data["exam_date"],
                deadline=exam_data["registration_deadline"],
                levels=exam_data["levels"]
            )
            print(f"   ✅ {i}. {exam_data['exam_date']} - Levels: {', '.join(exam_data['levels'])}")

        except Exception as e:
            print(f"   ❌ {i}. Failed to insert {exam_data['exam_date']}: {e}")
            return False

    return True


def display_current_exam_dates(db_conn: DatabaseConnection):
    """Display current exam dates in database."""
    print("\n📋 Current exam dates in database:")

    try:
        exams = db_conn.get_exam_dates()

        if not exams:
            print("   (No exam dates found)")
            return

        for exam in exams:
            print(f"   📅 {exam['exam_date']} (Deadline: {exam['registration_deadline']})")
            print(f"      ID: {exam['id']}")
            print(f"      Levels: {', '.join(exam['levels'])}")
            print()

    except Exception as e:
        print(f"   ❌ Error retrieving exam dates: {e}")


def main():
    """Main function to insert exam dates."""
    print("🔧 Exam Dates Database Insertion Script")
    print("=" * 50)

    # Display configuration
    print(f"Database Type: {DB_TYPE}")
    if DB_TYPE == "sqlite":
        print(f"Database Path: {SQLITE_DB_PATH}")
    else:
        print(f"MySQL Server: {MYSQL_CONFIG['host']}:{MYSQL_CONFIG['port']}")
        print(f"Database: {MYSQL_CONFIG['database']}")
        print(f"User: {MYSQL_CONFIG['user']}")

    # Get database connection
    db_conn = get_database_connection()
    db_conn.connect()

    # Display current exam dates
    display_current_exam_dates(db_conn)

    # Confirm insertion
    print(f"📋 Ready to insert {len(EXAM_DATES_TO_INSERT)} exam dates")
    response = input("Continue? (y/n): ").strip().lower()

    if response != 'y':
        print("❌ Insertion cancelled")
        db_conn.close()
        return

    # Insert exam dates
    success = insert_exam_dates(db_conn, EXAM_DATES_TO_INSERT)

    if success:
        print("\n✅ All exam dates inserted successfully!")
        print("\n📋 Updated exam dates:")
        display_current_exam_dates(db_conn)
    else:
        print("\n❌ Some exam dates failed to insert")

    # Close connection
    db_conn.close()


if __name__ == "__main__":
    main()
