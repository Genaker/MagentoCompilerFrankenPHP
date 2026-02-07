#!/bin/bash

# Test runner script for GoGentoServerGo
# This script runs tests with proper warden access

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo "=================================================================================="
echo "RUNNING GOGENTOSERVERGO TESTS"
echo "=================================================================================="
echo ""

# Check if running in Docker or on host
if [ -f /.dockerenv ] || [ -n "$DOCKER_CONTAINER" ]; then
    echo "Running tests in Docker container..."
    # Mount warden binary and run tests
    docker run --rm \
        -v "$SCRIPT_DIR:/workspace" \
        -w /workspace \
        -v /opt/warden:/opt/warden:ro \
        --network host \
        -e PATH="/opt/warden/bin:$PATH" \
        golang:1.21-alpine \
        sh -c "go test -v $@"
else
    echo "Running tests on host system..."
    # Run tests directly on host (warden should be available)
    go test -v "$@"
fi

echo ""
echo "=================================================================================="
echo "TESTS COMPLETE"
echo "=================================================================================="
