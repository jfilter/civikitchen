#!/bin/bash
# Test script for CiviCRM EU-NGO All-In-One Docker image

set -e

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Default values
IMAGE_NAME=${IMAGE_NAME:-civicrm-eu-ngo}
IMAGE_TAG=${IMAGE_TAG:-latest}
CONTAINER_NAME="civicrm-aio-test"
TEST_PORT=8080
TIMEOUT=180  # 3 minutes timeout

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --image)
            IMAGE_NAME="$2"
            shift 2
            ;;
        --tag)
            IMAGE_TAG="$2"
            shift 2
            ;;
        --port)
            TEST_PORT="$2"
            shift 2
            ;;
        --timeout)
            TIMEOUT="$2"
            shift 2
            ;;
        --keep)
            KEEP_CONTAINER="true"
            shift
            ;;
        --help)
            echo "Usage: $0 [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --image NAME     Image name (default: civicrm-eu-ngo)"
            echo "  --tag TAG        Image tag (default: latest)"
            echo "  --port PORT      Port to test on (default: 8080)"
            echo "  --timeout SEC    Startup timeout in seconds (default: 180)"
            echo "  --keep           Keep container running after tests"
            echo "  --help           Show this help message"
            echo ""
            echo "Examples:"
            echo "  $0"
            echo "  $0 --tag test --port 9090"
            echo "  $0 --keep  # Keep container for manual inspection"
            exit 0
            ;;
        *)
            echo -e "${RED}Unknown option: $1${NC}"
            echo "Use --help for usage information"
            exit 1
            ;;
    esac
done

FULL_IMAGE="${IMAGE_NAME}:${IMAGE_TAG}"

echo -e "${BLUE}=========================================="
echo "CiviCRM EU-NGO All-In-One Image Tests"
echo -e "==========================================${NC}"
echo ""
echo "Test Configuration:"
echo "  Image:     ${FULL_IMAGE}"
echo "  Port:      ${TEST_PORT}"
echo "  Timeout:   ${TIMEOUT}s"
echo ""

# Cleanup function
cleanup() {
    if [ "${KEEP_CONTAINER}" != "true" ]; then
        echo ""
        echo -e "${YELLOW}Cleaning up...${NC}"
        docker stop ${CONTAINER_NAME} >/dev/null 2>&1 || true
        docker rm ${CONTAINER_NAME} >/dev/null 2>&1 || true
        echo -e "${GREEN}✓ Cleanup complete${NC}"
    else
        echo ""
        echo -e "${YELLOW}Container kept running: ${CONTAINER_NAME}${NC}"
        echo "Access at: http://localhost:${TEST_PORT}"
        echo ""
        echo "To view logs:"
        echo "  docker logs ${CONTAINER_NAME}"
        echo ""
        echo "To stop and remove:"
        echo "  docker stop ${CONTAINER_NAME} && docker rm ${CONTAINER_NAME}"
    fi
}

# Set trap for cleanup on exit
if [ "${KEEP_CONTAINER}" != "true" ]; then
    trap cleanup EXIT
fi

# Test 1: Check if image exists
echo -e "${BLUE}Test 1: Checking if image exists...${NC}"
if docker image inspect ${FULL_IMAGE} >/dev/null 2>&1; then
    echo -e "${GREEN}✓ Image found${NC}"
    IMAGE_SIZE=$(docker images ${FULL_IMAGE} --format "{{.Size}}")
    echo "  Size: ${IMAGE_SIZE}"
else
    echo -e "${RED}✗ Image not found: ${FULL_IMAGE}${NC}"
    echo "  Build it first with: ./allinone/build.sh"
    exit 1
fi
echo ""

# Test 2: Check if port is available
echo -e "${BLUE}Test 2: Checking if port ${TEST_PORT} is available...${NC}"
if lsof -Pi :${TEST_PORT} -sTCP:LISTEN -t >/dev/null 2>&1; then
    echo -e "${RED}✗ Port ${TEST_PORT} is already in use${NC}"
    echo "  Use --port option to specify a different port"
    exit 1
else
    echo -e "${GREEN}✓ Port ${TEST_PORT} is available${NC}"
fi
echo ""

# Test 3: Start container
echo -e "${BLUE}Test 3: Starting container...${NC}"
# Remove any existing container with same name
docker stop ${CONTAINER_NAME} >/dev/null 2>&1 || true
docker rm ${CONTAINER_NAME} >/dev/null 2>&1 || true

if docker run -d -p ${TEST_PORT}:80 --name ${CONTAINER_NAME} ${FULL_IMAGE} >/dev/null; then
    echo -e "${GREEN}✓ Container started${NC}"
    echo "  Container ID: $(docker ps -q -f name=${CONTAINER_NAME})"
else
    echo -e "${RED}✗ Failed to start container${NC}"
    exit 1
fi
echo ""

# Test 4: Wait for container to be ready
echo -e "${BLUE}Test 4: Waiting for container to be ready (max ${TIMEOUT}s)...${NC}"
ELAPSED=0
INTERVAL=5
while [ $ELAPSED -lt $TIMEOUT ]; do
    if curl -sf http://localhost:${TEST_PORT} >/dev/null 2>&1; then
        echo -e "${GREEN}✓ Container is responding (after ${ELAPSED}s)${NC}"
        break
    fi

    # Check if container is still running
    if ! docker ps -q -f name=${CONTAINER_NAME} | grep -q .; then
        echo -e "${RED}✗ Container stopped unexpectedly${NC}"
        echo ""
        echo "Last logs:"
        docker logs --tail 50 ${CONTAINER_NAME}
        exit 1
    fi

    if [ $ELAPSED -gt 0 ]; then
        echo "  Waiting... (${ELAPSED}s elapsed)"
    fi

    sleep $INTERVAL
    ELAPSED=$((ELAPSED + INTERVAL))
