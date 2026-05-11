#!/bin/bash
# NAT-TEST Admin Startup Script
# Ensures dependencies are present before launching the server

cd "$(dirname "$0")"

echo "📦 Checking and updating dependencies..."
python3 -m pip install --break-system-packages pydantic python-dotenv streamlit st-tiny-editor

if [ $? -eq 0 ]; then
    echo "🚀 Dependencies verified. Starting NAT-TEST Admin..."
    streamlit run main.py
else
    echo "❌ Error: Failed to install dependencies. Please check your internet connection or permissions."
    exit 1
fi