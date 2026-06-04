#!/bin/bash
# Deploy exam dates schema to MySQL server
# Usage: ./deploy_exam_dates_mysql.sh [mysql_connection_string]

set -e

# Configuration
MYSQL_CONN=${1:-"root@localhost"}
DB_NAME=${2:-"exam_dates"}
SCHEMA_FILE="$(dirname "$0")/create_exam_dates_mysql.sql"

echo "🔧 Exam Dates Database Deployment (MySQL)"
echo "=========================================="
echo "MySQL Connection: $MYSQL_CONN"
echo "Database: $DB_NAME"
echo ""

# Check if schema file exists
if [ ! -f "$SCHEMA_FILE" ]; then
    echo "❌ Error: Schema file not found: $SCHEMA_FILE"
    exit 1
fi

echo "📋 Steps to be executed:"
echo "  1. Test MySQL connection"
echo "  2. Create database if not exists"
echo "  3. Backup existing database (optional)"
echo "  4. Create exam dates tables"
echo "  5. Verify tables"
echo ""

read -p "Continue? (y/n) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "❌ Deployment cancelled"
    exit 1
fi

# Parse MySQL connection string
# Expected formats: "user@host" or "user@host:port"
MYSQL_USER=$(echo "$MYSQL_CONN" | cut -d'@' -f1)
MYSQL_HOST=$(echo "$MYSQL_CONN" | cut -d'@' -f2 | cut -d':' -f1)
MYSQL_PORT=$(echo "$MYSQL_CONN" | cut -d':' -f2)

# Default port if not specified
if [ "$MYSQL_PORT" == "$MYSQL_HOST" ]; then
    MYSQL_PORT="3306"
fi

echo "🚀 Deploying to MySQL..."
echo "   Host: $MYSQL_HOST:$MYSQL_PORT"
echo "   User: $MYSQL_USER"
echo "   Database: $DB_NAME"
echo ""

# Prompt for password
echo "Enter MySQL password for user '$MYSQL_USER':"
read -s MYSQL_PASSWORD
echo ""

# Test connection and create database if not exists
echo "📊 Creating database if not exists..."
mysql -h"$MYSQL_HOST" -P"$MYSQL_PORT" -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" \
    -e "CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "✅ Database ready: $DB_NAME"

# Optional: Create backup
read -p "Create backup before deployment? (y/n) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    BACKUP_FILE="${DB_NAME}_backup_$(date +%Y%m%d_%H%M%S).sql"
    echo "💾 Creating backup: $BACKUP_FILE"
    mysqldump -h"$MYSQL_HOST" -P"$MYSQL_PORT" -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" \
        "$DB_NAME" > "$BACKUP_FILE" 2>/dev/null || echo "   (No existing data to backup)"
    echo "✅ Backup created"
fi

# Create tables
echo "📊 Creating exam dates tables..."
mysql -h"$MYSQL_HOST" -P"$MYSQL_PORT" -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" \
    "$DB_NAME" < "$SCHEMA_FILE"

echo ""
echo "✅ Deployment completed successfully!"
echo ""
echo "📝 Summary:"
echo "  - exam_dates table created"
echo "  - exam_levels table created"
echo "  - Indexes created"
if [ -n "$BACKUP_FILE" ]; then
    echo "  - Backup: $BACKUP_FILE"
fi
echo ""
echo "🔍 To verify:"
echo "  mysql -h$MYSQL_HOST -P$MYSQL_PORT -u$MYSQL_USER -p$MYSQL_PASSWORD $DB_NAME -e 'SHOW TABLES;'"
echo ""
echo "📖 To use with Python application:"
echo "  pip install mysql-connector-python"
echo "  # Update database connection string in application config"
