#!/bin/bash
set -e

BIN_DIR="$HOME/bin"
CHROME_DIR="$BIN_DIR/chrome"
CHROME_PATH="$CHROME_DIR/chrome-headless-shell"

# Function to verify binary actually runs on this system
verify_binary() {
    local binary="$1"
    if [ ! -f "$binary" ]; then
        echo "Binary not found: $binary"
        return 1
    fi

    if [ ! -x "$binary" ]; then
        echo "Binary not executable: $binary"
        return 1
    fi

    # Follow symlinks
    local real_binary=$(readlink -f "$binary" 2>/dev/null || echo "$binary")
    echo "Checking binary: $real_binary"
    echo "System arch: $(uname -m)"

    # Actually try to run it - this is the most reliable test
    local output
    output=$("$real_binary" --version 2>&1)
    local exit_code=$?

    echo "Exit code: $exit_code"
    echo "Output: $output"

    # Check for architecture mismatch errors
    if echo "$output" | grep -qE "ELF|not found|cannot execute|Exec format error"; then
        echo "Architecture mismatch detected!"
        return 1
    fi

    # Success if exit code is 0 or 1 (some Chrome versions return 1 for --version)
    if [ $exit_code -le 1 ]; then
        echo "Binary verification passed"
        return 0
    fi

    echo "Binary failed with exit code $exit_code"
    return 1
}

# Check if Chrome is already installed AND working
if [ -x "$CHROME_PATH" ]; then
    echo "Chrome found at $CHROME_PATH, verifying it works..."

    # First verify architecture matches
    if ! verify_binary "$CHROME_PATH"; then
        echo "Chrome binary exists but has wrong architecture"
        echo "Removing and re-installing..."
        rm -rf "$CHROME_DIR"
        mkdir -p "$CHROME_DIR"
    elif "$CHROME_PATH" --version 2>/dev/null; then
        echo "Chrome headless shell already installed and working"
        exit 0
    else
        echo "Chrome binary exists but doesn't work (corrupted or missing dependencies)"
        echo "Removing and re-installing..."
        rm -rf "$CHROME_DIR"
        mkdir -p "$CHROME_DIR"
    fi
fi

echo "Chrome headless shell not found, installing..."
mkdir -p "$CHROME_DIR"

ARCH=$(uname -m)
echo "Detected architecture: $ARCH"

# Laravel Cloud runs on ARM64 (Graviton) - detect this environment
if [ -n "$LARAVEL_CLOUD" ] || [ -d "/var/www" ]; then
    echo "Laravel Cloud environment detected"
    # Double-check we're getting the right architecture
    echo "Kernel: $(uname -a)"
fi

