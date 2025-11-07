#!/bin/bash
# Build script for CiviCRM EU-NGO All-In-One Docker image

set -e

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${BLUE}=========================================="
echo "CiviCRM EU-NGO All-In-One Image Builder"
echo -e "==========================================${NC}"
echo ""

# Default values
PHP_VERSION=${PHP_VERSION:-8.2}
CIVICRM_VERSION=${CIVICRM_VERSION:-6.7.1}
IMAGE_NAME=${IMAGE_NAME:-civicrm-eu-ngo}
IMAGE_TAG=${IMAGE_TAG:-latest}
PLATFORM=${PLATFORM:-linux/amd64}

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --php-version)
            PHP_VERSION="$2"
            shift 2
            ;;
        --civicrm-version)
            CIVICRM_VERSION="$2"
            shift 2
            ;;
        --tag)
            IMAGE_TAG="$2"
            shift 2
            ;;
        --name)
            IMAGE_NAME="$2"
            shift 2
            ;;
        --platform)
            PLATFORM="$2"
            shift 2
            ;;
        --multi-platform)
            PLATFORM="linux/amd64,linux/arm64"
            shift
            ;;
        --push)
            PUSH_IMAGE="true"
            shift
            ;;
        --help)
            echo "Usage: $0 [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --php-version VERSION      PHP version (default: 8.2)"
            echo "  --civicrm-version VERSION  CiviCRM version (default: 6.7.1)"
            echo "  --tag TAG                  Image tag (default: latest)"
            echo "  --name NAME                Image name (default: civicrm-eu-ngo)"
            echo "  --platform PLATFORM        Target platform (default: linux/amd64)"
            echo "  --multi-platform           Build for both AMD64 and ARM64"
            echo "  --push                     Push to registry after build"
            echo "  --help                     Show this help message"
            echo ""
            echo "Examples:"
            echo "  $0"
            echo "  $0 --php-version 8.3 --tag test"
            echo "  $0 --multi-platform --push"
            exit 0
            ;;
        *)
            echo -e "${RED}Unknown option: $1${NC}"
            echo "Use --help for usage information"
            exit 1
            ;;
    esac
done

# Display build configuration
echo -e "${YELLOW}Build Configuration:${NC}"
echo "  Image Name:      ${IMAGE_NAME}:${IMAGE_TAG}"
echo "  PHP Version:     ${PHP_VERSION}"
echo "  CiviCRM Version: ${CIVICRM_VERSION}"
echo "  Platform:        ${PLATFORM}"
echo "  Push to Registry: ${PUSH_IMAGE:-false}"
echo ""

# Get script directory and project root
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$( cd "${SCRIPT_DIR}/../.." && pwd )"

# Change to project root
cd "${PROJECT_ROOT}"

# Check if we're in the correct directory
if [ ! -f "allinone/Dockerfile" ]; then
    echo -e "${RED}Error: allinone/Dockerfile not found${NC}"
    echo "Script must be run from project root or allinone directory"
    exit 1
fi

# Check if extensions/eu-nonprofit/civikitchen.json exists
if [ ! -f "extensions/eu-nonprofit/civikitchen.json" ]; then
    echo -e "${RED}Error: extensions/eu-nonprofit/civikitchen.json not found${NC}"
    echo "This file is required for the build"
    exit 1
fi

# Check if buildx is available for multi-platform builds
if [[ "$PLATFORM" == *","* ]]; then
    if ! docker buildx version >/dev/null 2>&1; then
        echo -e "${RED}Error: Docker Buildx is required for multi-platform builds${NC}"
        echo "Install it with: docker buildx install"
        exit 1
    fi
fi

# Estimate build time
echo -e "${YELLOW}⏱️  Estimated build time: 15-30 minutes${NC}"
echo -e "${YELLOW}   (depending on network speed and system performance)${NC}"
echo ""

