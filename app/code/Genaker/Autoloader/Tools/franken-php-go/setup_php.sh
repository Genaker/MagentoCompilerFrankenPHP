#!/bin/bash

# Setup PHP binaries from Warden
# Copies PHP and required libraries from Warden container to local directory

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_DIR="$SCRIPT_DIR/php-bin"

echo "=================================================================================="
echo "SETTING UP PHP BINARIES FROM WARDEN"
echo "=================================================================================="
echo ""

# Find Warden PHP-FPM container
WARDEN_CONTAINER=$(docker ps --format '{{.Names}}' | grep -iE "php|fpm" | head -1)

if [ -z "$WARDEN_CONTAINER" ]; then
    # Try to get container name from warden command
    WARDEN_CONTAINER=$(warden env exec php-fpm hostname 2>/dev/null | xargs -I {} docker ps --filter "name={}" --format '{{.Names}}' | head -1)
fi

if [ -z "$WARDEN_CONTAINER" ]; then
    echo "ERROR: Could not find Warden PHP-FPM container"
    echo "Available containers:"
    docker ps --format '{{.Names}}' | head -5
    echo ""
    echo "Please ensure Warden is running: warden start"
    exit 1
fi

echo "Found Warden container: $WARDEN_CONTAINER"
echo ""

# Create PHP binaries directory
mkdir -p "$PHP_DIR"
echo "PHP binaries directory: $PHP_DIR"
echo ""

# Find PHP binary in container
PHP_BIN_PATH=$(docker exec $WARDEN_CONTAINER which php 2>/dev/null | head -1 || echo "/usr/bin/php")
echo "PHP binary in container: $PHP_BIN_PATH"

# Copy PHP binary
echo "Copying PHP binary..."
docker cp "${WARDEN_CONTAINER}:${PHP_BIN_PATH}" "$PHP_DIR/php" 2>/dev/null || {
    echo "Failed to copy PHP binary, trying alternative path..."
    docker cp "${WARDEN_CONTAINER}:/usr/local/bin/php" "$PHP_DIR/php" 2>/dev/null || {
        echo "ERROR: Could not copy PHP binary"
        exit 1
    }
}
chmod +x "$PHP_DIR/php"
echo "✓ PHP binary copied"

# Find PHP libraries
echo ""
echo "Finding PHP libraries..."
PHP_LIB_PATH=$(docker exec $WARDEN_CONTAINER php -i 2>/dev/null | grep "extension_dir" | head -1 | awk '{print $3}' || echo "/usr/lib/php")
echo "PHP extension directory: $PHP_LIB_PATH"

# Copy PHP configuration
echo ""
echo "Copying PHP configuration..."
docker exec $WARDEN_CONTAINER php --ini 2>/dev/null | grep "Loaded Configuration File" | awk '{print $4}' | while read php_ini; do
    if [ ! -z "$php_ini" ]; then
        echo "Found php.ini: $php_ini"
        mkdir -p "$PHP_DIR/conf"
        docker cp "${WARDEN_CONTAINER}:${php_ini}" "$PHP_DIR/conf/php.ini" 2>/dev/null || true
    fi
done

# Copy common PHP extensions if they exist
echo ""
echo "Copying PHP extensions..."
mkdir -p "$PHP_DIR/extensions"
for ext in opcache.so curl.so mysqli.so pdo_mysql.so; do
    docker exec $WARDEN_CONTAINER find /usr -name "$ext" 2>/dev/null | head -1 | while read ext_path; do
        if [ ! -z "$ext_path" ]; then
            echo "Copying $ext..."
            docker cp "${WARDEN_CONTAINER}:${ext_path}" "$PHP_DIR/extensions/" 2>/dev/null || true
        fi
    done
done

# Copy required libraries
echo ""
echo "Copying PHP shared libraries..."
mkdir -p "$PHP_DIR/lib"