if [ "$ARCH" = "x86_64" ]; then
    # For x64, use Chrome for Testing headless shell (small, fast)
    echo "Downloading Chrome headless shell for linux64..."

    # Get the latest stable version
    CHROME_VERSION=$(curl -s "https://googlechromelabs.github.io/chrome-for-testing/LATEST_RELEASE_STABLE")

    if [ -z "$CHROME_VERSION" ]; then
        # Fallback to a known working version
        CHROME_VERSION="131.0.6778.204"
    fi

    echo "Using Chrome version: $CHROME_VERSION"

    DOWNLOAD_URL="https://storage.googleapis.com/chrome-for-testing-public/${CHROME_VERSION}/linux64/chrome-headless-shell-linux64.zip"

    (
        cd "$BIN_DIR"
        echo "Downloading from $DOWNLOAD_URL..."
        curl --silent --show-error --fail -L -o chrome-headless-shell.zip "$DOWNLOAD_URL"
        echo "Extracting Chrome headless shell..."
        unzip -q -o chrome-headless-shell.zip
        mv chrome-headless-shell-linux64/* "$CHROME_DIR/"
        rmdir chrome-headless-shell-linux64
        chmod +x "$CHROME_DIR/chrome-headless-shell"
        rm -f chrome-headless-shell.zip
    )

    # Verify the downloaded binary
    if ! verify_binary "$CHROME_PATH"; then
        echo "ERROR: Downloaded binary doesn't match system architecture!"
        echo "Expected x86_64 but got something else."
        rm -rf "$CHROME_DIR"
        exit 1
    fi

    echo "Chrome headless shell installation completed!"
    "$CHROME_PATH" --version 2>/dev/null || echo "Chrome headless shell installed at $CHROME_PATH"

elif [ "$ARCH" = "aarch64" ] || [ "$ARCH" = "arm64" ]; then
    # For ARM64, Chrome for Testing now supports linux-arm64
    echo "ARM64 detected - downloading Chrome for Testing ARM64..."

    # Get the latest stable version
    CHROME_VERSION=$(curl -s "https://googlechromelabs.github.io/chrome-for-testing/LATEST_RELEASE_STABLE")

    if [ -z "$CHROME_VERSION" ]; then
        # Fallback to a known working version
        CHROME_VERSION="131.0.6778.204"
    fi

    echo "Using Chrome version: $CHROME_VERSION"

    # Try Chrome for Testing ARM64 first (added in late 2024)
    DOWNLOAD_URL="https://storage.googleapis.com/chrome-for-testing-public/${CHROME_VERSION}/linux-arm64/chrome-headless-shell-linux-arm64.zip"

    echo "Attempting Chrome for Testing ARM64 download..."
    if curl --silent --show-error --fail -L -o "$BIN_DIR/chrome-headless-shell.zip" "$DOWNLOAD_URL" 2>/dev/null; then
        (
            cd "$BIN_DIR"
            echo "Extracting Chrome headless shell for ARM64..."
            unzip -q -o chrome-headless-shell.zip
            mv chrome-headless-shell-linux-arm64/* "$CHROME_DIR/"
            rmdir chrome-headless-shell-linux-arm64 2>/dev/null || true
            chmod +x "$CHROME_DIR/chrome-headless-shell"
            rm -f chrome-headless-shell.zip
        )

        # Verify the downloaded binary
        if verify_binary "$CHROME_PATH"; then
            echo "Chrome for Testing ARM64 installation completed!"
            "$CHROME_PATH" --version 2>/dev/null || echo "Chrome headless shell installed at $CHROME_PATH"
        else
            echo "Chrome for Testing ARM64 binary verification failed, trying Puppeteer..."
            rm -rf "$CHROME_DIR"
            mkdir -p "$CHROME_DIR"
        fi
    else
        echo "Chrome for Testing ARM64 not available for this version, using Puppeteer fallback..."
    fi

    # Fallback to Puppeteer if Chrome for Testing ARM64 didn't work
    if [ ! -x "$CHROME_PATH" ] || ! verify_binary "$CHROME_PATH" 2>/dev/null; then
        echo "Using Puppeteer to install Chrome for ARM64..."

        # Create a temporary directory for Puppeteer
        PUPPETEER_CACHE="$BIN_DIR/.cache/puppeteer"
        mkdir -p "$PUPPETEER_CACHE"

        # Install Puppeteer and let it download Chrome
        export PUPPETEER_CACHE_DIR="$PUPPETEER_CACHE"

        # Try different browsers that might have ARM64 support
        # chromium has better ARM64 Linux support than Chrome
        BROWSERS_TO_TRY="chromium chrome-headless-shell chrome"

        for BROWSER in $BROWSERS_TO_TRY; do
            echo ""
            echo "Trying to install $BROWSER for linux_arm (ARM64)..."

            # Clear previous failed installs
            rm -rf "$PUPPETEER_CACHE"
            mkdir -p "$PUPPETEER_CACHE"

            # Capture the install output which includes the path
            INSTALL_OUTPUT=$(npx @puppeteer/browsers install "${BROWSER}@latest" --platform linux_arm --path "$PUPPETEER_CACHE" 2>&1) || true
            echo "$INSTALL_OUTPUT"

            # Extract path from output (format: browser@VERSION /path/to/binary)
            PUPPETEER_CHROME=$(echo "$INSTALL_OUTPUT" | grep -oE '/[^ ]+$' | grep -v '^$' | head -1)

            # Fallback to find if extraction failed
            if [ -z "$PUPPETEER_CHROME" ] || [ ! -f "$PUPPETEER_CHROME" ]; then
                echo "Extracting path from output failed, searching directory..."
                find "$PUPPETEER_CACHE" -type f -executable 2>/dev/null || true
                PUPPETEER_CHROME=$(find "$PUPPETEER_CACHE" \( -name "chrome" -o -name "chromium" -o -name "chrome-headless-shell" \) -type f -executable 2>/dev/null | head -1)
            fi

            if [ -n "$PUPPETEER_CHROME" ] && [ -x "$PUPPETEER_CHROME" ]; then
                echo "Found $BROWSER at: $PUPPETEER_CHROME"

                # Check if it's actually ARM64
                FILE_TYPE=$(file "$PUPPETEER_CHROME" 2>/dev/null || echo "")
                echo "Binary type: $FILE_TYPE"

                if echo "$FILE_TYPE" | grep -qE "aarch64|ARM"; then
                    echo "SUCCESS: $BROWSER is ARM64!"
                    break
                else
                    echo "WARNING: $BROWSER is NOT ARM64, trying next..."
                    PUPPETEER_CHROME=""
                fi
            else
                echo "$BROWSER installation failed or binary not found"
            fi
        done

        echo ""
        echo "Final Chrome path: $PUPPETEER_CHROME"

        if [ -n "$PUPPETEER_CHROME" ] && [ -x "$PUPPETEER_CHROME" ]; then
            # Create a symlink to our expected location
            ln -sf "$PUPPETEER_CHROME" "$CHROME_PATH"
            echo "Chrome installed via Puppeteer at $PUPPETEER_CHROME"
            echo "Symlinked to $CHROME_PATH"

            # Verify the installed binary actually works
            echo "Verifying Chrome binary works..."
            if verify_binary "$PUPPETEER_CHROME"; then
                echo "Chrome binary verification PASSED"
            else
                echo "WARNING: Chrome binary verification FAILED!"
                echo "The binary may be wrong architecture. Checking file type..."
                file "$PUPPETEER_CHROME" || true
            fi
        fi

        # If Puppeteer failed, try Playwright which has proper ARM64 support
        if [ -z "$PUPPETEER_CHROME" ] || ! verify_binary "$CHROME_PATH" 2>/dev/null; then
            echo ""
            echo "Puppeteer browsers don't have ARM64 Linux support."
            echo "Trying Playwright which has ARM64 Chromium builds..."

            PLAYWRIGHT_CACHE="$BIN_DIR/.cache/playwright"
            mkdir -p "$PLAYWRIGHT_CACHE"

            # Try to install system dependencies for Chromium
            echo "Attempting to install Chromium system dependencies..."
            apt-get update -qq 2>/dev/null || sudo apt-get update -qq 2>/dev/null || true
            apt-get install -y --no-install-recommends \
                libglib2.0-0 libdbus-1-3 libatk1.0-0 libatk-bridge2.0-0 \
                libcups2 libxkbcommon0 libatspi2.0-0 libxcomposite1 \
                libxdamage1 libxfixes3 libxrandr2 libgbm1 libpango-1.0-0 \
                libasound2 libnss3 libnspr4 libdrm2 libxcb1 2>/dev/null \
            || sudo apt-get install -y --no-install-recommends \
                libglib2.0-0 libdbus-1-3 libatk1.0-0 libatk-bridge2.0-0 \
                libcups2 libxkbcommon0 libatspi2.0-0 libxcomposite1 \
                libxdamage1 libxfixes3 libxrandr2 libgbm1 libpango-1.0-0 \
                libasound2 libnss3 libnspr4 libdrm2 libxcb1 2>/dev/null \
            || echo "Could not install system dependencies (no root access)"

            # Install Playwright's Chromium (has ARM64 Linux support)
            export PLAYWRIGHT_BROWSERS_PATH="$PLAYWRIGHT_CACHE"
            npx playwright install chromium 2>&1 || true

            # Find the Playwright Chromium binary - prefer headless-shell (fewer deps)
            echo "Looking for Playwright binaries..."
            find "$PLAYWRIGHT_CACHE" -type f -executable -name "*chrome*" 2>/dev/null | head -20 || true

            # Try headless shell first (fewer dependencies)
            PLAYWRIGHT_CHROME=$(find "$PLAYWRIGHT_CACHE" -path "*headless*" -name "chrome" -type f -executable 2>/dev/null | head -1)

            # Fall back to regular chrome
            if [ -z "$PLAYWRIGHT_CHROME" ]; then
                PLAYWRIGHT_CHROME=$(find "$PLAYWRIGHT_CACHE" -name "chrome" -type f -executable 2>/dev/null | head -1)
            fi

            if [ -n "$PLAYWRIGHT_CHROME" ] && [ -x "$PLAYWRIGHT_CHROME" ]; then
                echo "Found Playwright Chromium at: $PLAYWRIGHT_CHROME"
                FILE_TYPE=$(file "$PLAYWRIGHT_CHROME" 2>/dev/null || echo "")
                echo "Binary type: $FILE_TYPE"

                if echo "$FILE_TYPE" | grep -qE "aarch64|ARM"; then
                    echo "SUCCESS: Playwright Chromium is ARM64!"
                    ln -sf "$PLAYWRIGHT_CHROME" "$CHROME_PATH"
                    echo "Symlinked to $CHROME_PATH"
                else
                    echo "WARNING: Playwright Chromium is NOT ARM64"
                fi
            else
                echo "Playwright Chromium installation failed or not found"
                find "$PLAYWRIGHT_CACHE" -type f -executable 2>/dev/null | head -20 || true
            fi
        fi

        # Final fallback to system Chromium
        if ! verify_binary "$CHROME_PATH" 2>/dev/null; then
            echo ""
            echo "Falling back to system Chromium if available..."
            SYSTEM_CHROME=$(which chromium-browser 2>/dev/null || which chromium 2>/dev/null || echo "")
            if [ -n "$SYSTEM_CHROME" ]; then
                ln -sf "$SYSTEM_CHROME" "$CHROME_PATH"
                echo "Using system Chromium: $SYSTEM_CHROME"
            else
                echo "ERROR: No Chrome/Chromium installation found for ARM64!"
                echo "PDF generation will not work without Chrome."
                # Don't exit 1 - let the app continue, just without PDF support
            fi
        fi
    fi
else
    echo "Unsupported architecture: $ARCH"
    exit 1
fi

echo ""
echo "Chrome installation complete!"
echo "Path: $CHROME_PATH"

# Install puppeteer for Browsershot to use
# Browsershot requires the full puppeteer package (not puppeteer-core)
PUPPETEER_DIR="$BIN_DIR/puppeteer"
if [ ! -d "$PUPPETEER_DIR/node_modules/puppeteer" ]; then
    echo ""
    echo "Installing puppeteer for Browsershot..."
    mkdir -p "$PUPPETEER_DIR"
    cd "$PUPPETEER_DIR"

    # Create minimal package.json
    echo '{"name":"puppeteer-runtime","private":true}' > package.json

    # Skip Chromium download since we already have Chrome installed
    export PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true

    # Install full puppeteer (Browsershot requires puppeteer, not puppeteer-core)
    npm install puppeteer --save --silent 2>/dev/null || npm install puppeteer --save

    echo "Puppeteer installed at $PUPPETEER_DIR"
else
    echo "Puppeteer already installed at $PUPPETEER_DIR"
fi

echo ""
echo "Puppeteer path: $PUPPETEER_DIR/node_modules"
