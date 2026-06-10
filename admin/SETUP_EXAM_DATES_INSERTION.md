# Quick Setup Guide for Exam Dates Insertion

## Two Scripts Available

### 1. Simple Script (`insert_exam_dates_simple.py`) - **RECOMMENDED**
- Easy to configure
- MySQL only
- Edit connection details directly in script
- Perfect for one-time insertions

### 2. Advanced Script (`insert_exam_dates.py`)
- Supports SQLite and MySQL
- More features and error handling
- Better for repeated use
- Module support

## Quick Start (Simple Script)

### Step 1: Install MySQL Connector
```bash
pip install mysql-connector-python
```

### Step 2: Edit Configuration
Open `insert_exam_dates_simple.py` and update these lines:

```python
MYSQL_HOST = "your_host"        # e.g., "localhost" or "192.168.1.50"
MYSQL_PORT = 3306               # Default MySQL port
MYSQL_USER = "your_username"    # Your MySQL username
MYSQL_PASSWORD = "your_password" # Your MySQL password
MYSQL_DATABASE = "exam_dates"   # Your database name
```

### Step 3: Add Your Exam Dates
Edit the `EXAM_DATES` list:

```python
EXAM_DATES = [
    {
        "exam_date": "2026-07-15",
        "registration_deadline": "2026-06-30",
        "levels": ["1Q", "2Q", "3Q"]
    },
    # Add more exam dates as needed
]
```

### Step 4: Run the Script
```bash
cd admin
python insert_exam_dates_simple.py
```

## Example Configuration

### For Local MySQL
```python
MYSQL_HOST = "localhost"
MYSQL_USER = "root"
MYSQL_PASSWORD = ""
MYSQL_DATABASE = "exam_dates"
```

### For Remote MySQL
```python
MYSQL_HOST = "ku.ac.bd"          # Remote server
MYSQL_USER = "exam_admin"
MYSQL_PASSWORD = "secure_password"
MYSQL_DATABASE = "exam_dates"
```

### For MySQL via SSH Tunnel
```bash
# First create SSH tunnel
ssh -L 3307:localhost:3306 user@remote_server.com

# Then use in script
MYSQL_HOST = "localhost"
MYSQL_PORT = 3307                # Use tunnel port
MYSQL_USER = "remote_user"
MYSQL_PASSWORD = "remote_password"
MYSQL_DATABASE = "exam_dates"
```

## What the Script Does

1. **Connects** to your MySQL database
2. **Generates** unique UUID for each exam date
3. **Inserts** exam date and registration deadline
4. **Inserts** all associated levels (1Q-5Q)
5. **Displays** current exam dates in database
6. **Closes** connection safely

## Example Output

```
🚀 Exam Dates Insertion Script
========================================
Database: exam_dates
Host: localhost:3306
User: root
========================================

Press Enter to continue or Ctrl+C to cancel...

🔧 Connecting to MySQL at localhost:3306...
✅ Connected successfully!

📅 Inserting exam 1: 2026-07-15
   ✅ Added levels: 1Q, 2Q, 3Q
   🆔 ID: a1b2c3d4-e5f6-7890-abcd-ef1234567890

📅 Inserting exam 2: 2026-08-20
   ✅ Added levels: 4Q, 5Q
   🆔 ID: b2c3d4e5-f6g7-8901-bcde-f23456789012

✅ Successfully inserted 2 exam dates!

📋 Current exam dates in database:
   📅 2026-07-15 (Deadline: 2026-06-30)
      🆔 a1b2c3d4-e5f6-7890-abcd-ef1234567890
      📊 Levels: 1Q, 2Q, 3Q
   📅 2026-08-20 (Deadline: 2026-07-31)
      🆔 b2c3d4e5-f6g7-8901-bcde-f23456789012
      📊 Levels: 4Q, 5Q

🔌 Database connection closed
```

## Troubleshooting

### MySQL Connection Error
```bash
# Check if MySQL is running
sudo systemctl status mysql

# Test connection
mysql -h localhost -u root -p

# Check if database exists
mysql -u root -p -e "SHOW DATABASES;"
```

### Permission Denied
```sql
-- Grant privileges in MySQL
GRANT INSERT, SELECT, UPDATE, DELETE ON exam_dates.* TO 'your_user'@'your_host';
FLUSH PRIVILEGES;
```

### Database Not Found
```sql
-- Create database first
CREATE DATABASE exam_dates CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Run schema creation
mysql -u root -p exam_dates < frontend/intake/schema/create_exam_dates_mysql.sql
```

### Python Module Not Found
```bash
# Install MySQL connector
pip install mysql-connector-python

# Or using pip3
pip3 install mysql-connector-python
```

## Security Best Practices

1. **Never hardcode passwords** in production code
2. **Use environment variables** for sensitive data
3. **Limit database user** privileges to minimum required
4. **Use SSH tunnels** for remote connections
5. **Keep backups** before making changes

### Using Environment Variables
```python
import os

MYSQL_HOST = os.getenv("DB_HOST", "localhost")
MYSQL_USER = os.getenv("DB_USER", "root")
MYSQL_PASSWORD = os.getenv("DB_PASSWORD", "")
MYSQL_DATABASE = os.getenv("DB_NAME", "exam_dates")
```

### Run with Environment Variables
```bash
export DB_HOST="localhost"
export DB_USER="root"
export DB_PASSWORD="your_password"
export DB_NAME="exam_dates"

python insert_exam_dates_simple.py
```

## Next Steps

After inserting exam dates:

1. ✅ Verify data in database
2. ✅ Test admin interface
3. ✅ Export to JSON for frontend
4. ✅ Update registration form dropdown
5. ✅ Test registration process
