#!/bin/bash
# Seeding script for Identity Tracker (de.systopia.identitytracker)
set -e
cd /home/buildkit/buildkit/build/site/web
echo "  👥 Seeding Identity Tracker..."

create_contact() { cv api4 Contact.create values="{\"contact_type\":\"$1\",\"first_name\":\"$2\",\"last_name\":\"$3\",\"email\":\"$4\"}" > /dev/null 2>&1 || true; }
create_org() { cv api4 Contact.create values="{\"contact_type\":\"Organization\",\"organization_name\":\"$1\",\"email\":\"$2\"}" > /dev/null 2>&1 || true; }

create_contact "Individual" "Maria" "Rossi" "maria.rossi@example.it"
create_contact "Individual" "Thomas" "Anderson" "thomas.anderson@example.com"
create_contact "Individual" "Yuki" "Tanaka" "yuki.tanaka@example.jp"
create_contact "Individual" "Amina" "Hassan" "amina.hassan@example.eg"
create_org "Green Foundation Europe" "contact@greenfoundation.example.org"
create_org "International Red Cross" "info@redcross.example.org"

echo "     ✓ Created 6 contacts for identity tracking"
echo "     ℹ️  View identity history in contact summary"
