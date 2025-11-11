#!/bin/bash
# Configure API users from civikitchen.json
#
# This script reads the apiUsers configuration from civikitchen.json
# and creates Drupal users, CiviCRM contacts, assigns permissions,
# and generates API keys based on the declarative configuration.

set -e

cd /home/buildkit/buildkit/build/site/web

CONFIG_FILE="${1:-/config/civikitchen.json}"

if [ ! -f "$CONFIG_FILE" ]; then
    echo "  ⚠️  No civikitchen.json found, skipping API user configuration"
    exit 0
fi

# Check if apiUsers section exists
if ! jq -e '.apiUsers' "$CONFIG_FILE" > /dev/null 2>&1; then
    echo "  ℹ️  No apiUsers configured in civikitchen.json"
    exit 0
fi

echo "  🔑 Configuring API access from civikitchen.json..."

# === Configure AuthX ===
echo "     Configuring AuthX extension..."

# Set authx_header_cred (default: ["jwt", "api_key", "pass"])
header_cred=$(jq -r '.authx.header_cred // ["jwt", "api_key", "pass"] | @json' "$CONFIG_FILE")
cv ev "\\Civi::settings()->set(\"authx_header_cred\", $header_cred);" 2>/dev/null || true

# Add "authenticate with password" permission (required for authx with perm guard)
echo "     Adding authentication permissions..."
drush role:perm:add authenticated 'authenticate with password' 2>/dev/null || true
drush role:perm:add administrator 'authenticate with password' 2>/dev/null || true

# Reset passwords for built-in users (admin, demo)
echo "     Resetting passwords for built-in users..."
drush user:password admin 'admin' 2>/dev/null || true
drush user:password demo 'demo' 2>/dev/null || true

# === Process API Users ===
echo "  👥 Creating API users..."

# Function to generate a secure random API key (32 characters)
generate_api_key() {
    openssl rand -hex 16 2>/dev/null || head -c 16 /dev/urandom | xxd -p
}

# Function to derive email from username
get_email() {
    local username="$1"
    echo "${username}@example.org"
}

# Function to derive first/last name from username
get_first_name() {
    local username="$1"
    echo "$username" | awk '{print toupper(substr($0,1,1)) tolower(substr($0,2))}'
}

get_last_name() {
    echo "User"
}

# Temporary file to store API keys
API_KEYS_FILE="/tmp/api-keys.txt"
> "$API_KEYS_FILE"

# Get number of users
user_count=$(jq -r '.apiUsers | length' "$CONFIG_FILE")

# Process each user
for i in $(seq 0 $(($user_count - 1))); do
    username=$(jq -r ".apiUsers[$i].username" "$CONFIG_FILE")
    role=$(jq -r ".apiUsers[$i].role" "$CONFIG_FILE")

    echo "     Processing user: $username (role: $role)"

    # Derive fields
    password="$username"
    email=$(get_email "$username")
    first_name=$(get_first_name "$username")
    last_name=$(get_last_name)

    # Create custom Drupal role if it doesn't exist
    drush role:create "$role" "$role" 2>/dev/null || true

    # Create Drupal user
    drush user:create "$username" --mail="$email" --password="$password" 2>/dev/null || true

    # Assign role
    drush user:role:add "$role" "$username" 2>/dev/null || true

    # Reset password (ensures it works for authentication)
    drush user:password "$username" "$password" 2>/dev/null || true

    # Create CiviCRM contact
    contact_result=$(cv api4 Contact.create values="{\"contact_type\":\"Individual\",\"first_name\":\"$first_name\",\"last_name\":\"$last_name\",\"email\":\"$email\"}" --out=json 2>/dev/null || echo '[]')
    contact_id=$(echo "$contact_result" | jq -r '.[0].id // empty')

    # Get Drupal user ID
    uid=$(drush user:information "$username" --format=json 2>/dev/null | jq -r 'to_entries[0].value.uid // empty' || echo "")

    # Create UFMatch record (link Drupal user to CiviCRM contact)
    if [ -n "$uid" ] && [ -n "$contact_id" ]; then
        cv api4 UFMatch.create values="{\"uf_id\":$uid,\"contact_id\":$contact_id,\"uf_name\":\"$username\"}" --out=json 2>/dev/null || true
    fi

    # Assign permissions
    permissions=$(jq -r ".apiUsers[$i].permissions[]" "$CONFIG_FILE")
    while IFS= read -r permission; do
        [ -n "$permission" ] && drush role:perm:add "$role" "$permission" 2>/dev/null || true
    done <<< "$permissions"

    # Generate API key
    if [ -n "$contact_id" ]; then
        api_key="${role}_$(generate_api_key)"
        cv api4 Contact.update values="{\"id\":$contact_id,\"api_key\":\"$api_key\"}" > /dev/null 2>&1 || true
        echo "$username:$password:$api_key" >> "$API_KEYS_FILE"
    fi
done

echo "     ✓ API users configured successfully"
echo ""
echo "=========================================="
echo "API User Credentials"
echo "=========================================="
echo ""

# Display all credentials in a formatted table
while IFS=: read -r username password api_key; do
    printf "%-15s | Username: %-12s | Password: %-12s\n" "User" "$username" "$password"
    printf "%-15s | API Key:  %s\n" "" "$api_key"
    echo ""
done < "$API_KEYS_FILE"

echo "=========================================="
echo ""
echo "💡 Use these credentials for API testing:"
echo ""
echo "   Example curl request (APIv4):"
echo '   curl -X POST "http://localhost/civicrm/ajax/api4/Contact/get" \'
echo '     -H "Authorization: Basic $(echo -n username:password | base64)" \'
echo '     -H "X-Requested-With: XMLHttpRequest" \'
echo "     --data-urlencode 'params={\"limit\":5}'"
echo ""
echo "=========================================="

# Cleanup
rm -f "$API_KEYS_FILE"
