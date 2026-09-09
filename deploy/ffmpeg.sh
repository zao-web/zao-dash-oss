#!/bin/bash
set -e

BIN_DIR="$HOME/bin"
FFMPEG_PATH="$BIN_DIR/ffmpeg/ffmpeg"

# Check if FFmpeg is already installed
if [ -x "$FFMPEG_PATH" ]; then
    echo "FFmpeg already installed at $FFMPEG_PATH"
    "$FFMPEG_PATH" -version | head -1
    exit 0
fi

echo "FFmpeg not found, installing..."
mkdir -p "$BIN_DIR"

(
  cd "$BIN_DIR"
  echo "Downloading FFmpeg..."
  curl --silent --show-error --fail -L -o ffmpeg.tar.xz \
    "https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-arm64-static.tar.xz"
  echo "Extracting FFmpeg..."
  tar -xf ffmpeg.tar.xz
  mv ffmpeg-*-static ffmpeg
  chmod +x ffmpeg/ffmpeg ffmpeg/ffprobe
  rm -f ffmpeg.tar.xz
)

echo "FFmpeg installation completed!"
"$FFMPEG_PATH" -version | head -1
