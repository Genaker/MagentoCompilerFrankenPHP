# Genaker Autoloader Module

A Magento 2 module that optimizes Composer autoloading by combining all vendor PHP files into a single file and registering a custom autoloader that bypasses Composer's default autoloader for better performance.

## Features

- **Combined File Approach**: Combines all vendor classes into a single PHP file (219+ MB)
- **App Code Autoloader**: Combines all app/code classes into a single PHP file (2.5+ MB)
- **Custom Autoloader**: Registers autoloader before Composer's default autoloader
- **Performance Optimization**: Reduces file system lookups and improves class loading speed
- **Go Compilation Tool**: Optional Go-based PHP compiler for further optimization
- **Enable/Disable Support**: Can be enabled/disabled via configuration or environment variables
- **Fallback Support**: Falls back to classmap if combined file doesn't exist

## Installation

1. **Copy module files** to `app/code/Genaker/Autoloader/`

2. **Enable the module**:
```bash
php bin/magento module:enable Genaker_Autoloader
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

3. **Generate combined files** (optional but recommended):
```bash
# Generate vendor classes combined file
php bin/magento genaker:autoloader:combine-files

# Generate app/code classes combined file
php bin/magento genaker:autoloader:combine-app-code
```

4. **Build and install Go compilation tool** (optional):
```bash
# Build the Go tool
cd app/code/Genaker/Autoloader/Tools/go-compile-php
go build -o go-compile-php main.go

# Install to system (choose one):
# Option 1: Install to /usr/local/bin (requires sudo)
sudo cp go-compile-php /usr/local/bin/

# Option 2: Install to user's Go bin directory
mkdir -p ~/go/bin
cp go-compile-php ~/go/bin/
# Add ~/go/bin to your PATH if not already added

# Or use Makefile:
make build        # Build only
make install-user # Build and install to ~/go/bin
```

5. **Compile files using Go tool** (optional):
```bash
# Compile vendor file
php bin/magento genaker:autoloader:compile-go --vendor

# Compile app/code file
php bin/magento genaker:autoloader:compile-go --app-code

# Compile both files
php bin/magento genaker:autoloader:compile-go --both

# Use custom Go tool path
php bin/magento genaker:autoloader:compile-go --tool=/path/to/go-compile-php --vendor

# Custom input/output
php bin/magento genaker:autoloader:compile-go --input=var/combined_vendor_classes.php --output=var/compiled.php
```

## Configuration

### Enable/Disable via env.php

Add the following configuration to `app/etc/env.php`:

```php
<?php
return [
    // ... existing configuration ...
    
    'genaker_autoloader' => [
        'enabled' => true,  // Set to false to disable
    ],
];
```

**Example - Disable autoloader:**
```php
'genaker_autoloader' => [
    'enabled' => false,
],
```

### Enable/Disable via Environment Variable

Set the environment variable `GENAKER_AUTOLOADER_ENABLED`:

**Enable (default):**
```bash
export GENAKER_AUTOLOADER_ENABLED=1
# or
export GENAKER_AUTOLOADER_ENABLED=true
```

**Disable:**
```bash
export GENAKER_AUTOLOADER_ENABLED=0
# or
export GENAKER_AUTOLOADER_ENABLED=false
```

**For PHP-FPM (in php-fpm.conf or pool config):**
```ini
env[GENAKER_AUTOLOADER_ENABLED] = 1
```

**For Apache (.htaccess or VirtualHost):**
```apache
SetEnv GENAKER_AUTOLOADER_ENABLED 1
```

**For Nginx (in server block):**
```nginx
fastcgi_param GENAKER_AUTOLOADER_ENABLED 1;
```

**For Docker:**
```yaml
environment:
  - GENAKER_AUTOLOADER_ENABLED=1
```

**For Cloud Platforms (e.g., Magento Cloud):**
Add to `.magento.env.yaml`:
```yaml
variables:
  env:
    GENAKER_AUTOLOADER_ENABLED: '1'
```

### Configuration Priority

The module checks configuration in this order (first match wins):

1. **Environment Variable** (`GENAKER_AUTOLOADER_ENABLED`)
2. **env.php** (`genaker_autoloader.enabled`)
3. **Default**: Enabled (`true`)

## Usage

### Generate Combined Files

Generate or regenerate the combined files:

**Vendor classes:**
```bash
php bin/magento genaker:autoloader:combine-files
```

**App code classes:**
```bash
php bin/magento genaker:autoloader:combine-app-code
```

**Custom output paths:**
```bash
php bin/magento genaker:autoloader:combine-files --output=var/custom_vendor.php
php bin/magento genaker:autoloader:combine-app-code --output=var/custom_app_code.php
```

### Compile Files with Go Tool

After generating combined files, you can optionally compile them using a Go-based tool for further optimization:

**Compile vendor file:**
```bash
php bin/magento genaker:autoloader:compile-go --vendor
```

**Compile app/code file:**
```bash
php bin/magento genaker:autoloader:compile-go --app-code
```

**Compile both files:**
```bash
php bin/magento genaker:autoloader:compile-go --both
```

**Use custom Go tool or Docker image:**
```bash
# Custom Docker image
php bin/magento genaker:autoloader:compile-go --tool=my-custom/go-compile-php:latest --vendor

