-- Exam Dates Database Schema
-- Run this script on the target SQLite database
-- Usage: sqlite3 database.db < create_exam_dates.sql

-- Create exam_dates table
-- Note: ID should be auto-generated UUID using str(uuid.uuid4())
CREATE TABLE IF NOT EXISTS exam_dates (
    id TEXT PRIMARY KEY,
    exam_date TEXT NOT NULL,
    registration_deadline TEXT NOT NULL
);

-- Create exam_levels table (junction table for many-to-many relationship)
CREATE TABLE IF NOT EXISTS exam_levels (
    exam_date_id TEXT NOT NULL REFERENCES exam_dates(id) ON DELETE CASCADE,
    level TEXT NOT NULL CHECK(level IN ('N1', 'N2', 'N3', 'N4', 'N5')),
    PRIMARY KEY (exam_date_id, level)
);

-- Create index for exam_date queries
CREATE INDEX IF NOT EXISTS idx_exam_dates_date ON exam_dates(exam_date);

-- Sample data (optional - uncomment to insert test data)
/*
INSERT INTO exam_dates (id, exam_date, registration_deadline) VALUES
    ('exam-001', '2026-07-15', '2026-06-30'),
    ('exam-002', '2026-08-20', '2026-07-31');

INSERT INTO exam_levels (exam_date_id, level) VALUES
    ('exam-001', 'N1'),
    ('exam-001', 'N2'),
    ('exam-001', 'N3'),
    ('exam-002', 'N4'),
    ('exam-002', 'N5');
*/

-- Verify tables were created
SELECT 'exam_dates table created: ' || COUNT(*) as message FROM exam_dates;
SELECT 'exam_levels table created: ' || COUNT(*) as message FROM exam_levels;
