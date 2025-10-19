#!/bin/bash
set -e

# Test seed script for e2e testing
# Creates a test contact that can be verified by the test

cd /home/buildkit/buildkit/build/site/web

echo "Seeding test data for test-extension-with-seed..."

# Create a unique test contact using API v3 (simpler syntax)
# Note: The field is contact_source, not source
cv api3 Contact.create first_name=E2E last_name=SeedTest contact_type=Individual contact_source=test-seed-marker-12345

echo "✓ Test seeding complete!"