# Custom local binary
php bin/magento genaker:autoloader:compile-go --tool=/path/to/your-go-tool --no-docker --vendor
```

**Build Docker image (first time only):**
```bash
cd app/code/Genaker/Autoloader/Tools/go-compile-php
docker build -t genaker/go-compile-php:latest .
```

The command will automatically use Docker if available, or fall back to local binary.

**Custom input/output:**
```bash
php bin/magento genaker:autoloader:compile-go --input=var/combined_vendor_classes.php --output=var/compiled.php
```

**Note:** The command will automatically detect your Go tool in common locations:
- System PATH
- `/usr/local/bin/`
- `/usr/bin/`
- `$HOME/go/bin/`
- `$GOPATH/bin/`

The included `go-compile-php` tool performs the following optimizations:
- Removes PHP comments (single-line and multi-line)
- Optimizes whitespace
- Removes empty lines
- Preserves string content
- Maintains code structure

**Prerequisites for Go tool:**
- Go 1.16 or higher installed
- Built binary (`go-compile-php`) available in PATH or specified via `--tool` option

See `Tools/go-compile-php/README.md` for detailed build and usage instructions.

### Verify Configuration

Check if autoloader is enabled:

```bash
# Check module status
php bin/magento module:status Genaker_Autoloader

# Check if combined files exist
ls -lh var/combined_vendor_classes.php
ls -lh var/combined_app_code_classes.php
```

## How It Works

1. **Module Registration**: When Magento loads, `registration.php` is executed
2. **Configuration Check**: Module checks environment variable and `env.php` for enable/disable setting
3. **App Code Combined File Loading**: If enabled and `var/combined_app_code_classes.php` exists, it's loaded first
4. **Vendor Combined File Loading**: If enabled and `var/combined_vendor_classes.php` exists, it's loaded next
5. **Autoloader Registration**: Custom autoloader is registered at position 0 (before Composer)
6. **Class Loading**: When classes are needed, custom autoloader checks first, bypassing Composer for preloaded classes

## Performance

- **Vendor Combined File**: ~0.0897 ms per class average
- **App Code Combined File**: ~0.02 ms per class average
- **Individual Class Checks**: ~0.0002 ms per class
- **Autoloader Stack**: Custom autoloader at index 0, Composer at index 1

## Testing

Run integration tests:

```bash
# Run all tests
vendor/bin/phpunit app/code/Genaker/Autoloader/Test/Integration/

# Run specific test suite
vendor/bin/phpunit app/code/Genaker/Autoloader/Test/Integration/AutoloaderBypassTest.php
```

## Troubleshooting

### Autoloader Not Working

1. **Check if module is enabled:**
```bash
php bin/magento module:status Genaker_Autoloader
```

2. **Check configuration:**
```bash
# Check env.php
grep -A 2 "genaker_autoloader" app/etc/env.php

# Check environment variable
echo $GENAKER_AUTOLOADER_ENABLED
```

3. **Regenerate combined files:**
```bash
php bin/magento genaker:autoloader:combine-files
php bin/magento genaker:autoloader:combine-app-code
php bin/magento cache:flush
```

### Classes Not Loading

1. **Verify combined files exist:**
```bash
ls -lh var/combined_vendor_classes.php
ls -lh var/combined_app_code_classes.php
```

2. **Check autoloader stack:**
```php
<?php
require 'app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
print_r(spl_autoload_functions());
```

### Performance Issues

1. **Disable autoloader temporarily** to compare performance:
```php
// In app/etc/env.php
'genaker_autoloader' => [
    'enabled' => false,
],
```

2. **Regenerate combined files** if packages or app/code were updated:
```bash
php bin/magento genaker:autoloader:combine-files
php bin/magento genaker:autoloader:combine-app-code
```

## Environment Variable Limitations

**Note:** Magento's standard configuration system (`config.xml`, `system.xml`) cannot directly read environment variables at the `registration.php` stage because:

1. `registration.php` runs **before** Magento bootstrap
2. Magento's `DeploymentConfig` and `ScopeConfig` are not available yet
3. The autoloader needs to be registered early in the PHP execution

**Solution:** This module reads configuration directly from:
- **Environment variables** via `getenv()` (available early)
- **env.php** via `include` (available early)

This approach ensures the autoloader can be configured before Magento's full bootstrap completes.

## Files

- `registration.php` - Module registration and autoloader setup
- `Model/ComposerAutoloader.php` - Autoloader model class
- `Console/Command/CombineClassMapFiles.php` - CLI command to generate vendor combined file
- `Console/Command/CombineAppCodeFiles.php` - CLI command to generate app/code combined file
- `Test/Integration/` - Integration tests

## License

Copyright (c) 2026 Genaker
