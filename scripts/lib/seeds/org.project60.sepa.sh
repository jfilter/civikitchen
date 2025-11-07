#!/bin/bash
# Seeding script for CiviSEPA (org.project60.sepa)
#
# This script creates sample data for testing SEPA Direct Debit functionality

set -e

cd /home/buildkit/buildkit/build/site/web

echo "  📝 Seeding CiviSEPA with comprehensive sample data..."

# Helper function
create_contact_if_not_exists() {
    local contact_type="$1"
    local first_name="$2"
    local last_name="$3"
    local email="$4"

    existing=$(cv api4 Contact.get +w contact_type="$contact_type" +w email="$email" +l 1 --out=json 2>/dev/null | jq -r '.[] | length' || echo "0")

    if [ "$existing" -eq 0 ]; then
        cv api4 Contact.create values="{\"contact_type\":\"$contact_type\",\"first_name\":\"$first_name\",\"last_name\":\"$last_name\",\"email\":\"$email\"}" > /dev/null 2>&1 || true
        echo "     ✓ Created contact: $first_name $last_name ($email)"
    fi
}

# Create financial types for SEPA
cv api4 FinancialType.create values='{"name":"SEPA Direct Debit","description":"Recurring donations via SEPA mandate","is_active":true}' match='name' > /dev/null 2>&1 || true
cv api4 FinancialType.create values='{"name":"SEPA One-Off","description":"One-time SEPA donations","is_active":true}' match='name' > /dev/null 2>&1 || true
cv api4 FinancialType.create values='{"name":"SEPA Recurring","description":"Monthly recurring SEPA donations","is_active":true}' match='name' > /dev/null 2>&1 || true

# Create sample contacts with European names (SEPA region)
create_contact_if_not_exists "Individual" "Hans" "Müller" "hans.mueller@example.de"
create_contact_if_not_exists "Individual" "François" "Martin" "francois.martin@example.fr"
create_contact_if_not_exists "Individual" "Giuseppe" "Verdi" "giuseppe.verdi@example.it"
create_contact_if_not_exists "Individual" "Isabella" "Rodriguez" "isabella.rodriguez@example.es"
create_contact_if_not_exists "Individual" "Jan" "De Vries" "jan.devries@example.nl"
create_contact_if_not_exists "Individual" "Ingrid" "Svensson" "ingrid.svensson@example.se"

echo "     ✓ Created SEPA financial types and 6 sample donors"
echo ""
echo "     ℹ️  Next steps for CiviSEPA:"
echo "        1. Configure SEPA creditor: /civicrm/sepa"
echo "        2. Set up creditor ID (format: DE98ZZZ09999999999)"
echo "        3. Create SEPA mandates for test donors"
echo "        4. Generate SEPA XML files for bank submission"
echo "        5. Test SEPA batch processing"
