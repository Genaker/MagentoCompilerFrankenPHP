# go-compile-php

A Go-based PHP compiler/optimizer tool for the Genaker Autoloader module.

## Features

- Removes PHP comments (single-line and multi-line)
- Optimizes whitespace
- Removes empty lines
- Preserves string content
- Maintains code structure

## Building

### Option 1: Using Docker (Recommended)

**Prerequisites:**
- Docker installed

**Build Docker Image:**
```bash
cd app/code/Genaker/Autoloader/Tools/go-compile-php
docker build -t genaker/go-compile-php:latest .
```

Or use the build script:
```bash
./build-docker.sh
```

**Usage with Docker:**
The Magento command will automatically use Docker if available:
```bash
php bin/magento genaker:autoloader:compile-go --vendor
```

**Manual Docker Usage:**
```bash
docker run --rm -v $(pwd):/workspace -w /workspace genaker/go-compile-php:latest input.php output.php
```

### Option 2: Build Native Binary

**Prerequisites:**
- Go 1.16 or higher

**Build Instructions:**
```bash
cd app/code/Genaker/Autoloader/Tools/go-compile-php
go build -o go-compile-php main.go
```

**Install to System PATH:**
```bash
# Install to /usr/local/bin (requires sudo)
sudo cp go-compile-php /usr/local/bin/

# Or install to user's Go bin directory
cp go-compile-php ~/go/bin/
```

**Force Native Binary (skip Docker):**
```bash
php bin/magento genaker:autoloader:compile-go --no-docker --vendor
```

## Usage

### Basic Usage

```bash
# Compile a PHP file
go-compile-php input.php output.php

# Using flags
go-compile-php -input=input.php -output=output.php
go-compile-php -i input.php -o output.php
```

### With Magento Command

```bash
# Compile vendor file
php bin/magento genaker:autoloader:compile-go --vendor

# Compile app/code file
php bin/magento genaker:autoloader:compile-go --app-code

# Compile both
php bin/magento genaker:autoloader:compile-go --both

# Use custom tool path
php bin/magento genaker:autoloader:compile-go --tool=/path/to/go-compile-php --vendor
```

## What It Does

The tool performs the following optimizations:

1. **Comment Removal**: Removes single-line (`//`) and multi-line (`/* */`) comments while preserving strings
2. **Whitespace Optimization**: Reduces multiple spaces/tabs to single spaces
3. **Empty Line Removal**: Removes excessive empty lines (keeps max 1)
4. **Trailing Whitespace**: Removes trailing spaces and tabs from lines

## Output

The tool provides:
- Compiled PHP file
- File size statistics
- Compression ratio

Example output:
```
Successfully compiled: input.php -> output.php
Input size: 1000000 bytes
Output size: 850000 bytes
Compression: 15.00%
```

## Notes

- The tool preserves PHP code structure and functionality
- Strings are protected from optimization
- The compiled output is still valid PHP code
- For best results, use with OPcache enabled
