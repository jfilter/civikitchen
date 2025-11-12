#!/bin/bash
set -e

# Navigate to site root
cd /home/buildkit/buildkit/build/site/web

echo "Seeding test data for test-extension-with-seed..."

# Create test contact using CiviCRM API with a unique marker
# Using single-line JSON to avoid shell escaping issues
cv api4 Contact.create values='{"first_name":"E2E","last_name":"SeedTest","contact_type":"Individual","contact_source":"test-seed-marker-12345"}'

echo "✓ Seeding complete!"
