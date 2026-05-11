# FTP Deployment Setup Guide

## GitHub Actions will now automatically deploy your frontend via FTP!

### Required GitHub Secrets

You need to add these secrets to your GitHub repository:

**Go to:** `GitHub Repository → Settings → Secrets and variables → Actions → New repository secret`

## 1. FTP_SERVER
Your FTP server address

Examples:
- `ftp://nat-test.ku.ac.bd`
- `ftp://your-domain.com`
- `192.168.1.100` (server IP address)
- `your-server.com`

**Note:** Include the `ftp://` prefix if required by your hosting provider.

## 2. FTP_USERNAME
Your FTP username

Examples:
- `your-username`
- `nat-test@ku.ac.bd`
- `your-cpanel-username`

## 3. FTP_PASSWORD
Your FTP password

**This is the same password you use for:**
- cPanel login
- FTP client (FileZilla, etc.)
- Web-based file manager

## 4. FTP_SERVER_DIR
The directory where your website files should be uploaded

Examples:
- `/public_html`
- `/www`
- `/var/www/html`
- `/public_html/nat-test`
- `/`

**Tip:** Check your current hosting control panel to find the correct path.

## How to Find Your FTP Credentials

### cPanel Hosting:
1. Login to cPanel
2. Go to **Files** → **FTP Accounts**
3. Create or find existing FTP account
4. Copy the credentials

### Plesk Hosting:
1. Login to Plesk
2. Go to **Websites & Domains**
3. Click **FTP Access**
4. View/create FTP accounts

### Web-based File Manager:
1. Login to your hosting control panel
2. Look for **FTP** or **File Manager** section
3. Find FTP connection details

### Testing FTP Connection Locally:
```bash
# Test FTP connection
ftp your-server.com
# Enter username and password when prompted
# If connection works, credentials are correct
```

## What Gets Deployed

The workflow will upload:
- ✅ All HTML files (`*.html`)
- ✅ CSS directory with compiled styles
- ✅ JavaScript files
- ✅ Images and media files
- ✅ Data files (JSON content)

The workflow excludes:
- ❌ Node modules
- ❌ Source files (input.css, tailwind.config.js)
- ❌ Git files
- ❌ Development files

## After Setup

Once secrets are configured:
1. Push changes to `frontend/` directory
2. GitHub Actions automatically builds CSS
3. Changes are deployed to your server via FTP
4. Visit your website to see updates!

## Testing the Deployment

After setting up secrets:
1. Make a small change to any frontend file
2. Push to GitHub
3. Watch the Actions tab
4. If deployment fails, check the error logs for FTP issues

## Common FTP Issues

**Connection timeout:**
- Check FTP_SERVER format
- Verify server allows FTP connections
- Some hosts require SFTP instead

**Authentication failed:**
- Verify username/password
- Check if IP needs to be whitelisted
- Some hosts require passive FTP mode

**Permission denied:**
- Check FTP_SERVER_DIR path
- Verify user has write permissions
- Contact hosting provider if issues persist
