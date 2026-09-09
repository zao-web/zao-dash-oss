#!/bin/bash
# Install wkhtmltopdf (static binary) into ~/bin/wkhtmltopdf for
# ARM64 (Laravel Cloud / Graviton). Google's Chrome for Testing
# only ships x86_64, so we fall back to wkhtmltopdf for PDF
# rendering on ARM hosts.
set -e

BIN_DIR="$HOME/bin"
INSTALL_DIR="$BIN_DIR/wkhtmltopdf-pkg"
WKHTML="$BIN_DIR/wkhtmltopdf"

verify_binary() {
    local binary="$1"
    if [ ! -x "$binary" ]; then
        return 1
    fi
    local output
    output=$("$binary" --version 2>&1) || return 1
    echo "wkhtmltopdf check: $output"
    return 0
}

# Skip if already installed and working.
if [ -x "$WKHTML" ] && verify_binary "$WKHTML"; then
    echo "wkhtmltopdf already installed and working."
    exit 0
fi

mkdir -p "$BIN_DIR" "$INSTALL_DIR"

ARCH=$(uname -m)
echo "Architecture: $ARCH"

# wkhtmltopdf 0.12.6.1-3 packages from the official packaging repo.
case "$ARCH" in
    aarch64|arm64)
        # ARM64 .deb (Debian 12 / Ubuntu 22.04 compatible)
        URL="https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6.1-3/wkhtmltox_0.12.6.1-3.bookworm_arm64.deb"
        ;;
    x86_64|amd64)
        URL="https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6.1-3/wkhtmltox_0.12.6.1-3.bookworm_amd64.deb"
        ;;
    *)
        echo "Unsupported architecture: $ARCH"
        exit 1
        ;;
esac

echo "Downloading $URL ..."
(
    cd "$INSTALL_DIR"
    curl --silent --show-error --fail -L -o pkg.deb "$URL"
    # .deb is an ar archive of control + data tarballs. Extract data.tar.xz
    # (or .gz) to get the actual binary tree. No dpkg/sudo needed.
    ar x pkg.deb
    if [ -f data.tar.xz ]; then
        tar -xJf data.tar.xz
    elif [ -f data.tar.gz ]; then
        tar -xzf data.tar.gz
    else
        echo "Unexpected .deb structure"; ls -la; exit 1
    fi
    if [ -x "./usr/local/bin/wkhtmltopdf" ]; then
        cp ./usr/local/bin/wkhtmltopdf "$WKHTML"
    elif [ -x "./usr/bin/wkhtmltopdf" ]; then
        cp ./usr/bin/wkhtmltopdf "$WKHTML"
    else
        echo "Could not locate wkhtmltopdf inside .deb"; find . -name wkhtmltopdf; exit 1
    fi
    chmod +x "$WKHTML"
    rm -rf pkg.deb usr etc data.tar.* control.tar.* debian-binary
)

if ! verify_binary "$WKHTML"; then
    echo "ERROR: wkhtmltopdf installed but failed --version check"
    exit 1
fi

echo "wkhtmltopdf installed at $WKHTML"
