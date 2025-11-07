#!/bin/bash
# Two-stage build script for creating a pre-configured all-in-one CiviCRM image
# Stage 1: Build base image and run civibuild
# Stage 2: Export database and rebuild with pre-configured database

set -e

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Configuration
BASE_IMAGE_NAME="${BASE_IMAGE_NAME:-civicrm-eu-ngo-base}"
FINAL_IMAGE_NAME="${FINAL_IMAGE_NAME:-civicrm-eu-ngo}"
IMAGE_TAG="${IMAGE_TAG:-latest}"
CONTAINER_NAME="civicrm-aio-builder"
BUILD_CONTEXT="$(dirname "$0")/.."

echo -e "${BLUE}=========================================="
echo "CiviCRM EU-NGO Pre-Built Image Builder"
echo -e "==========================================${NC}"
echo ""
echo "This script will:"
echo "  1. Build base image"
echo "  2. Run container and create CiviCRM site"
echo "  3. Export database"
echo "  4. Build final image with pre-configured database"
echo ""

# Parse arguments
SKIP_BASE_BUILD=false
SKIP_SITE_CREATION=false
PLATFORM="linux/arm64"
PUSH_TO_REGISTRY=false

while [[ $# -gt 0 ]]; do
    case $1 in
        --skip-base)
            SKIP_BASE_BUILD=true
            shift
            ;;
        --skip-site)
            SKIP_SITE_CREATION=true
            shift
            ;;
        --platform)
            PLATFORM="$2"
            shift 2
            ;;
        --tag)
            IMAGE_TAG="$2"
            shift 2
            ;;
        --push)
            PUSH_TO_REGISTRY=true
            shift
            ;;
        *)
            echo -e "${RED}Unknown option: $1${NC}"
            exit 1
            ;;
    esac
done

# Step 1: Build base image
if [ "$SKIP_BASE_BUILD" = false ]; then
    echo -e "${BLUE}Step 1/4: Building base image...${NC}"
    echo ""

    if [ "$PUSH_TO_REGISTRY" = true ]; then
        docker buildx build \
            --file "$BUILD_CONTEXT/allinone/Dockerfile" \
            --tag "${BASE_IMAGE_NAME}:${IMAGE_TAG}" \
            --platform "$PLATFORM" \
            --push \
            "$BUILD_CONTEXT"
    else
        docker buildx build \
            --file "$BUILD_CONTEXT/allinone/Dockerfile" \
            --tag "${BASE_IMAGE_NAME}:${IMAGE_TAG}" \
            --platform "$PLATFORM" \
            --load \
            "$BUILD_CONTEXT"
    fi

    if [ $? -eq 0 ]; then
        echo ""
        echo -e "${GREEN}✓ Base image built successfully${NC}"
    else
        echo -e "${RED}✗ Base image build failed${NC}"
        exit 1
    fi
else
    echo -e "${YELLOW}Skipping base image build${NC}"
fi

echo ""

# Step 2: Run container and create site
if [ "$SKIP_SITE_CREATION" = false ]; then
    echo -e "${BLUE}Step 2/4: Creating CiviCRM site...${NC}"
    echo ""

    # Clean up any existing container
    docker rm -f "$CONTAINER_NAME" 2>/dev/null || true

    # Run container
    echo "Starting container (this will take 5-10 minutes)..."
    docker run -d \
        --name "$CONTAINER_NAME" \
        -p 8888:80 \
        "${BASE_IMAGE_NAME}:${IMAGE_TAG}"

    # Wait for site creation to complete
    echo "Waiting for CiviCRM site creation..."
    echo "(You can watch progress with: docker logs -f $CONTAINER_NAME)"
    echo ""

    # Monitor the logs for completion
    timeout 900 bash -c "
        while true; do
            if docker logs $CONTAINER_NAME 2>&1 | grep -q 'CiviCRM is ready!'; then
                echo -e '${GREEN}✓ Site created successfully${NC}'
                break
            fi
            if docker logs $CONTAINER_NAME 2>&1 | grep -q 'Site creation failed'; then
                echo -e '${RED}✗ Site creation failed${NC}'
                docker logs $CONTAINER_NAME
                exit 1
            fi
            sleep 10
        done
    "

    if [ $? -ne 0 ]; then
        echo -e "${RED}✗ Timeout or error during site creation${NC}"
        docker logs "$CONTAINER_NAME"
        exit 1
    fi

    echo ""
    echo "Site is accessible at: http://localhost:8888"
    echo "Username: admin / Password: admin"
    echo ""
