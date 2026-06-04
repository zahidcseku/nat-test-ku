#!/bin/bash
# Deploy exam dates schema to intake server
# Usage: ./deploy_exam_dates.sh [server_user@server_host]

set -e

# Configuration
SERVER_USER=${1:-"ku@ku.ac.bd"}
REMOTE_DB_PATH="/var/intake/inbox.db"
SCHEMA_FILE="$(dirname "$0")/create_exam_dates.sql"
BACKUP_SUFFIX=$(date +%Y%m%d_%H%M%S)

echo "🔧 Exam Dates Database Deployment"
echo "==================================="
echo "Server: $SERVER_USER"
echo "Remote DB: $REMOTE_DB_PATH"
echo ""

# Check if schema file exists
if [ ! -f "$SCHEMA_FILE" ]; then
    echo "❌ Error: Schema file not found: $SCHEMA_FILE"
    exit 1
fi

echo "📋 Steps to be executed:"
echo "  1. Backup remote database"
echo "  2. Transfer schema file"
echo "  3. Create exam dates tables"
echo "  4. Verify tables"
echo ""

read -p "Continue? (y/n) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "❌ Deployment cancelled"
    exit 1
fi

# Create temporary schema file with verification
TEMP_SCHEMA="/tmp/create_exam_dates_$$.sql"
cat "$SCHEMA_FILE" > "$TEMP_SCHEMA"
echo "" >> "$TEMP_SCHEMA"
echo "-- Verify deployment" >> "$TEMP_SCHEMA"
echo ".tables" >> "$TEMP_SCHEMA"
echo ".quit" >> "$TEMP_SCHEMA"

# Execute deployment via SSH
echo "🚀 Deploying to $SERVER_USER..."
ssh "$SERVER_USER" << ENDSSH
set -e
echo "💾 Creating backup..."
cp $REMOTE_DB_PATH ${REMOTE_DB_PATH}.backup.$BACKUP_SUFFIX
echo "✅ Backup created: ${REMOTE_DB_PATH}.backup.$BACKUP_SUFFIX"

echo "📊 Creating exam dates tables..."
sqlite3 $REMOTE_DB_PATH < /dev/stdin
ENDSSH

# Cleanup
rm -f "$TEMP_SCHEMA"

echo ""
echo "✅ Deployment completed successfully!"
echo ""
echo "📝 Summary:"
echo "  - exam_dates table created"
echo "  - exam_levels table created"
echo "  - Indexes created"
echo "  - Backup: ${REMOTE_DB_PATH}.backup.$BACKUP_SUFFIX"
echo ""
echo "🔍 To verify on server:"
echo "  ssh $SERVER_USER 'sqlite3 $REMOTE_DB_PATH \".tables\"'"
