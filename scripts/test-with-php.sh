#!/bin/bash
set -e

# Script to test with a specific PHP version
PHP_VERSION=${1:-8.2}
SITE_TYPE=${2:-drupal10-demo}

echo "=========================================="
echo "Testing CiviCRM with PHP ${PHP_VERSION}"
echo "Site type: ${SITE_TYPE}"
echo "=========================================="

# Stop existing containers
echo "Stopping existing containers..."
docker-compose down -v

# Build with specific PHP version
echo "Building with PHP ${PHP_VERSION}..."
PHP_VERSION=${PHP_VERSION} docker-compose build --no-cache

# Start containers
echo "Starting containers with PHP ${PHP_VERSION} and site type ${SITE_TYPE}..."
CIVICRM_SITE_TYPE=${SITE_TYPE} PHP_VERSION=${PHP_VERSION} docker-compose up -d

# Show logs
echo ""
echo "Container started. Showing logs (Ctrl+C to stop following)..."
echo "Site will be available at http://localhost:8080"
echo ""
docker-compose logs -f civicrm
