#!/bin/bash
set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# CiviCRM versions to test
CIVICRM_VERSIONS=("6.5.1" "6.6.3" "6.7.1" "master")

# PHP version to test with (default: 8.2)
PHP_VER="${PHP_VERSION:-8.2}"

# Site type to test with
SITE_TYPE="${CIVICRM_SITE_TYPE:-drupal10-demo}"

# Test results
RESULTS=()

echo "=========================================="
echo "CiviCRM Multi-Version Test Suite"
echo "=========================================="
echo ""
echo "Testing CiviCRM versions: ${CIVICRM_VERSIONS[*]}"
echo "PHP version: $PHP_VER"
echo "Site type: $SITE_TYPE"
echo ""

# Function to test a CiviCRM version
test_civicrm_version() {
    local civicrm_version=$1
    echo ""
    echo "=========================================="
    echo "Testing CiviCRM $civicrm_version"
    echo "=========================================="

    # Stop existing containers
    echo "Stopping existing containers..."
    docker-compose down -v

    # Build with specific PHP version
    echo "Building with PHP $PHP_VER..."
    PHP_VERSION=$PHP_VER docker-compose build --no-cache

    # Start containers
    echo "Starting containers..."
    CIVICRM_SITE_TYPE=$SITE_TYPE CIVICRM_VERSION=$civicrm_version PHP_VERSION=$PHP_VER docker-compose up -d

    # Wait for site to be ready
    echo "Waiting for site to be ready..."
    sleep 30

    # Check if site is accessible
    echo "Checking site accessibility..."
    max_attempts=30
    attempt=0
    while [ $attempt -lt $max_attempts ]; do
        if curl -s -o /dev/null -w "%{http_code}" http://localhost:8080 | grep -q "200\|302"; then
            echo -e "${GREEN}✓ Site is accessible${NC}"
            break
        fi
        attempt=$((attempt + 1))
        echo "Waiting... ($attempt/$max_attempts)"
        sleep 10
    done

    if [ $attempt -eq $max_attempts ]; then
        echo -e "${RED}✗ Site failed to become accessible${NC}"
        RESULTS+=("CiviCRM $civicrm_version (PHP $PHP_VER): FAILED (site not accessible)")
        return 1
    fi

    # Run Playwright tests
    echo "Running Playwright tests..."
    if SKIP_WEBSERVER=1 npm test; then
        echo -e "${GREEN}✓ Tests passed for CiviCRM $civicrm_version${NC}"
        RESULTS+=("CiviCRM $civicrm_version (PHP $PHP_VER): PASSED")
        return 0
    else
        echo -e "${RED}✗ Tests failed for CiviCRM $civicrm_version${NC}"
        RESULTS+=("CiviCRM $civicrm_version (PHP $PHP_VER): FAILED (tests)")
        return 1
    fi
}

# Check if npm packages are installed
if [ ! -d "node_modules" ]; then
    echo "Installing npm dependencies..."
    npm install
    npx playwright install
fi

# Test each CiviCRM version
for version in "${CIVICRM_VERSIONS[@]}"; do
    if test_civicrm_version "$version"; then
        echo -e "${GREEN}✓ CiviCRM $version completed successfully${NC}"
    else
        echo -e "${RED}✗ CiviCRM $version failed${NC}"
    fi
done

# Stop containers
docker-compose down

# Print summary
echo ""
echo "=========================================="
echo "Test Results Summary"
echo "=========================================="
for result in "${RESULTS[@]}"; do
    if [[ $result == *"PASSED"* ]]; then
        echo -e "${GREEN}✓ $result${NC}"
    else
        echo -e "${RED}✗ $result${NC}"
    fi
done
echo "=========================================="

# Exit with error if any tests failed
for result in "${RESULTS[@]}"; do
    if [[ $result == *"FAILED"* ]]; then
        exit 1
    fi
done

exit 0
