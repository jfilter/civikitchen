#!/bin/bash
# Seeding script for Contract Extension (de.systopia.contract)

set -e
cd /home/buildkit/buildkit/build/site/web

echo "  📋 Seeding Contract extension with comprehensive sample data..."

create_contact_if_not_exists() {
    local contact_type="$1"; local first_name="$2"; local last_name="$3"; local email="$4"
    existing=$(cv api4 Contact.get +w contact_type="$contact_type" +w email="$email" +l 1 --out=json 2>/dev/null | jq -r '.[] | length' || echo "0")
    if [ "$existing" -eq 0 ]; then
        cv api4 Contact.create values="{\"contact_type\":\"$contact_type\",\"first_name\":\"$first_name\",\"last_name\":\"$last_name\",\"email\":\"$email\"}" > /dev/null 2>&1 || true
        echo "     ✓ Created contact: $first_name $last_name ($email)"
    fi
}

# Create financial types for contracts
cv api4 FinancialType.create values='{"name":"Membership Contract","description":"Recurring membership contracts","is_active":true}' match='name' > /dev/null 2>&1 || true
cv api4 FinancialType.create values='{"name":"Supporter Contract","description":"Regular supporter agreements","is_active":true}' match='name' > /dev/null 2>&1 || true
cv api4 FinancialType.create values='{"name":"Sustainer Contract","description":"Monthly sustainer programs","is_active":true}' match='name' > /dev/null 2>&1 || true

# Create sample contacts for contracts
create_contact_if_not_exists "Individual" "Emma" "Weber" "emma.weber@example.de"
create_contact_if_not_exists "Individual" "Luca" "Romano" "luca.romano@example.it"
create_contact_if_not_exists "Individual" "Clara" "Dupont" "clara.dupont@example.fr"
create_contact_if_not_exists "Individual" "Oliver" "Hansen" "oliver.hansen@example.dk"

echo "     ✓ Created contract financial types and 4 sample members"
echo ""
echo "     ℹ️  Next steps: Configure contract settings and membership types"