read -p "Do you want to continue? (y/N) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Build cancelled"
    exit 0
fi

echo ""
echo -e "${BLUE}Starting build...${NC}"
echo ""

# Build command
BUILD_CMD="docker buildx build"
BUILD_CMD="${BUILD_CMD} --file allinone/Dockerfile"
BUILD_CMD="${BUILD_CMD} --platform ${PLATFORM}"
BUILD_CMD="${BUILD_CMD} --build-arg PHP_VERSION=${PHP_VERSION}"
BUILD_CMD="${BUILD_CMD} --build-arg CIVICRM_VERSION=${CIVICRM_VERSION}"
BUILD_CMD="${BUILD_CMD} --tag ${IMAGE_NAME}:${IMAGE_TAG}"

# Add additional tags
if [ "${IMAGE_TAG}" == "latest" ]; then
    BUILD_CMD="${BUILD_CMD} --tag ${IMAGE_NAME}:${CIVICRM_VERSION}"
    BUILD_CMD="${BUILD_CMD} --tag ${IMAGE_NAME}:php${PHP_VERSION}"
fi

# Push if requested
if [ "${PUSH_IMAGE}" == "true" ]; then
    BUILD_CMD="${BUILD_CMD} --push"
else
    BUILD_CMD="${BUILD_CMD} --load"
fi

# Add progress output
BUILD_CMD="${BUILD_CMD} --progress=plain"

# Add context
BUILD_CMD="${BUILD_CMD} ."

# Execute build
echo -e "${GREEN}Executing:${NC} ${BUILD_CMD}"
echo ""

START_TIME=$(date +%s)

if eval ${BUILD_CMD}; then
    END_TIME=$(date +%s)
    DURATION=$((END_TIME - START_TIME))
    MINUTES=$((DURATION / 60))
    SECONDS=$((DURATION % 60))

    echo ""
    echo -e "${GREEN}=========================================="
    echo "✓ Build completed successfully!"
    echo -e "==========================================${NC}"
    echo ""
    echo "Build time: ${MINUTES}m ${SECONDS}s"
    echo "Image: ${IMAGE_NAME}:${IMAGE_TAG}"

    if [ "${PUSH_IMAGE}" != "true" ]; then
        echo ""
        echo -e "${YELLOW}Next steps:${NC}"
        echo "  1. Test the image:"
        echo "     docker run -d -p 8080:80 --name civicrm-test ${IMAGE_NAME}:${IMAGE_TAG}"
        echo ""
        echo "  2. Or run the test script:"
        echo "     ./allinone/test.sh"
        echo ""
        echo "  3. Push to registry:"
        echo "     docker tag ${IMAGE_NAME}:${IMAGE_TAG} username/${IMAGE_NAME}:${IMAGE_TAG}"
        echo "     docker push username/${IMAGE_NAME}:${IMAGE_TAG}"
    else
        echo ""
        echo -e "${GREEN}✓ Image pushed to registry${NC}"
        echo ""
        echo "Pull and run with:"
        echo "  docker run -d -p 8080:80 ${IMAGE_NAME}:${IMAGE_TAG}"
    fi

    echo ""

    # Display image size if not multi-platform
    if [[ "$PLATFORM" != *","* ]] && [ "${PUSH_IMAGE}" != "true" ]; then
        IMAGE_SIZE=$(docker images ${IMAGE_NAME}:${IMAGE_TAG} --format "{{.Size}}")
        echo "Image size: ${IMAGE_SIZE}"
    fi

else
    END_TIME=$(date +%s)
    DURATION=$((END_TIME - START_TIME))
    MINUTES=$((DURATION / 60))
    SECONDS=$((DURATION % 60))

    echo ""
    echo -e "${RED}=========================================="
    echo "✗ Build failed after ${MINUTES}m ${SECONDS}s"
    echo -e "==========================================${NC}"
    echo ""
    echo "Check the error messages above for details"
    exit 1
fi
