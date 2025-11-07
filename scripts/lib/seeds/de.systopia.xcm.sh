#!/bin/bash
# Seeding script for XCM (de.systopia.xcm)
set -e
cd /home/buildkit/buildkit/build/site/web
echo "  🔍 Seeding XCM (Extended Contact Matcher)..."

create_contact() {
    cv api4 Contact.create values="{\"contact_type\":\"$1\",\"first_name\":\"$2\",\"last_name\":\"$3\",\"email\":\"$4\"}" > /dev/null 2>&1 || true
}

create_org() {
    cv api4 Contact.create values="{\"contact_type\":\"Organization\",\"organization_name\":\"$1\",\"email\":\"$2\"}" > /dev/null 2>&1 || true
}

create_contact "Individual" "John" "Smith" "john.smith@example.com"
create_contact "Individual" "Jane" "Doe" "jane.doe@example.com"
create_contact "Individual" "Robert" "Johnson" "robert.j@example.com"
create_org "World Vision International" "info@worldvision.example.org"
create_org "Oxfam International" "contact@oxfam.example.org"

echo "     ✓ Created 5 contacts for XCM matching tests"
echo "     ℹ️  Configure matching rules: /civicrm/admin/setting/xcm"
