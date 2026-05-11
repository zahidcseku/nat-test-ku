#!/bin/bash
# Start script for NAT-TEST Frontend



# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo "🌐 NAT-TEST Frontend - Starting HTTP Server..."
echo ""

# Default to port 8000, but allow override
PORT=${PORT:-8000}

# Check if port is already in use
if lsof -Pi :$PORT -sTCP:LISTEN -t >/dev/null 2>&1 ; then
    echo -e "${YELLOW}Port $PORT is already in use.${NC}"
    echo "Stopping existing server..."
    lsof -ti:$PORT | xargs kill -9 2>/dev/null || true
    sleep 1
fi

# Start server in background
echo -e "${BLUE}Starting server on port $PORT...${NC}"
python3 -m http.server $PORT &
SERVER_PID=$!

# Save PID for later cleanup
echo $SERVER_PID > /tmp/frontend_server.pid

echo ""
echo -e "${GREEN}✓ Server started successfully!${NC}"
echo ""
echo -e "${BLUE}Frontend URL:${NC}     http://localhost:$PORT"
echo -e "${BLUE}Debug page:${NC}      http://localhost:$PORT/debug.html"
echo ""
echo -e "${YELLOW}Press Ctrl+C to stop the server${NC}"
echo ""

# Function to cleanup on exit
cleanup() {
    echo ""
    echo -e "${YELLOW}Stopping server...${NC}"
    if [ -f /tmp/frontend_server.pid ]; then
        kill $(cat /tmp/frontend_server.pid) 2>/dev/null || true
        rm /tmp/frontend_server.pid
    fi
    echo -e "${GREEN}✓ Server stopped${NC}"
    exit 0
}

# Trap SIGINT and SIGTERM
trap cleanup SIGINT SIGTERM

# Wait for server process (keeps script running)
wait $SERVER_PID 2>/dev/null
