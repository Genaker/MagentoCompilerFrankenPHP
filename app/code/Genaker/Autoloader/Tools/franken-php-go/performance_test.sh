#!/bin/bash

# Performance Test Script
# Compares GoGentoServerGo vs Regular PHP with OPcache

set -e

PORT_GOGENTO=8080
PORT_PHP=8081
DOCROOT="${1:-$(pwd)/../../../../..}"
NUM_REQUESTS="${2:-100}"
CONCURRENT="${3:-10}"

echo "=================================================================================="
echo "PERFORMANCE COMPARISON TEST"
echo "=================================================================================="
echo ""
echo "Test Configuration:"
echo "  Document Root: $DOCROOT"
echo "  Total Requests: $NUM_REQUESTS"
echo "  Concurrent Requests: $CONCURRENT"
echo ""

# Test URL
TEST_URL="http://localhost"

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to run Apache Bench test
run_ab_test() {
    local port=$1
    local name=$2
    local url="${TEST_URL}:${port}/"
    
    echo -e "${BLUE}Testing ${name}...${NC}"
    echo "URL: $url"
    
    ab -n $NUM_REQUESTS -c $CONCURRENT -q "$url" > /tmp/ab_${name}.txt 2>&1 || true
    
    # Extract key metrics
    local rps=$(grep "Requests per second" /tmp/ab_${name}.txt | awk '{print $4}')
    local time_per_request=$(grep "Time per request.*mean" /tmp/ab_${name}.txt | head -1 | awk '{print $4}')
    local time_total=$(grep "Time taken for tests" /tmp/ab_${name}.txt | awk '{print $5}')
    local failed=$(grep "Failed requests" /tmp/ab_${name}.txt | awk '{print $3}' | head -1)
    
    echo "  Requests per second: ${rps:-N/A}"
    echo "  Time per request: ${time_per_request:-N/A} ms"
    echo "  Total time: ${time_total:-N/A} seconds"
    echo "  Failed requests: ${failed:-0}"
    echo ""
    
    # Return RPS for comparison
    echo "$rps"
}

# Check if GoGentoServerGo exists
if [ ! -f "./GoGentoServerGo" ]; then
    echo -e "${YELLOW}GoGentoServerGo binary not found. Building...${NC}"
    docker run --rm -v $(pwd):/workspace -w /workspace golang:1.21-alpine go build -o GoGentoServerGo . || {
        echo "Failed to build GoGentoServerGo"
        exit 1
    }
fi

# Check if ab (Apache Bench) is installed
if ! command -v ab &> /dev/null; then
    echo "Apache Bench (ab) not found. Please install: sudo apt-get install apache2-utils"
    exit 1
fi

# Start GoGentoServerGo in background
echo -e "${GREEN}Starting GoGentoServerGo server on port $PORT_GOGENTO...${NC}"
./GoGentoServerGo -port=$PORT_GOGENTO -workers=4 -docroot="$DOCROOT" > /tmp/gogento.log 2>&1 &
GOGENTO_PID=$!
sleep 3

# Check if server started
if ! curl -s "http://localhost:$PORT_GOGENTO/" > /dev/null 2>&1; then
    echo -e "${YELLOW}GoGentoServerGo server may not have started properly${NC}"
fi

# Start PHP built-in server in background
echo -e "${GREEN}Starting PHP built-in server on port $PORT_PHP...${NC}"
cd "$DOCROOT/pub"
php -S localhost:$PORT_PHP > /tmp/php_server.log 2>&1 &
PHP_PID=$!
sleep 2

# Check if PHP server started
if ! curl -s "http://localhost:$PORT_PHP/" > /dev/null 2>&1; then
    echo -e "${YELLOW}PHP server may not have started properly${NC}"
fi

# Run tests
echo ""
echo "=================================================================================="
echo "RUNNING PERFORMANCE TESTS"
echo "=================================================================================="
echo ""

GOGENTO_RPS=$(run_ab_test $PORT_GOGENTO "GoGentoServerGo")
PHP_RPS=$(run_ab_test $PORT_PHP "PHP Built-in Server")

# Stop servers
echo -e "${YELLOW}Stopping servers...${NC}"
kill $GOGENTO_PID 2>/dev/null || true
kill $PHP_PID 2>/dev/null || true
sleep 1

# Comparison
echo "=================================================================================="
echo "COMPARISON SUMMARY"
echo "=================================================================================="
echo ""

if [ ! -z "$GOGENTO_RPS" ] && [ ! -z "$PHP_RPS" ]; then
    # Convert to numbers for comparison
    GOGENTO_NUM=$(echo "$GOGENTO_RPS" | sed 's/[^0-9.]//g')
    PHP_NUM=$(echo "$PHP_RPS" | sed 's/[^0-9.]//g')
    
    if [ ! -z "$GOGENTO_NUM" ] && [ ! -z "$PHP_NUM" ]; then
        SPEEDUP=$(echo "scale=2; $GOGENTO_NUM / $PHP_NUM" | bc)
        if (( $(echo "$GOGENTO_NUM > $PHP_NUM" | bc -l) )); then
            echo -e "${GREEN}GoGentoServerGo is ${SPEEDUP}x FASTER than PHP${NC}"
        else
            echo -e "${YELLOW}PHP is ${SPEEDUP}x FASTER than GoGentoServerGo${NC}"
        fi
    fi
fi

echo ""
echo "Full test results saved in /tmp/ab_*.txt"
echo ""
