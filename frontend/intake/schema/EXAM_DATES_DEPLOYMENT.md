# Exam Dates Database Deployment

## Architecture Context

**IMPORTANT:** `/intake/` is INSIDE the `/frontend/` directory at `/frontend/intake/`, NOT at the project root.

According to the project architecture, exam dates should be deployed to the **local admin database**, not the intake server. Here's why:

- **Admin interface** (local Streamlit app at `/admin/`) manages exam dates
- **Frontend** (static site at `/frontend/`) displays exam dates in dropdowns
- **Intake service** (PHP service at `/frontend/intake/`) receives registration data but doesn't need to manage exam dates

## Deployment Options

### Database System Choice

**Available schemas:**
- **SQLite** (`create_exam_dates.sql`) - For local development or lightweight deployment
- **MySQL** (`create_exam_dates_mysql.sql`) - For production server deployment

Choose the schema that matches your database system.

### Option 1: Local Admin Database (SQLite - Recommended)

The exam dates tables already exist in your local admin database at:
```
/Users/zahid/projects/NAT_TEST_KU/data/admin.db
```

**No additional deployment needed** - the tables are already created and ready to use.

### Option 2: Intake Server Database (If needed)

If you need exam dates on the intake server for validation or other purposes:

#### SQLite Deployment
```bash
# SSH to the Khulna University server
ssh ku@ku.ac.bd

# Navigate to the intake directory
cd /var/intake

# Backup existing database
cp inbox.db inbox.db.backup.$(date +%Y%m%d_%H%M%S)

# Create the exam dates tables
sqlite3 inbox.db < schema/create_exam_dates.sql

# Verify tables were created
sqlite3 inbox.db ".tables"
sqlite3 inbox.db ".schema exam_dates"
```

#### MySQL Deployment
```bash
# Use the automated deployment script
cd frontend/intake/schema
./deploy_exam_dates_mysql.sh root@localhost exam_dates

# Or manually:
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS exam_dates;"
mysql -u root -p exam_dates < schema/create_exam_dates_mysql.sql

# Verify tables were created
mysql -u root -p exam_dates -e "SHOW TABLES;"
mysql -u root -p exam_dates -e "DESCRIBE exam_dates;"
```

## Database Location Summary

| Service | Database Path | Purpose |
|---------|--------------|---------|
| **Local Admin** | `data/admin.db` | ✅ Exam dates management (primary) |
| **Intake Server** | `/var/intake/inbox.db` | Registration inbox only |

## Sync Considerations

Since exam dates are managed locally:
1. Admin interface enters/edits exam dates in local `admin.db`
2. Admin interface exports to `frontend/data/exam_dates.json`
3. Frontend displays exam dates from JSON file
4. Registration form reads from frontend JSON

The intake server doesn't need exam dates unless you want to validate that users are registering for valid exam dates during submission.

## Verification

To verify exam dates tables exist:

```bash
# Local admin database
sqlite3 data/admin.db ".tables" | grep exam
sqlite3 data/admin.db ".schema exam_dates"

# If deployed to intake server
sqlite3 /var/intake/inbox.db ".tables" | grep exam
```

## Next Steps

1. Build admin interface for CRUD operations on exam dates
2. Create export function to generate `frontend/data/exam_dates.json`
3. Update registration form to read exam dates from JSON
4. Add validation in intake service (if needed)

## SQLite vs MySQL Schema Differences

| Feature | SQLite | MySQL |
|---------|--------|-------|
| UUID Storage | `TEXT` | `CHAR(36)` |
| Date Fields | `TEXT` (ISO format) | `DATE` |
| Level Constraint | `CHECK(level IN (...))` | `ENUM('1Q', '2Q', '3Q', '4Q', '5Q')` |
| Table Engine | N/A | `InnoDB` |
| Character Set | N/A | `utf8mb4_unicode_ci` |
| Concatenation | `\|\|` operator | `CONCAT()` function |

Both schemas support:
- ✅ Auto-generated UUIDs (36-character format)
- ✅ Multiple levels per exam date
- ✅ Cascade delete (exam_dates → exam_levels)
- ✅ Index on exam_date for performance
- ✅ Validated level values (1Q-5Q)
