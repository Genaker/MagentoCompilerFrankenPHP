#!/bin/bash
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Prioritize system libraries for core libs (libc, libm, etc.) to avoid GLIBC version conflicts
# Then use local lib directory for container-specific libraries (libcrypt, libedit, etc.)
# This ensures compatibility with host system while using container-specific libraries when needed
export LD_LIBRARY_PATH="/lib/x86_64-linux-gnu:/usr/lib/x86_64-linux-gnu:/lib64:/usr/lib64:/usr/lib:/lib:${SCRIPT_DIR}/lib:${LD_LIBRARY_PATH}"
exec "${SCRIPT_DIR}/php" "$@"
