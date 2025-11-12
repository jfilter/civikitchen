#!/bin/bash
set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# PHP versions to test
PHP_VERSIONS=("8.1" "8.2" "8.3")

# CiviCRM versions to test
CIVICRM_VERSIONS=("6.5.1" "6.6.3" "6.7.1" "master")

# Site type to test with
SITE_TYPE="${CIVICRM_SITE_TYPE:-drupal10-demo}"

# Test results
RESULTS=()

echo "=========================================="
echo "CiviCRM Full Combination Test Suite"
echo "=========================================="
echo ""
echo "PHP versions: ${PHP_VERSIONS[*]}"
echo "CiviCRM versions: ${CIVICRM_VERSIONS[*]}"
echo "Site type: ${SITE_TYPE}"
echo ""
echo "Total combinations: $((${#PHP_VERSIONS[@]} * ${#CIVICRM_VERSIONS[@]}))"
echo ""

# Function to test a combination
test_combination() {
    local php_version=$1
    local civicrm_version=$2
    local combo_label="PHP ${php_version} + CiviCRM ${civicrm_version}"

    echo ""
    echo "=========================================="
    echo "Testing ${combo_label}"
    echo "=========================================="

    # Stop existing containers
    echo "Stopping existing containers..."
    docker-compose down -v

    # Build with specific PHP version
    echo "Building with PHP ${php_version}..."
    PHP_VERSION=${php_version} docker-compose build --no-cache

    # Start containers
    echo "Starting containers..."
    CIVICRM_SITE_TYPE=${SITE_TYPE} CIVICRM_VERSION=${civicrm_version} PHP_VERSION=${php_version} docker-compose up -d

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
        RESULTS+=("${combo_label}: FAILED (site not accessible)")
        return 1
    fi

    # Run Playwright tests
    echo "Running Playwright tests..."
    if SKIP_WEBSERVER=1 npm test; then
        echo -e "${GREEN}✓ Tests passed for ${combo_label}${NC}"
        RESULTS+=("${combo_label}: PASSED")
        return 0
    else
        echo -e "${RED}✗ Tests failed for ${combo_label}${NC}"
        RESULTS+=("${combo_label}: FAILED (tests)")
        return 1
    fi
}

# Check if npm packages are installed
if [[ ! -d "node_modules" ]]; then
    echo "Installing npm dependencies..."
    npm install
    npx playwright install
fi

# Test all combinations
combination_count=0
for php_version in "${PHP_VERSIONS[@]}"; do
    for civicrm_version in "${CIVICRM_VERSIONS[@]}"; do
        combination_count=$((combination_count + 1))
        echo ""
        echo "=========================================="
        echo "Combination ${combination_count} of $((${#PHP_VERSIONS[@]} * ${#CIVICRM_VERSIONS[@]}))"
        echo "=========================================="

        # shellcheck disable=SC2310
        if test_combination "${php_version}" "${civicrm_version}"; then
            echo -e "${GREEN}✓ Combination completed successfully${NC}"
        else
            echo -e "${RED}✗ Combination failed${NC}"
        fi
    done
done

# Stop containers
docker-compose down

# Print summary
echo ""
echo "=========================================="
echo "Test Results Summary"
echo "=========================================="
echo ""
for result in "${RESULTS[@]}"; do
    if [[ ${result} == *"PASSED"* ]]; then
        echo -e "${GREEN}✓ ${result}${NC}"
    else
        echo -e "${RED}✗ ${result}${NC}"
    fi
done
echo ""
echo "=========================================="

# Calculate pass/fail statistics
passed=0
failed=0
for result in "${RESULTS[@]}"; do
    if [[ ${result} == *"PASSED"* ]]; then
        passed=$((passed + 1))
    else
        failed=$((failed + 1))
    fi
done

echo "Total: ${#RESULTS[@]} | Passed: ${passed} | Failed: ${failed}"
echo "=========================================="

# Exit with error if any tests failed
if [[ ${failed} -gt 0 ]]; then
    exit 1
fi

exit 0
