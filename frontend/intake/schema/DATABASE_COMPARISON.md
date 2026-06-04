# Database Schema Comparison: SQLite vs MySQL

## Quick Reference Guide

| Aspect | SQLite | MySQL |
|--------|--------|-------|
| **File** | `create_exam_dates.sql` | `create_exam_dates_mysql.sql` |
| **Deploy Script** | `deploy_exam_dates.sh` | `deploy_exam_dates_mysql.sh` |
| **Best For** | Local development, small deployments | Production servers, high concurrency |

## Schema Differences

### UUID Storage
- **SQLite**: `TEXT` - Flexible storage, no size limit
- **MySQL**: `CHAR(36)` - Optimized for UUID format, fixed width

### Date Handling
- **SQLite**: `TEXT` - Stores dates as ISO format strings ('2026-07-15')
- **MySQL**: `DATE` - Native date type with built-in validation

### Level Constraints
- **SQLite**: `CHECK(level IN ('1Q', '2Q', '3Q', '4Q', '5Q'))`
- **MySQL**: `ENUM('1Q', '2Q', '3Q', '4Q', '5Q')` - More efficient storage

### Performance Features
- **SQLite**: Lightweight, embedded, no server process
- **MySQL**: `InnoDB` engine, foreign key constraints, concurrent access

## Application Code Changes

When switching between SQLite and MySQL, update your database connection:

### Python SQLite Example
```python
import sqlite3
conn = sqlite3.connect('database.db')
conn.row_factory = sqlite3.Row
```

### Python MySQL Example
```python
import mysql.connector
conn = mysql.connector.connect(
    host='localhost',
    user='username',
    password='password',
    database='exam_dates'
)
```

## UUID Generation

Both schemas use the same UUID generation method:
```python
import uuid
exam_id = str(uuid.uuid4())  # Generates: 'a1b2c3d4-e5f6-7890-abcd-ef1234567890'
```

## Data Validation

### SQLite Validation
- Application-level validation recommended
- CHECK constraints provide basic validation
- Date format validation in application code

### MySQL Validation
- Database-level validation with DATE type
- ENUM provides strict level validation
- Automatic date format checking

## Migration Considerations

### SQLite → MySQL Migration
```sql
-- Export from SQLite
sqlite3 exam.db .dump > dump.sql

-- Convert and import to MySQL
# Update TEXT -> CHAR(36) for UUIDs
# Update TEXT -> DATE for date fields
# Update CHECK -> ENUM for levels
mysql -u root -p exam_dates < converted_dump.sql
```

### MySQL → SQLite Migration
```bash
# Export from MySQL
mysqldump -u root -p exam_dates > dump.sql

# Convert and import to SQLite
# Update CHAR(36) -> TEXT for UUIDs
# Update DATE -> TEXT for date fields
# Update ENUM -> CHECK for levels
sqlite3 exam.db < converted_dump.sql
```

## Deployment Recommendations

### Use SQLite When:
- ✅ Local development environment
- ✅ Single-user admin interface
- ✅ Simple deployment without database server
- ✅ Low traffic websites
- ✅ Embedded applications

### Use MySQL When:
- ✅ Production server deployment
- ✅ Multiple concurrent users
- ✅ High-performance requirements
- ✅ Existing MySQL infrastructure
- ✅ Advanced database features needed

## Compatibility Notes

Both schemas maintain:
- ✅ Same table structure (exam_dates, exam_levels)
- ✅ Same relationships (foreign keys with cascade delete)
- ✅ Same validation rules (levels 1Q-5Q)
- ✅ Same indexing strategy (exam_date index)
- ✅ Same UUID format (36 characters)

Application code can work with either schema by only changing the database connection configuration.
