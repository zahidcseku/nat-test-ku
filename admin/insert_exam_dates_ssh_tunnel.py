#!/usr/bin/env python3
"""
Simple example: Insert exam dates into MySQL database.
Configure your database details below.
"""

import uuid
import mysql.connector
from sshtunnel import SSHTunnelForwarder

# ========================================
# CONFIGURE THESE DETAILS
# ========================================

SSH_HOST = "62.30.197.93"
SSH_USER = "nattestkuac"
SSH_PASSWORD = "d8]NB10O_&wkX#3+"

DB_USER = "nattes_reg"
DB_PASS = "OWX0j153i9zR"
DB_NAME = "exam_dates"

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


def generate_sql_statements():
    """
    Generates MySQL INSERT statements for the exam dates and levels.
    Returns a string containing the SQL script.
    """
    sql_lines = [
        "-- Auto-generated SQL statements for Exam Dates",
        "SET AUTOCOMMIT = 0;",
        "START TRANSACTION;",
        "",
    ]

    for exam in EXAM_DATES:
        exam_id = str(uuid.uuid4())

        # Insert into exam_dates
        exam_sql = (
            f"INSERT INTO exam_dates (id, exam_date, registration_deadline) "
            f"VALUES ('{exam_id}', '{exam['exam_date']}', '{exam['registration_deadline']}');"
        )
        sql_lines.append(exam_sql)

        # Insert into exam_levels
        for level in exam["levels"]:
            level_sql = (
                f"INSERT INTO exam_levels (exam_date_id, level) "
                f"VALUES ('{exam_id}', '{level}');"
            )
            sql_lines.append(level_sql)

        sql_lines.append("")  # Newline for readability

    sql_lines.append("COMMIT;")
    sql_lines.append("SET AUTOCOMMIT = 1;")

    return "\n".join(sql_lines)


def run_with_tunnel():
    """Starts SSH tunnel and performs the database insertion."""
    try:
        with SSHTunnelForwarder(
            (SSH_HOST, 22),
            ssh_username=SSH_USER,
            ssh_password=SSH_PASSWORD,
            remote_bind_address=("127.0.0.1", 3306),
        ) as tunnel:
            print(f"✅ SSH Tunnel Active on local port: {tunnel.local_bind_port}")

            conn = mysql.connector.connect(
                host="127.0.0.1",
                port=tunnel.local_bind_port,
                user=DB_USER,
                password=DB_PASS,
                database=DB_NAME,
            )

            print("✅ Database Connected via Tunnel!")
            cursor = conn.cursor()

            for exam in EXAM_DATES:
                exam_id = str(uuid.uuid4())
                cursor.execute(
                    "INSERT INTO exam_dates (id, exam_date, registration_deadline) VALUES (%s, %s, %s)",
                    (exam_id, exam["exam_date"], exam["registration_deadline"]),
                )
                for level in exam["levels"]:
                    cursor.execute(
                        "INSERT INTO exam_levels (exam_date_id, level) VALUES (%s, %s)",
                        (exam_id, level),
                    )

            conn.commit()
            print(f"✅ Successfully inserted {len(EXAM_DATES)} exam dates!")
            conn.close()

    except Exception as e:
        print(f"❌ Error: {e}")


if __name__ == "__main__":
    print("Choose an option:")
    print("1. Insert dates into database via SSH tunnel")
    print("2. Generate SQL statements (text output)")

    choice = input("\nEnter choice (1 or 2): ")

    if choice == "1":
        run_with_tunnel()
    elif choice == "2":
        sql_script = generate_sql_statements()
        print("\n--- BEGIN SQL SCRIPT ---")
        print(sql_script)
        print("--- END SQL SCRIPT ---\n")

        # Optional: Save to file
        with open("insert_exams.sql", "w") as f:
            f.write(sql_script)
        print("💾 SQL script saved to 'insert_exams.sql'")
    else:
        print("Invalid choice.")
