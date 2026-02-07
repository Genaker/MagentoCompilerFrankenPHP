#!/bin/bash

# Magento FrankenPHP Docker wrapper
# Uses FrankenPHP Docker image with warden volume mounts

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Default to 4 levels up from script directory (app/code/Genaker/Autoloader/Tools/franken-php-go -> Magento root)
DEFAULT_MAGENTO_ROOT="$(cd "$SCRIPT_DIR/../../../../" && pwd)"
MAGENTO_ROOT="${1:-$DEFAULT_MAGENTO_ROOT}"
PORT="${2:-8080}"
WORKERS="${3:-4}"

echo "=================================================================================="
echo "MAGENTO FRANKENPHP DOCKER WRAPPER"
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

# Create Caddyfile for FrankenPHP configuration
CADDYFILE="${SCRIPT_DIR}/Caddyfile"
cat > "$CADDYFILE" << CADDYEOF
{
    auto_https off
    admin localhost:2019
}

:${PORT} {
    root * /app/public/pub
    
    # Worker mode configuration for Magento
    php_server {
        worker /app/config/magento_worker.php ${WORKERS}
    }
    
    # Static files - serve directly
    @static {
        path *.css *.js *.jpg *.jpeg *.png *.gif *.svg *.ico *.woff *.woff2 *.ttf *.eot *.pdf *.zip *.tar *.gz
    }
    handle @static {
        file_server
    }
    
    # All other requests go to Magento (via worker)
    handle {
        php_server
    }
}
CADDYEOF

echo "Created Caddyfile: $CADDYFILE"
echo ""

# Stop existing container if running
docker stop magento-frankenphp 2>/dev/null || true
docker rm magento-frankenphp 2>/dev/null || true

# Run FrankenPHP Docker container
echo "Starting FrankenPHP Docker container..."
echo ""

docker run -d \
    --name magento-frankenphp \
    -p "${PORT}:${PORT}" \
    -v "${MAGENTO_ROOT}:/app/public" \
    -v "${SCRIPT_DIR}:/app/config" \
    -v "${CADDYFILE}:/etc/frankenphp/Caddyfile:ro" \
    -e SERVER_NAME="localhost" \
    dunglas/frankenphp

echo ""
echo "FrankenPHP started!"
echo "Access Magento at: http://localhost:${PORT}"
echo ""
echo "To stop: docker stop magento-frankenphp"
echo "To view logs: docker logs -f magento-frankenphp"
