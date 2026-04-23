#!/bin/bash
# Start script for NAT-TEST Admin

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo "🎓 NAT-TEST Admin - Starting..."
echo ""

# Check if .env exists
if [ ! -f .env ]; then
    echo -e "${YELLOW}Creating .env file from template...${NC}"
    cp .env.example .env
    echo -e "${GREEN}✓ Created .env file${NC}"
    echo -e "${YELLOW}⚠ Please edit .env with your configuration before continuing${NC}"
    echo ""
fi

# Check if dependencies are installed
if ! python3 -c "import streamlit" 2>/dev/null; then
    echo -e "${YELLOW}Installing dependencies...${NC}"
    pip install -q -r requirements.txt
    echo -e "${GREEN}✓ Dependencies installed${NC}"
    echo ""
fi

# Check if database exists
if [ ! -f data/admin.db ]; then
    echo -e "${YELLOW}Initializing database with seed content...${NC}"
    python scripts/seed_content.py
    echo -e "${GREEN}✓ Database initialized${NC}"
    echo ""
fi

# Start Streamlit
echo -e "${GREEN}Starting admin interface...${NC}"
echo "Admin will be available at: http://127.0.0.1:8501"
echo ""
echo "Press Ctrl+C to stop"
echo ""

streamlit run main.py