done

if [ $ELAPSED -ge $TIMEOUT ]; then
    echo -e "${RED}✗ Timeout waiting for container (${TIMEOUT}s)${NC}"
    echo ""
    echo "Container logs:"
    docker logs ${CONTAINER_NAME}
    exit 1
fi
echo ""

# Test 5: HTTP Response
echo -e "${BLUE}Test 5: Testing HTTP response...${NC}"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:${TEST_PORT})
if [ "$HTTP_CODE" -eq 200 ] || [ "$HTTP_CODE" -eq 302 ] || [ "$HTTP_CODE" -eq 301 ]; then
    echo -e "${GREEN}✓ HTTP response: ${HTTP_CODE}${NC}"
else
    echo -e "${RED}✗ Unexpected HTTP code: ${HTTP_CODE}${NC}"
    exit 1
fi
echo ""

# Test 6: MariaDB is running
echo -e "${BLUE}Test 6: Testing MariaDB connection...${NC}"
if docker exec ${CONTAINER_NAME} mysqladmin -u root -proot ping >/dev/null 2>&1; then
    echo -e "${GREEN}✓ MariaDB is running${NC}"
else
    echo -e "${RED}✗ MariaDB connection failed${NC}"
    exit 1
fi
echo ""

# Test 7: CiviCRM database exists
echo -e "${BLUE}Test 7: Testing CiviCRM database...${NC}"
DB_CHECK=$(docker exec ${CONTAINER_NAME} bash -c "cd /home/buildkit/buildkit/build/site/web && /home/buildkit/buildkit/bin/cv ev 'return CRM_Core_DAO::singleValueQuery(\"SELECT COUNT(*) FROM civicrm_contact\");' 2>/dev/null" | tr -d '"')
if [ ! -z "$DB_CHECK" ] && [ "$DB_CHECK" -gt 0 ]; then
    echo -e "${GREEN}✓ CiviCRM database accessible${NC}"
    echo "  Found ${DB_CHECK} contacts"
else
    echo -e "${RED}✗ CiviCRM database check failed${NC}"
    exit 1
fi
echo ""

# Test 8: Check installed extensions
echo -e "${BLUE}Test 8: Checking EU-NGO extensions...${NC}"
EXPECTED_EXTENSIONS=(
    "org.project60.banking"
    "org.project60.sepa"
    "de.systopia.contract"
    "de.systopia.twingle"
    "de.systopia.gdprx"
    "de.systopia.xcm"
    "de.systopia.identitytracker"
    "org.civicrm.shoreditch"
    "org.civicrm.contactlayout"
)

EXTENSION_CHECK_FAILED=0
for ext in "${EXPECTED_EXTENSIONS[@]}"; do
    if docker exec ${CONTAINER_NAME} bash -c "cd /home/buildkit/buildkit/build/site/web && /home/buildkit/buildkit/bin/cv api4 Extension.get 2>/dev/null" | grep -q "\"key\": \"${ext}\""; then
        echo -e "  ${GREEN}✓${NC} ${ext}"
    else
        echo -e "  ${RED}✗${NC} ${ext} not found"
        EXTENSION_CHECK_FAILED=1
    fi
done

if [ $EXTENSION_CHECK_FAILED -eq 0 ]; then
    echo -e "${GREEN}✓ All 9 extensions found${NC}"
else
    echo -e "${RED}✗ Some extensions are missing${NC}"
    exit 1
fi
echo ""

# Test 9: Apache is serving correctly
echo -e "${BLUE}Test 9: Testing Apache configuration...${NC}"
if docker exec ${CONTAINER_NAME} apachectl -t >/dev/null 2>&1; then
    echo -e "${GREEN}✓ Apache configuration is valid${NC}"
else
    echo -e "${RED}✗ Apache configuration has errors${NC}"
    docker exec ${CONTAINER_NAME} apachectl -t
    exit 1
fi
echo ""

# Test 10: File permissions
echo -e "${BLUE}Test 10: Testing file permissions...${NC}"
if docker exec ${CONTAINER_NAME} test -w /home/buildkit/buildkit/build/site/web/sites/default/files; then
    echo -e "${GREEN}✓ Files directory is writable${NC}"
else
    echo -e "${RED}✗ Files directory is not writable${NC}"
    exit 1
fi
echo ""

# Display summary
echo -e "${GREEN}=========================================="
echo "✓ All tests passed!"
echo -e "==========================================${NC}"
echo ""
echo "Test Summary:"
echo "  Image:      ${FULL_IMAGE}"
echo "  Image Size: ${IMAGE_SIZE}"
echo "  Startup:    ${ELAPSED}s"
echo "  Access URL: http://localhost:${TEST_PORT}"
echo ""
echo "Default Credentials:"
echo "  Username: admin"
echo "  Password: admin"
echo ""
echo "Extensions: 9 EU-NGO extensions verified"
echo ""

if [ "${KEEP_CONTAINER}" != "true" ]; then
    echo -e "${YELLOW}Container will be stopped and removed.${NC}"
    echo "To keep it running, use: $0 --keep"
else
    echo -e "${GREEN}Container is running: ${CONTAINER_NAME}${NC}"
fi

echo ""
