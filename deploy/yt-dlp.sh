#!/bin/bash
set -e

BIN_DIR="$HOME/bin"
YTDLP_PATH="$BIN_DIR/yt-dlp"

# Check if yt-dlp is already installed
if [ -x "$YTDLP_PATH" ]; then
    echo "yt-dlp already installed at $YTDLP_PATH"
    "$YTDLP_PATH" --version
    exit 0
fi

echo "yt-dlp not found, installing..."
mkdir -p "$BIN_DIR"

echo "Downloading yt-dlp..."
curl --silent --show-error --fail -L -o "$YTDLP_PATH" \
    "https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp"

chmod +x "$YTDLP_PATH"

echo "yt-dlp installation completed!"
"$YTDLP_PATH" --version
