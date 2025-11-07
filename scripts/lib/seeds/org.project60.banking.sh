#!/bin/bash
# Seeding script for CiviBanking (org.project60.banking)
#
# This script creates comprehensive sample data for testing CiviBanking functionality
# including sample donors, financial types, and provides guidance for bank account setup.

set -e

cd /home/buildkit/buildkit/build/site/web

echo "  💳 Seeding CiviBanking with comprehensive sample data..."

# Helper: Create contact if not exists (by email)
create_contact_if_not_exists() {
    local contact_type="$1"
    local first_name="$2"
    local last_name="$3"
    local email="$4"
    local org_name="${5:-}"

    # Check if contact already exists
    existing=$(cv api4 Contact.get +w contact_type="$contact_type" +w email="$email" +l 1 --out=json 2>/dev/null | jq -r '.[] | length' || echo "0")

    if [ "$existing" -eq 0 ]; then
        if [ "$contact_type" = "Individual" ]; then
            cv api4 Contact.create values="{\"contact_type\":\"$contact_type\",\"first_name\":\"$first_name\",\"last_name\":\"$last_name\",\"email\":\"$email\"}" > /dev/null 2>&1 || true
            echo "     ✓ Created contact: $first_name $last_name ($email)"
        else
            cv api4 Contact.create values="{\"contact_type\":\"$contact_type\",\"organization_name\":\"$org_name\",\"email\":\"$email\"}" > /dev/null 2>&1 || true
            echo "     ✓ Created organization: $org_name ($email)"
        fi
    fi
}

# Create diverse sample contacts for banking transactions
create_contact_if_not_exists "Individual" "Anna" "Schmidt" "anna.schmidt@example.de"
create_contact_if_not_exists "Individual" "Pierre" "Dubois" "pierre.dubois@example.fr"
create_contact_if_not_exists "Individual" "Maria" "González" "maria.gonzalez@example.es"
create_contact_if_not_exists "Individual" "Jan" "Kowalski" "jan.kowalski@example.pl"
create_contact_if_not_exists "Individual" "Sophie" "Laurent" "sophie.laurent@example.be"
create_contact_if_not_exists "Individual" "Hans" "Müller" "hans.mueller@example.de"
create_contact_if_not_exists "Individual" "François" "Martin" "francois.martin@example.fr"

# Create organizations as regular donors
create_contact_if_not_exists "Organization" "" "" "info@greenpeace.example.org" "Greenpeace Foundation"
create_contact_if_not_exists "Organization" "" "" "contact@amnesty.example.org" "Amnesty International"
create_contact_if_not_exists "Organization" "" "" "info@wwf.example.org" "World Wildlife Fund Europe"

# Create financial types for banking
cv api4 FinancialType.create values='{"name":"Bank Transfer","description":"Direct bank transfers","is_active":true}' match='name' > /dev/null 2>&1 || true
cv api4 FinancialType.create values='{"name":"Standing Order","description":"Regular bank standing orders","is_active":true}' match='name' > /dev/null 2>&1 || true
cv api4 FinancialType.create values='{"name":"Wire Transfer","description":"International wire transfers","is_active":true}' match='name' > /dev/null 2>&1 || true

echo "     ✓ Created 10 sample donor contacts and 3 financial types for banking"
echo ""
echo "     ℹ️  Next steps for CiviBanking:"
echo "        1. Configure bank accounts: /civicrm/banking/manager"
echo "        2. Set up payment plugins (e.g., SEPA matcher)"
echo "        3. Import sample bank statements to test matching"
echo "        4. Configure matcher rules for automatic reconciliation"
