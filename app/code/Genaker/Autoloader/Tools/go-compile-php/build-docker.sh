#!/bin/bash
# Build Docker image for go-compile-php

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
IMAGE_NAME="genaker/go-compile-php"
IMAGE_TAG="latest"

echo "Building Docker image: ${IMAGE_NAME}:${IMAGE_TAG}"

cd "$SCRIPT_DIR"

docker build -t "${IMAGE_NAME}:${IMAGE_TAG}" .

echo ""
echo "✓ Docker image built successfully!"
echo ""
echo "To use the compiler:"
echo "  docker run --rm -v \$(pwd):/workspace -w /workspace ${IMAGE_NAME}:${IMAGE_TAG} input.php output.php"
echo ""
echo "Or use the Magento command:"
echo "  php bin/magento genaker:autoloader:compile-go --vendor"
