#!/bin/bash
set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# PHP versions to test
PHP_VERSIONS=("7.4" "8.0" "8.1" "8.2" "8.3")

# Site type to test with
SITE_TYPE="${CIVICRM_SITE_TYPE:-drupal10-demo}"

# CiviCRM version to test (empty = latest)
CIVI_VERSION="${CIVICRM_VERSION:-}"

# Test results
RESULTS=()

echo "=========================================="
echo "CiviCRM Multi-PHP Version Test Suite"
echo "=========================================="
echo ""
echo "Testing PHP versions: ${PHP_VERSIONS[*]}"
echo "Site type: ${SITE_TYPE}"
if [[ -n "${CIVI_VERSION}" ]]; then
    echo "CiviCRM version: ${CIVI_VERSION}"
else
    echo "CiviCRM version: latest"
fi
echo ""

# Function to test a PHP version
test_php_version() {
    local php_version=$1
    echo ""
    echo "=========================================="
    echo "Testing PHP ${php_version}"
    echo "=========================================="

    # Stop existing containers
    echo "Stopping existing containers..."
    docker-compose down -v

    # Build with specific PHP version
    echo "Building with PHP ${php_version}..."
    PHP_VERSION=${php_version} docker-compose build --no-cache

    # Start containers
    echo "Starting containers..."
    CIVICRM_SITE_TYPE=${SITE_TYPE} CIVICRM_VERSION=${CIVI_VERSION} PHP_VERSION=${php_version} docker-compose up -d

    # Wait for site to be ready
    echo "Waiting for site to be ready..."
    sleep 30

    # Check if site is accessible
    echo "Checking site accessibility..."
    max_attempts=30
    attempt=0
    while [[ ${attempt} -lt ${max_attempts} ]]; do
        HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080 || true)
        if echo "${HTTP_STATUS}" | grep -q "200\|302"; then
            echo -e "${GREEN}✓ Site is accessible${NC}"
            break
        fi
        attempt=$((attempt + 1))
        echo "Waiting... (${attempt}/${max_attempts})"
        sleep 10
    done

    if [[ ${attempt} -eq ${max_attempts} ]]; then
        echo -e "${RED}✗ Site failed to become accessible${NC}"
        local result_label="PHP ${php_version}"
        [[ -n "${CIVI_VERSION}" ]] && result_label="${result_label} + CiviCRM ${CIVI_VERSION}"
        RESULTS+=("${result_label}: FAILED (site not accessible)")
        return 1
    fi

    # Run Playwright tests
    echo "Running Playwright tests..."
    if SKIP_WEBSERVER=1 npm test; then
        echo -e "${GREEN}✓ Tests passed for PHP ${php_version}${NC}"
        local result_label="PHP ${php_version}"
        [[ -n "${CIVI_VERSION}" ]] && result_label="${result_label} + CiviCRM ${CIVI_VERSION}"
        RESULTS+=("${result_label}: PASSED")
        return 0
    else
        echo -e "${RED}✗ Tests failed for PHP ${php_version}${NC}"
        local result_label="PHP ${php_version}"
        [[ -n "${CIVI_VERSION}" ]] && result_label="${result_label} + CiviCRM ${CIVI_VERSION}"
        RESULTS+=("${result_label}: FAILED (tests)")
        return 1
    fi
}

# Check if npm packages are installed
if [[ ! -d "node_modules" ]]; then
    echo "Installing npm dependencies..."
    npm install
    npx playwright install
fi

# Test each PHP version
for version in "${PHP_VERSIONS[@]}"; do
    # shellcheck disable=SC2310
    if test_php_version "${version}"; then
        echo -e "${GREEN}✓ PHP ${version} completed successfully${NC}"
    else
        echo -e "${RED}✗ PHP ${version} failed${NC}"
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
    if [[ ${result} == *"PASSED"* ]]; then
        echo -e "${GREEN}✓ ${result}${NC}"
    else
        echo -e "${RED}✗ ${result}${NC}"
    fi
done
echo "=========================================="

# Exit with error if any tests failed
for result in "${RESULTS[@]}"; do
    if [[ ${result} == *"FAILED"* ]]; then
        exit 1
    fi
done

exit 0