else
    echo -e "${YELLOW}Skipping site creation${NC}"
    echo ""
fi

# Step 3: Export database
echo -e "${BLUE}Step 3/4: Exporting database...${NC}"
echo ""

# Create temporary directory for database export
TEMP_DIR=$(mktemp -d)
DB_DUMP="$TEMP_DIR/civicrm-initial.sql"

echo "Exporting database to $DB_DUMP..."
docker exec "$CONTAINER_NAME" mysqldump -u root -proot --all-databases --single-transaction > "$DB_DUMP"

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Database exported ($(du -h $DB_DUMP | cut -f1))${NC}"
else
    echo -e "${RED}✗ Database export failed${NC}"
    exit 1
fi

echo ""

# Step 4: Build final image with database
echo -e "${BLUE}Step 4/4: Building final image with pre-configured database...${NC}"
echo ""

# Create a new Dockerfile that includes the database
cat > "$TEMP_DIR/Dockerfile.final" <<EOF
FROM ${BASE_IMAGE_NAME}:${IMAGE_TAG}

USER root

# Copy pre-built database
COPY civicrm-initial.sql /home/buildkit/civicrm-initial.sql
RUN chown buildkit:buildkit /home/buildkit/civicrm-initial.sql

# Copy the site files from the builder container
# Note: This will be added via docker cp, not COPY
EOF

# Export site files from container
echo "Exporting site files..."
SITE_TARBALL="$TEMP_DIR/site.tar.gz"
docker exec "$CONTAINER_NAME" tar czf - -C /home/buildkit/buildkit/build site > "$SITE_TARBALL"

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Site files exported ($(du -h $SITE_TARBALL | cut -f1))${NC}"
else
    echo -e "${RED}✗ Site files export failed${NC}"
    exit 1
fi

# Update Dockerfile to include site files
cat >> "$TEMP_DIR/Dockerfile.final" <<EOF

# Copy pre-built site
COPY site.tar.gz /tmp/site.tar.gz
RUN mkdir -p /home/buildkit/buildkit/build \\
    && tar xzf /tmp/site.tar.gz -C /home/buildkit/buildkit/build \\
    && rm /tmp/site.tar.gz \\
    && chown -R buildkit:buildkit /home/buildkit/buildkit/build
EOF

# Build final image
echo ""
echo "Building final image..."
if [ "$PUSH_TO_REGISTRY" = true ]; then
    docker buildx build \
        -f "$TEMP_DIR/Dockerfile.final" \
        --tag "${FINAL_IMAGE_NAME}:${IMAGE_TAG}" \
        --platform "$PLATFORM" \
        --push \
        "$TEMP_DIR"
else
    docker buildx build \
        -f "$TEMP_DIR/Dockerfile.final" \
        --tag "${FINAL_IMAGE_NAME}:${IMAGE_TAG}" \
        --platform "$PLATFORM" \
        --load \
        "$TEMP_DIR"
fi

if [ $? -eq 0 ]; then
    echo ""
    echo -e "${GREEN}✓ Final image built successfully${NC}"
else
    echo -e "${RED}✗ Final image build failed${NC}"
    exit 1
fi

# Cleanup
echo ""
echo "Cleaning up..."
docker rm -f "$CONTAINER_NAME" 2>/dev/null || true
rm -rf "$TEMP_DIR"

echo ""
echo -e "${GREEN}=========================================="
echo "✓ Pre-built image ready!"
echo -e "==========================================${NC}"
echo ""
echo "Image: ${FINAL_IMAGE_NAME}:${IMAGE_TAG}"
echo ""
echo "Test it with:"
echo "  docker run -d -p 8080:80 ${FINAL_IMAGE_NAME}:${IMAGE_TAG}"
echo ""
echo "The site should be ready in ~30 seconds at: http://localhost:8080"
echo ""