# Get list of required libraries
docker exec $WARDEN_CONTAINER ldd /usr/bin/php 2>/dev/null | grep "=>" | awk '{print $3}' | grep "^/" | while read lib_path; do
    if [ ! -z "$lib_path" ]; then
        lib_name=$(basename "$lib_path")
        if [ ! -f "$PHP_DIR/lib/$lib_name" ]; then
            echo "Copying $lib_name..."
            # Try to copy the library
            docker cp "${WARDEN_CONTAINER}:${lib_path}" "$PHP_DIR/lib/$lib_name" 2>/dev/null || {
                # If direct copy fails, try to find it
                docker exec $WARDEN_CONTAINER find /lib /lib64 /usr/lib /usr/lib64 -name "$lib_name" 2>/dev/null | head -1 | while read found_lib; do
                    if [ ! -z "$found_lib" ]; then
                        docker cp "${WARDEN_CONTAINER}:${found_lib}" "$PHP_DIR/lib/$lib_name" 2>/dev/null || true
                    fi
                done
            }
        fi
    fi
done

# Specifically copy libcrypt.so.2 if it exists (required by PHP binary)
echo ""
echo "Copying libcrypt.so.2 (required for PHP)..."
# Find the actual library file (not symlink)
docker exec $WARDEN_CONTAINER find /lib* /usr/lib* -name "libcrypt.so.2.0.0" -o -name "libcrypt.so.2" 2>/dev/null | while read crypt_lib; do
    if [ ! -z "$crypt_lib" ]; then
        # Check if it's a real file (not symlink)
        if docker exec $WARDEN_CONTAINER test -f "$crypt_lib" 2>/dev/null; then
            crypt_name=$(basename "$crypt_lib")
            echo "Found libcrypt file: $crypt_lib"
            docker cp "${WARDEN_CONTAINER}:${crypt_lib}" "$PHP_DIR/lib/$crypt_name" 2>/dev/null && {
                echo "✓ Copied $crypt_name"
                # Create symlink libcrypt.so.2 -> libcrypt.so.2.0.0
                if [[ "$crypt_name" == libcrypt.so.2.0.0 ]]; then
                    cd "$PHP_DIR/lib" && ln -sf "$crypt_name" "libcrypt.so.2" 2>/dev/null && echo "✓ Created symlink libcrypt.so.2" || true
                fi
                break
            } || echo "⚠ Failed to copy libcrypt"
        fi
    fi
done

# Also copy common library directories
echo "Copying common library directories..."
for lib_dir in /lib64 /usr/lib64; do
    dir_name=$(basename "$lib_dir")
    if docker exec $WARDEN_CONTAINER test -d "$lib_dir" 2>/dev/null; then
        echo "Copying $lib_dir..."
        docker cp "${WARDEN_CONTAINER}:${lib_dir}/." "$PHP_DIR/lib/" 2>/dev/null || true
    fi
done

# Create wrapper script that sets LD_LIBRARY_PATH
echo ""
echo "Creating PHP wrapper script..."
cat > "$PHP_DIR/php-wrapper.sh" << 'EOFW'
#!/bin/bash
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export LD_LIBRARY_PATH="${SCRIPT_DIR}/lib:/usr/lib64:/lib64:/usr/lib:/lib:${LD_LIBRARY_PATH}"
exec "${SCRIPT_DIR}/php" "$@"
EOFW
chmod +x "$PHP_DIR/php-wrapper.sh"

# Test PHP binary
echo ""
echo "Testing PHP binary..."
if "$PHP_DIR/php" -v > /dev/null 2>&1; then
    echo "✓ PHP binary works!"
    "$PHP_DIR/php" -v | head -1
else
    echo "⚠ PHP binary may need additional libraries"
    echo "You may need to copy additional .so files from the container"
fi

echo ""
echo "=================================================================================="
echo "SETUP COMPLETE"
echo "=================================================================================="
echo ""
echo "PHP binary location: $PHP_DIR/php"
echo "PHP wrapper script: $PHP_DIR/php-wrapper.sh"
echo ""
echo "Usage:"
echo "  ./GoGentoServerGo -php=\"$PHP_DIR/php-wrapper.sh\" -docroot=/path/to/magento"
echo ""
