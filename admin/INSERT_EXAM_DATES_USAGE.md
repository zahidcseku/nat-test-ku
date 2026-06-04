# Exam Dates Insertion Script - Usage Guide

## Quick Start

### 1. Configure Database Connection

Edit the configuration section in `insert_exam_dates.py`:

```python
# Database type configuration
DB_TYPE = "mysql"  # Options: "sqlite" or "mysql"

# SQLite Configuration
SQLITE_DB_PATH = "./data/admin.db"

# MySQL Configuration
MYSQL_CONFIG = {
    "host": "your_mysql_host",      # e.g., "localhost" or "192.168.1.100"
    "port": 3306,                   # MySQL port
    "user": "your_username",        # MySQL username
    "password": "your_password",    # MySQL password
    "database": "exam_dates",       # Database name
    "charset": "utf8mb4",
    "collation": "utf8mb4_unicode_ci"
}
```

### 2. Define Exam Dates to Insert

Edit the `EXAM_DATES_TO_INSERT` list:

```python
EXAM_DATES_TO_INSERT = [
    {
        "exam_date": "2026-07-15",
        "registration_deadline": "2026-06-30",
        "levels": ["1Q", "2Q", "3Q"]
    },
    # Add more exam dates as needed
]
```

### 3. Run the Script

```bash
cd admin
python insert_exam_dates.py
```

## Configuration Examples

### Local SQLite Database
```python
DB_TYPE = "sqlite"
SQLITE_DB_PATH = "./data/admin.db"
```

### Remote MySQL Database
```python
DB_TYPE = "mysql"
MYSQL_CONFIG = {
    "host": "ku.ac.bd",           # Remote server
    "port": 3306,
    "user": "exam_admin",
    "password": "secure_password",
    "database": "exam_dates",
    "charset": "utf8mb4",
    "collation": "utf8mb4_unicode_ci"
}
```

### MySQL via SSH Tunnel
```bash
# First create SSH tunnel
ssh -L 3307:localhost:3306 user@remote_server.com

# Then configure script to use tunnel
MYSQL_CONFIG = {
    "host": "localhost",
    "port": 3307,  # Use tunnel port
    # ... rest of config
}
```

## Features

- ✅ **Auto-generated UUIDs**: Each exam date gets a unique ID
- ✅ **Multiple levels**: One exam date can have multiple levels (1Q-5Q)
- ✅ **Database validation**: Ensures data integrity
- ✅ **Transaction safety**: All-or-nothing insertion
- ✅ **Progress feedback**: Shows what's being inserted
- ✅ **Current data display**: Shows existing exam dates before insertion
- ✅ **Confirmation prompt**: Ask before making changes

## Exam Date Format

```python
{
    "exam_date": "YYYY-MM-DD",           # When exam occurs
    "registration_deadline": "YYYY-MM-DD", # Last date to apply
    "levels": ["1Q", "2Q", "3Q"]         # Available levels (1Q-5Q)
}
```

## Available Levels

Only these level values are allowed:
- `"1Q"` - Quarter 1
- `"2Q"` - Quarter 2
- `"3Q"` - Quarter 3
- `"4Q"` - Quarter 4
- `"5Q"` - Quarter 5

## Error Handling

The script handles common errors:
- ❌ Connection failures
- ❌ Invalid level values
- ❌ Duplicate entries
- ❌ Database permission issues
- ❌ Missing required fields

## Database Requirements

### SQLite
- No additional packages needed
- Database file must exist
- Schema must be created first

### MySQL
- Install MySQL connector: `pip install mysql-connector-python`
- Database must exist
- User must have INSERT privileges
- Schema must be created first

## Troubleshooting

### MySQL Connection Issues
```bash
# Test MySQL connection
mysql -h host -u user -p database_name

# Install MySQL connector
pip install mysql-connector-python
```

### SQLite Database Not Found
```bash
# Create database and schema
python -c "from admin.core.database import init_db; init_db()"
```

### Permission Issues
```bash
# SQLite: Make file writable
chmod 664 data/admin.db

# MySQL: Grant privileges
GRANT INSERT, SELECT, UPDATE, DELETE ON exam_dates.* TO 'user'@'host';
```

## Advanced Usage

### Import as Module
```python
from admin.insert_exam_dates import (
    get_database_connection,
    insert_exam_dates,
    EXAM_DATES_TO_INSERT
)

# Get connection
db_conn = get_database_connection()
db_conn.connect()

# Insert exam dates
success = insert_exam_dates(db_conn, EXAM_DATES_TO_INSERT)
db_conn.close()
```

### Custom Exam Data
```python
from admin.insert_exam_dates import get_database_connection
import uuid

# Create custom exam dates
custom_exams = [
    {
        "exam_date": "2026-12-01",
        "registration_deadline": "2026-11-15",
        "levels": ["1Q", "2Q"]
    }
]

db_conn = get_database_connection()
db_conn.connect()
insert_exam_dates(db_conn, custom_exams)
db_conn.close()
```

## Security Notes

⚠️ **Important Security Practices:**

1. **Never commit passwords** to version control
2. **Use environment variables** for sensitive data
3. **Limit database user privileges** to only what's needed
4. **Use SSH tunnels** for remote connections
5. **Keep backups** before bulk insertions

Example using environment variables:
```python
import os

MYSQL_CONFIG = {
    "host": os.getenv("DB_HOST", "localhost"),
    "user": os.getenv("DB_USER", "root"),
    "password": os.getenv("DB_PASSWORD", ""),
    "database": os.getenv("DB_NAME", "exam_dates"),
    # ... rest of config
}
```
