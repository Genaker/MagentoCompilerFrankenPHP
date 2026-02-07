#!/bin/bash

# Magento-specific FrankenPHP wrapper
# Uses FrankenPHP binary with warden for Magento optimization

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Default to 4 levels up from script directory (app/code/Genaker/Autoloader/Tools/franken-php-go -> Magento root)
DEFAULT_MAGENTO_ROOT="$(cd "$SCRIPT_DIR/../../../../" && pwd)"
MAGENTO_ROOT="${1:-$DEFAULT_MAGENTO_ROOT}"
PORT="${2:-8080}"
WORKERS="${3:-4}"

echo "=================================================================================="
echo "MAGENTO FRANKENPHP WRAPPER"
echo "=================================================================================="
echo ""

# Verify Magento structure
MAGENTO_PUB="${MAGENTO_ROOT}/pub"
MAGENTO_INDEX="${MAGENTO_PUB}/index.php"

if [ ! -f "$MAGENTO_INDEX" ]; then
    echo "ERROR: Magento index.php not found at: $MAGENTO_INDEX"
    exit 1
fi

echo "Magento root: $MAGENTO_ROOT"
echo "Magento pub: $MAGENTO_PUB"
echo "Port: $PORT"
echo "Workers: $WORKERS"
echo ""

# Use existing worker script
WORKER_SCRIPT="${SCRIPT_DIR}/magento_worker.php"

if [ ! -f "$WORKER_SCRIPT" ]; then
    echo "ERROR: Worker script not found: $WORKER_SCRIPT"
    exit 1
fi

echo "Using worker script: $WORKER_SCRIPT"
echo ""

# Check if we're in warden environment FIRST
# If Warden is available, we don't need FrankenPHP on the host
if command -v warden &> /dev/null; then
    echo "Warden detected - using warden environment"
    echo ""
    
    # Run FrankenPHP via warden
    # FrankenPHP will use PHP from warden container
    echo "Starting FrankenPHP with Worker Mode via Warden..."
    echo ""
    
    # Set environment variables for Magento
    export MAGENTO_ROOT="$MAGENTO_ROOT"
    export SERVER_NAME="localhost"
    
    # Convert paths for warden container
    # Warden maps host path to /var/www/html
    CONTAINER_MAGENTO_ROOT="/var/www/html"
    CONTAINER_WORKER_SCRIPT="${CONTAINER_MAGENTO_ROOT}/app/code/Genaker/Autoloader/Tools/franken-php-go/magento_worker.php"
    CONTAINER_PUB="${CONTAINER_MAGENTO_ROOT}/pub"
    
    # Run FrankenPHP inside warden container
    echo "Checking for FrankenPHP in warden container..."
    echo "Worker script: $CONTAINER_WORKER_SCRIPT"
    echo "Document root: $CONTAINER_PUB"
    echo ""
    
    # Check if FrankenPHP exists in container
    if ! warden env exec php-fpm bash -c "command -v frankenphp &> /dev/null" 2>/dev/null; then
        echo "ERROR: FrankenPHP not found in warden container"
        echo ""
        echo "To install FrankenPHP in the warden container, run:"
        echo "  warden env exec php-fpm bash -c \"curl https://frankenphp.dev/install.sh | sh\""
        echo ""
        echo "Or use the Docker wrapper instead (no installation needed):"
        echo "  ./magento_frankenphp_docker.sh"
        exit 1
    fi
    
    echo "✓ FrankenPHP found in container"
    echo "Starting FrankenPHP with Worker Mode..."
    echo ""
    echo "Note: If port $PORT is already in use, stop the existing server or use a different port"
    echo ""
    
    warden env exec php-fpm bash -c "
        cd $CONTAINER_PUB && \
        frankenphp php-server \
            --worker $CONTAINER_WORKER_SCRIPT,$WORKERS \
            --listen :$PORT \
            --root $CONTAINER_PUB
    "
else
    # Warden not found - check for FrankenPHP on host
    if ! command -v frankenphp &> /dev/null; then
        echo "ERROR: FrankenPHP not found"
        echo ""
        echo "Install FrankenPHP:"
        echo "  curl https://frankenphp.dev/install.sh | sh"
        echo ""
        echo "Or use Docker wrapper:"
        echo "  ./magento_frankenphp_docker.sh"
        exit 1
    fi
    
    echo "Warden not found - running FrankenPHP directly"
    echo ""
    echo "NOTE: Ensure PHP extensions required by Magento are available"
    echo ""
    
    # Run FrankenPHP directly
    cd "$MAGENTO_PUB"
    frankenphp php-server \
        --worker "$WORKER_SCRIPT,$WORKERS" \
        --listen ":$PORT" \
        --root "$MAGENTO_PUB"
fi
