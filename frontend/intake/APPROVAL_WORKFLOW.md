# Approval Workflow Guide

This document explains the approval workflow for registration applications.

## Database Schema

All registrations include these approval-related fields:

```sql
approved TINYINT(1) DEFAULT 0           -- 0 = pending, 1 = approved
approved_at TIMESTAMP NULL              -- When approval happened
approved_by VARCHAR(100) NULL           -- Admin username/email
```

## Workflow States

### 1. Pending (Default)
- `approved = 0`
- `approved_at = NULL`
- `approved_by = NULL`
- This is the state for all new registrations

### 2. Approved
- `approved = 1`
- `approved_at = CURRENT_TIMESTAMP`
- `approved_by = 'admin_username'`
- Application approved and ready for frontend display

### 3. Rejected (Optional)
- Keep `approved = 0`
- Add `rejected_at` and `rejected_reason` fields (future enhancement)
- Or simply delete the record

## SQL Queries for Admin Service

### Pull Pending Registrations

```sql
SELECT id, full_name, email, mobile, exam_level, test_date, submitted_at
FROM registrations
WHERE approved = 0
ORDER BY submitted_at DESC;
```

### Pull Registration with Files for Review

```sql
SELECT *
FROM registrations
WHERE id = 'uuid-here' AND approved = 0;
```

### Approve a Registration

```sql
UPDATE registrations
SET approved = 1,
    approved_at = CURRENT_TIMESTAMP,
    approved_by = 'admin@example.com'
WHERE id = 'uuid-here';
```

### Pull Approved Registrations for Frontend

```sql
SELECT id, full_name, exam_level, test_date
FROM registrations
WHERE approved = 1
ORDER BY approved_at DESC;
```

### Statistics

```sql
-- Count pending applications
SELECT COUNT(*) as pending_count
FROM registrations
WHERE approved = 0;

-- Count approved applications
SELECT COUNT(*) as approved_count
FROM registrations
WHERE approved = 1;

-- Applications by exam level
SELECT exam_level, COUNT(*) as count
FROM registrations
WHERE approved = 1
GROUP BY exam_level;
```

## Admin Service Integration

### Step 1: Pull Data

```bash
# Pull new registrations from intake service
mysqldump -u intake_user -p nat_test_intake \
  --where="approved = 0" \
  registrations > pending_registrations.sql

# Import into local admin database
sqlite3 admin.db <<EOF
.mode csv
.import pending_registrations.sql registrations
EOF
```

### Step 2: Display in Admin Interface

- Show pending registrations list
- Click to view full details
- Display uploaded photos and IDs
- Show payment receipts

### Step 3: Approve/Reject

```python
# Pseudocode for admin service
def approve_registration(registration_id, admin_username):
    # Update both databases
    update_local_db(registration_id, admin_username)
    update_intake_db(registration_id, admin_username)
    publish_to_frontend(registration_id)
```

### Step 4: Publish to Frontend

```bash
# Export approved registrations
sqlite3 admin.db "SELECT * FROM registrations WHERE approved = 1" > approved.json

# Upload to frontend
rsync -avz approved.json frontend:/var/www/data/participants.json
```

## File Handling

When pulling files from intake service:

```bash
# Pull files for a specific registration
rsync -avz \
  user@intake-server:/path/to/intake/uploads/photos/{uuid}.jpg \
  ./data/photos/

rsync -avz \
  user@intake-server:/path/to/intake/uploads/ids/{uuid}.* \
  ./data/ids/

# Pull payment receipts
rsync -avz \
  user@intake-server:/path/to/intake/uploads/receipts/{uuid}.* \
  ./data/receipts/
```

## Testing

### Test Approval Workflow

```sql
-- Create test registration
INSERT INTO registrations (id, full_name, email, approved, ...)
VALUES ('test-uuid', 'Test User', 'test@example.com', 0, ...);

-- Verify it's pending
SELECT approved FROM registrations WHERE id = 'test-uuid';
-- Expected: 0

-- Approve it
UPDATE registrations
SET approved = 1, approved_at = CURRENT_TIMESTAMP, approved_by = 'admin'
WHERE id = 'test-uuid';

-- Verify it's approved
SELECT approved, approved_at, approved_by FROM registrations WHERE id = 'test-uuid';
-- Expected: 1, [timestamp], 'admin'

-- Pull for frontend
SELECT * FROM registrations WHERE approved = 1;
-- Expected: Test registration appears
```

## Security Considerations

1. **Approval Permissions**: Only admin users should approve registrations
2. **Audit Trail**: The `approved_by` field tracks who approved what
3. **Timestamp**: `approved_at` provides approval history
4. **No Direct Access**: Intake service never exposes approval endpoint
5. **Admin-Only Updates**: Only admin service updates approval status

## Future Enhancements

Possible additions to the approval system:

1. **Rejection handling**: Add `rejected_at`, `rejected_by`, `rejected_reason` fields
2. **Multi-stage approval**: Add `review_status` enum (pending, under_review, approved, rejected)
3. **Approval notes**: Add `approval_notes` text field for admin comments
4. **Bulk approval**: Admin can approve multiple applications at once
5. **Auto-approval**: Rules-based approval for complete applications

## Troubleshooting

### Registration not appearing in frontend

```sql
-- Check if approved
SELECT approved FROM registrations WHERE id = 'uuid';
-- If 0, needs approval

-- Check frontend export
SELECT COUNT(*) FROM registrations WHERE approved = 1;
-- Should be > 0
```

### Can't update approval status

- Ensure admin service has UPDATE permissions on intake database
- Check database user privileges: `GRANT UPDATE ON nat_test_intake.* TO 'admin_user'`
- Verify connection to intake database

### Missing approval fields

If deployment predates this feature:

```bash
mysql -u intake_user -p nat_test_intake < migrations/add_approval_fields.sql
```
