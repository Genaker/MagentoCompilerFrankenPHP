# GoGentoServerGo - PHP Runtime in Go

A Go-based PHP runtime server that embeds PHP execution, providing Worker mode with persistent variable caching for Magento and other PHP applications.

## Features

- **Worker Mode Only**: Application boots once and stays in memory (framework stays "awake")
- **Persistent Variable Caching**: PHP variables cached between requests without losing state
- **No FastCGI**: Direct PHP execution without handshake overhead
- **Multi-Worker Support**: Multiple worker processes for concurrent request handling

## Worker Mode

- Application boots once and stays in memory
- Framework stays "awake" between requests
- Persistent variable caching
- Higher memory usage but much faster response times
- Multiple workers for concurrent processing

## Building

```bash
cd app/code/Genaker/Autoloader/Tools/franken-php-go
go build -o GoGentoServerGo main.go variable_cache.go
```

Or using Docker:
```bash
docker run --rm -v $(pwd):/workspace -w /workspace golang:1.21-alpine go build -o GoGentoServerGo .
```

## Usage

```bash
./GoGentoServerGo -port=8080 -workers=4 -docroot=/path/to/magento
```

## Options

- `-port`: HTTP server port (default: `8080`)
- `-workers`: Number of worker processes (default: `4`)
- `-php`: Path to PHP binary (default: `php`)
- `-docroot`: Document root directory (default: `.`)

## Architecture

### Worker Mode Flow
1. Workers start and bootstrap application once
2. Request arrives
3. Route to available worker (round-robin)
4. Worker processes request using cached state
5. Return response
6. Worker stays alive for next request

## Variable Caching

Variables are cached in worker mode using the `ApplicationState` structure:
- Variables persist between requests
- Thread-safe access with mutex locks
- Automatic cleanup of stale variables
- Accessible via `FRANKEN_CACHE_*` environment variables

## Future Enhancements

- [ ] Replace exec with embedded PHP library (libphp)
- [ ] Add FastCGI support for compatibility
- [ ] Implement variable expiration/TTL
- [ ] Add metrics and monitoring
- [ ] Support for PHP extensions
- [ ] Hot reloading in development mode

## Running FrankenPHP with Magento

This project includes wrapper scripts to run FrankenPHP with Magento using Worker Mode for optimal performance.

### Option 1: Using Warden (Recommended)

**Step 1: Install FrankenPHP in Warden container** (one-time setup):
```bash
warden env exec php-fpm bash -c "curl https://frankenphp.dev/install.sh | sh"
```

**Step 2: Run the wrapper script**:
```bash
cd app/code/Genaker/Autoloader/Tools/franken-php-go
./magento_frankenphp.sh
```

Or with custom parameters:
```bash
./magento_frankenphp.sh /path/to/magento 8080 4
```

Parameters:
- `$1`: Magento root directory (default: auto-detected from script location)
- `$2`: Port number (default: `8080`)
- `$3`: Number of workers (default: `4`)

**What it does:**
- Automatically detects Warden environment
- Runs FrankenPHP inside Warden PHP-FPM container
- Uses Magento worker script (`magento_worker.php`) for persistent state
- Maps paths correctly (`/var/www/html` inside container)

### Option 2: Using Docker (No Installation Needed)

If you prefer Docker or don't want to install FrankenPHP in Warden:

```bash
cd app/code/Genaker/Autoloader/Tools/franken-php-go
./magento_frankenphp_docker.sh
```

Or with custom parameters:
```bash
./magento_frankenphp_docker.sh /path/to/magento 8080 4
```

**What it does:**
- Uses official FrankenPHP Docker image (`dunglas/frankenphp`)
- Mounts Magento directory and worker script
- Generates Caddyfile configuration automatically
- No installation required - everything runs in Docker

### How Worker Mode Works

1. **Bootstrap Once**: Magento boots once when the worker starts
2. **Stay in Memory**: Application state persists between requests
3. **Handle Requests**: Each request reuses the bootstrapped application
4. **No Re-bootstrap**: Framework stays "awake" - much faster response times

The `magento_worker.php` script:
- Boots Magento once using `Bootstrap::create()`
- Enters a loop using `frankenphp_handle_request()`
- Processes each request without re-bootstrapping
- Maintains ObjectManager and application state in memory

### Troubleshooting

**Port already in use:**
```bash
# Use a different port
./magento_frankenphp.sh /path/to/magento 8081 4

# Or stop the existing server
lsof -ti :8080 | xargs kill -9
```

**FrankenPHP not found in Warden:**
```bash
# Install it manually
warden env exec php-fpm bash -c "curl https://frankenphp.dev/install.sh | sh"
```

**View logs:**
```bash
# For Docker wrapper
docker logs -f magento-frankenphp

# For Warden wrapper
# Logs are output directly to console
```

## Running with Nginx (Without Changing Nginx Config)

### Option 1: Replace pub/index.php (Drop-in Replacement)

Use the FrankenPHP-compatible `index_frankenphp.php` as a drop-in replacement:

```bash
cd /path/to/magento/pub
cp index.php index.php.backup
cp app/code/Genaker/Autoloader/Tools/franken-php-go/index_frankenphp.php index.php
```

**How it works:**
- Detects if running in FrankenPHP worker mode automatically
- If FrankenPHP worker mode: Uses persistent state (fast)
- If regular PHP-FPM: Works exactly like standard index.php (backward compatible)
- No Nginx config changes needed - works with existing setup

**To use with FrankenPHP:**
```bash
# Start FrankenPHP pointing to pub/index.php
frankenphp php-server \
    --worker /path/to/magento/pub/index.php,4 \
    --listen :9000 \
    --root /path/to/magento/pub
```

### Option 2: Use Separate Entry Point

Keep original `index.php` and use `index_frankenphp.php` as a separate entry point:

```bash
# Start FrankenPHP with separate entry point
frankenphp php-server \
    --worker /path/to/magento/pub/index_frankenphp.php,4 \
    --listen :9000 \
    --root /path/to/magento/pub
```

## Running with Nginx (With Nginx Config)

### Option 1: Proxy to FrankenPHP

Configure Nginx to proxy requests to FrankenPHP:

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/magento/pub;
    
    index index.php;
    
    # Proxy PHP requests to FrankenPHP
    location ~ \.php$ {
        proxy_pass http://127.0.0.1:9000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
    
    # Static files served directly by Nginx
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|woff|woff2|ttf|svg)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
    
    # All other requests go to FrankenPHP
    location / {
        try_files $uri $uri/ @frankenphp;
    }
    
    location @frankenphp {
        proxy_pass http://127.0.0.1:9000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Then start FrankenPHP:
```bash
frankenphp php-server \
    --worker /path/to/magento/pub/index.php,4 \
    --listen 127.0.0.1:9000 \
    --root /path/to/magento/pub
```

### Option 2: FastCGI Protocol (if FrankenPHP supports it)

If FrankenPHP supports FastCGI, configure Nginx to use it:

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/magento/pub;
    
    index index.php;
    
    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

## Notes

Currently uses `exec` to call PHP binary. For production, this should be replaced with embedded PHP (libphp) for better performance and integration.
