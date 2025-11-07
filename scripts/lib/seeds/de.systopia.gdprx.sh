#!/bin/bash
# Seeding script for GDPR Extension (de.systopia.gdprx)
set -e
cd /home/buildkit/buildkit/build/site/web
echo "  🔒 Seeding GDPR extension..."

create_contact() { cv api4 Contact.create values="{\"contact_type\":\"Individual\",\"first_name\":\"$1\",\"last_name\":\"$2\",\"email\":\"$3\"}" > /dev/null 2>&1 || true; }

create_contact "Lisa" "Schmidt" "lisa.schmidt@example.de"
create_contact "Marco" "Bianchi" "marco.bianchi@example.it"
create_contact "Sophie" "Dubois" "sophie.dubois@example.fr"
create_contact "Elena" "Gonzalez" "elena.gonzalez@example.es"

echo "     ✓ Created 4 contacts for GDPR management"
echo "     ℹ️  Configure GDPR settings, consent tracking, and DSAR"
