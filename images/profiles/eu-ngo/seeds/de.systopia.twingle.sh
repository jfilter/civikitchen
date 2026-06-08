#!/bin/bash
# Seeding script for Twingle (de.systopia.twingle)
set -e
cd /home/buildkit/buildkit/build/site/web
echo "  🎁 Seeding Twingle integration..."

create_contact() { cv api4 Contact.create values="{\"contact_type\":\"Individual\",\"first_name\":\"$1\",\"last_name\":\"$2\",\"email\":\"$3\"}" > /dev/null 2>&1 || true; }

create_contact "Michael" "Hoffmann" "michael.hoffmann@example.de"
create_contact "Sarah" "Fischer" "sarah.fischer@example.de"
create_contact "David" "Meyer" "david.meyer@example.de"
create_contact "Laura" "Becker" "laura.becker@example.de"

# Create financial type for Twingle donations
cv api4 FinancialType.create values='{"name":"Twingle Donation","description":"Donations via Twingle platform","is_active":true}' match='name' > /dev/null 2>&1 || true

echo "     ✓ Created 4 Twingle donors and financial type"
echo "     ℹ️  Configure Twingle API credentials and project mapping"
