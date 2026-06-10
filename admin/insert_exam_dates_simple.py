#!/usr/bin/env python3
"""
Simple example: Insert exam dates into MySQL database.
Configure your database details below.
"""

import uuid
import mysql.connector

# ========================================
# CONFIGURE THESE DETAILS
# ========================================
# define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
# define('DB_NAME', getenv('DB_NAME') ?: 'nat_test_intake');
# define('DB_USER', getenv('DB_USER') ?: 'intake_user');
# define('DB_PASS', getenv('DB_PASS') ?: '');
# define('DB_CHARSET', 'utf8mb4');

MYSQL_HOST = "62.30.197.93"  # Your MySQL server host
MYSQL_PORT = 8000  # Your MySQL port
MYSQL_USER = "nattes_reg"  # Your MySQL username
MYSQL_PASSWORD = "OWX0j153i9zR"  # Your MySQL password
MYSQL_DATABASE = "exam_dates"  # Your database name

# Exam dates to insert
EXAM_DATES = [
    {
        "exam_date": "2026-01-17",
        "registration_deadline": "2025-12-19",
        "levels": ["1Q", "2Q", "3Q", "4Q", "5Q"],
    },
    {
        "exam_date": "2026-02-07",
        "registration_deadline": "2026-01-09",
        "levels": ["1Q", "2Q", "3Q", "4Q", "5Q"],
    },
    {
        "exam_date": "2026-03-07",
        "registration_deadline": "2026-02-13",
        "levels": ["1Q", "2Q", "3Q", "4Q", "5Q"],
    },
    {
        "exam_date": "2026-04-11",
        "registration_deadline": "2026-03-06",
        "levels": ["1Q", "2Q", "3Q", "4Q", "5Q"],
    },
    {
        "exam_date": "2026-05-16",
        "registration_deadline": "2026-04-17",
        "levels": ["1Q", "2Q", "3Q", "4Q", "5Q"],
    },
    {
        "exam_date": "2026-06-13",
        "registration_deadline": "2026-05-15",
        "levels": ["1Q", "2Q", "3Q", "4Q", "5Q"],
    },
    {
        "exam_date": "2026-07-11",
        "registration_deadline": "2026-06-12",
        "levels": ["1Q", "2Q", "3Q", "4Q", "5Q"],
    },
    {
        "exam_date": "2026-08-15",
        "registration_deadline": "2026-07-17",
        "levels": ["1Q", "2Q", "3Q", "4Q", "5Q"],
    },
    {
        "exam_date": "2026-09-26",
        "registration_deadline": "2026-08-28",
        "levels": ["1Q", "2Q", "3Q", "4Q", "5Q"],
    },
    {
        "exam_date": "2026-10-17",
        "registration_deadline": "2026-09-18",
        "levels": ["1Q", "2Q", "3Q", "4Q", "5Q"],
    },
    {
        "exam_date": "2026-11-14",
        "registration_deadline": "2026-10-16",
        "levels": ["1Q", "2Q", "3Q", "4Q", "5Q"],
    },
    {
        "exam_date": "2026-12-12",
        "registration_deadline": "2026-11-13",
        "levels": ["1Q", "2Q", "3Q", "4Q", "5Q"],
    },
]

# ========================================
# INSERT EXAM DATES
# ========================================


def insert_exam_dates():
    """Connect to MySQL and insert exam dates."""

    try:
        # Connect to MySQL
        print(f"🔧 Connecting to MySQL at {MYSQL_HOST}:{MYSQL_PORT}...")
        conn = mysql.connector.connect(
            host=MYSQL_HOST,
            # port=MYSQL_PORT,
            user=MYSQL_USER,
            password=MYSQL_PASSWORD,
            database=MYSQL_DATABASE,
            charset="utf8mb4",
        )
        print("✅ Connected successfully!")

        cursor = conn.cursor()

        # Insert each exam date
        for i, exam in enumerate(EXAM_DATES, 1):
            # Generate UUID
            exam_id = str(uuid.uuid4())

            print(f"\n📅 Inserting exam {i}: {exam['exam_date']}")

            # Insert exam date
            cursor.execute(
                """
                INSERT INTO exam_dates (id, exam_date, registration_deadline)
                VALUES (%s, %s, %s)
            """,
                (exam_id, exam["exam_date"], exam["registration_deadline"]),
            )

            # Insert levels
            for level in exam["levels"]:
                cursor.execute(
                    """
                    INSERT INTO exam_levels (exam_date_id, level)
                    VALUES (%s, %s)
                """,
                    (exam_id, level),
                )

            print(f"   ✅ Added levels: {', '.join(exam['levels'])}")
            print(f"   🆔 ID: {exam_id}")

        # Commit changes
        conn.commit()
        print(f"\n✅ Successfully inserted {len(EXAM_DATES)} exam dates!")

        # Show current exam dates
        print("\n📋 Current exam dates in database:")
        cursor.execute("""
            SELECT id, exam_date, registration_deadline
            FROM exam_dates
            ORDER BY exam_date
        """)
        exams = cursor.fetchall()

        for exam in exams:
            exam_id, exam_date, deadline = exam
            print(f"   📅 {exam_date} (Deadline: {deadline})")
            print(f"      🆔 {exam_id}")

            # Get levels
            cursor.execute(
                """
                SELECT level FROM exam_levels WHERE exam_date_id = %s ORDER BY level
            """,
                (exam_id,),
            )
            levels = cursor.fetchall()
            print(f"      📊 Levels: {', '.join([level[0] for level in levels])}")

    except mysql.connector.Error as e:
        print(f"❌ MySQL Error: {e}")
    except Exception as e:
        print(f"❌ Error: {e}")
    finally:
        if "conn" in locals() and conn.is_connected():
            cursor.close()
            conn.close()
            print("\n🔌 Database connection closed")


if __name__ == "__main__":
    print("🚀 Exam Dates Insertion Script")
    print("=" * 40)
    print(f"Database: {MYSQL_DATABASE}")
    print(f"Host: {MYSQL_HOST}:{MYSQL_PORT}")
    print(f"User: {MYSQL_USER}")
    print("=" * 40)

    input("\nPress Enter to continue or Ctrl+C to cancel...")

    insert_exam_dates()
