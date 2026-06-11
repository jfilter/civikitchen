#!/bin/bash
# Configure API users from profile.json (apiUsers + authx sections).
#
# CMS-agnostic driver: the shared steps (AuthX settings, CiviCRM contacts,
# API keys, credentials file) run through cv; only user/role creation goes
# through a small per-UF adapter — drush on Drupal, wp-cli on WordPress, the
# standaloneusers User/Role APIv4 entities on Standalone.

set -e

# civibuild layout if present (demo + buildkit dev images); the standalone
# dev image has cv on the global PATH and resolves the site via env, so a
# cd is only needed where the civibuild tree exists.
[[ -d /home/buildkit/buildkit/bin ]] && export PATH="/home/buildkit/buildkit/bin:${PATH}"
[[ -d /home/buildkit/buildkit/build/site/web ]] && cd /home/buildkit/buildkit/build/site/web

CONFIG_FILE="${1:-/config/civikitchen.json}"

if [[ ! -f "${CONFIG_FILE}" ]]; then
    echo "  ⚠️  No profile config found, skipping API user configuration"
    exit 0
fi

# Check if apiUsers section exists
if ! jq -e '.apiUsers' "${CONFIG_FILE}" > /dev/null 2>&1; then
    echo "  ℹ️  No apiUsers configured"
    exit 0
fi

# Which user framework backs this site: Drupal8 (= Drupal 9/10/11),
# WordPress, or Standalone. Everything CMS-specific branches on this.
UF="$(cv ev 'echo CRM_Core_Config::singleton()->userFramework;')"
case "${UF}" in
    Drupal8|WordPress|Standalone) ;;
    *)
        echo "  ERROR: unsupported user framework '${UF}' for API user creation" >&2
        exit 1
        ;;
esac

echo "  🔑 Configuring API access (${UF})..."

# CiviCRM permission → WordPress capability, the same mapping core applies in
# CRM_Core_Permission_WordPress::check(): munge(strtolower($perm)) — every
# char outside [a-z0-9_] becomes an underscore.
wp_cap() {
    printf '%s' "$1" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9_]/_/g'
}

# === Configure AuthX ===
echo "     Configuring AuthX extension..."

# Set authx_header_cred (default: ["jwt", "api_key", "pass"])
header_cred=$(jq -r '.authx.header_cred // ["jwt", "api_key", "pass"] | @json' "${CONFIG_FILE}")
cv ev "\\Civi::settings()->set(\"authx_header_cred\", ${header_cred});" 2>/dev/null || true

# Make CiviCRM's permission list known to the CMS before granting any.
cv api System.flush 2>/dev/null || true

case "${UF}" in
    Drupal8)
        # "authenticate with password" is required for authx with perm guard.
        echo "     Adding authentication permissions..."
        drush role:perm:add authenticated 'authenticate with password' 2>/dev/null || true
        drush role:perm:add administrator 'authenticate with password' 2>/dev/null || true

        # Grant all CiviCRM permissions to administrator role
        echo "     Granting CiviCRM permissions to administrator role..."
        drush role:perm:add administrator 'access civicrm' 2>/dev/null || true
        drush role:perm:add administrator 'administer civicrm' 2>/dev/null || true

        # Reset passwords for built-in users and update civibuild config
        echo "     Resetting passwords for built-in users..."
        drush user:password admin 'admin' 2>/dev/null || true
        drush user:password demo 'demo' 2>/dev/null || true
        ;;
    WordPress)
        echo "     Adding authentication capability to administrator..."
        wp cap add administrator authenticate_with_password 2>/dev/null || true
        echo "     Resetting passwords for built-in users..."
        wp user update admin --user_pass=admin --skip-email 2>/dev/null || true
        wp user update demo --user_pass=demo --skip-email 2>/dev/null || true
        ;;
    Standalone)
        # Built-in admin role already carries every permission; the per-user
        # roles below get "authenticate with password" added explicitly.
        :
        ;;
esac

# Update civibuild site config to reflect new passwords
if [[ -f "/home/buildkit/buildkit/build/site.sh" ]]; then
  echo "     Updating civibuild site configuration..."
  sed -i 's/^ADMIN_PASS=.*/ADMIN_PASS="admin"/' /home/buildkit/buildkit/build/site.sh 2>/dev/null || true
  sed -i 's/^DEMO_PASS=.*/DEMO_PASS="demo"/' /home/buildkit/buildkit/build/site.sh 2>/dev/null || true
fi

# === Process API Users ===
echo "  👥 Creating API users..."

# Function to generate a secure random API key (32 characters)
generate_api_key() {
    if openssl rand -hex 16 2>/dev/null; then
        return 0
    else
        head -c 16 /dev/urandom | xxd -p || true
    fi
}

# Function to derive email from username
get_email() {
    local username="$1"
    echo "${username}@example.org"
}

# Function to derive first/last name from username
get_first_name() {
    local username="$1"
    echo "${username}" | awk '{print toupper(substr($0,1,1)) tolower(substr($0,2))}'
}

get_last_name() {
    echo "User"
}

# Credentials are kept in the container so they stay retrievable after the
# log output scrolls away: docker exec <c> cat /home/buildkit/api-credentials.txt
API_KEYS_FILE="${HOME:-/home/buildkit}/api-credentials.txt"
true > "${API_KEYS_FILE}"
chmod 600 "${API_KEYS_FILE}" 2>/dev/null || true

# Get number of users
user_count=$(jq -r '.apiUsers | length' "${CONFIG_FILE}")

# Process each user
for i in $(seq 0 $((user_count - 1))); do
    username=$(jq -r ".apiUsers[${i}].username" "${CONFIG_FILE}")
    role=$(jq -r ".apiUsers[${i}].role" "${CONFIG_FILE}")

    echo "     Processing user: ${username} (role: ${role})"

    # Derive fields
    password="${username}"
    # shellcheck disable=SC2311
    email=$(get_email "${username}")
    # shellcheck disable=SC2311
    first_name=$(get_first_name "${username}")
    # shellcheck disable=SC2311
    last_name=$(get_last_name)

    # Create CiviCRM contact
    contact_result=$(cv api4 Contact.create values="{\"contact_type\":\"Individual\",\"first_name\":\"${first_name}\",\"last_name\":\"${last_name}\",\"email\":\"${email}\"}" --out=json 2>/dev/null || echo '[]')
    contact_id=$(echo "${contact_result}" | jq -r '.[0].id // empty')

    uid=""
    case "${UF}" in
        Drupal8)
            # Create custom Drupal role if it doesn't exist
            drush role:create "${role}" "${role}" 2>/dev/null || true

            # Create Drupal user
            drush user:create "${username}" --mail="${email}" --password="${password}" 2>/dev/null || true

            # Assign role
            drush user:role:add "${role}" "${username}" 2>/dev/null || true

            # Reset password (ensures it works for authentication)
            drush user:password "${username}" "${password}" 2>/dev/null || true

            # Assign permissions (Civi permission names are registered with Drupal)
            jq -r ".apiUsers[${i}].permissions[]" "${CONFIG_FILE}" | while IFS= read -r permission; do
                [[ -n "${permission}" ]] && drush role:perm:add "${role}" "${permission}" 2>/dev/null || true
            done

            USER_INFO=$(drush user:information "${username}" --format=json 2>/dev/null || true)
            uid=$(echo "${USER_INFO}" | jq -r 'to_entries[0].value.uid // empty' || echo "")
            ;;
        WordPress)
            wp role create "${role}" "${role}" 2>/dev/null || true
            wp user create "${username}" "${email}" --user_pass="${password}" --role="${role}" 2>/dev/null || true
            wp user update "${username}" --user_pass="${password}" --skip-email 2>/dev/null || true

            # authx perm guard + the profile's permissions, as WP capabilities
            wp cap add "${role}" authenticate_with_password 2>/dev/null || true
            jq -r ".apiUsers[${i}].permissions[]" "${CONFIG_FILE}" | while IFS= read -r permission; do
                # shellcheck disable=SC2311
                [[ -n "${permission}" ]] && wp cap add "${role}" "$(wp_cap "${permission}")" 2>/dev/null || true
            done

            uid=$(wp user get "${username}" --field=ID 2>/dev/null || echo "")
            ;;
        Standalone)
            # Role + user via the standaloneusers APIv4 entities. save+match
            # keeps re-runs idempotent; "password" is a write-only field that
            # gets hashed on save; "roles" wants role IDs. The User row IS the
            # uf_match record on Standalone, so no separate UFMatch below.
            perms_json=$(jq -c ".apiUsers[${i}].permissions + [\"authenticate with password\"]" "${CONFIG_FILE}")
            role_id=$(cv api4 Role.save "{\"records\":[{\"name\":\"${role}\",\"label\":\"${role}\",\"permissions\":${perms_json},\"is_active\":true}],\"match\":[\"name\"]}" --out=json | jq -r '.[0].id // empty')
            if [[ -n "${role_id}" && -n "${contact_id}" ]]; then
                uid=$(cv api4 User.save "{\"records\":[{\"username\":\"${username}\",\"uf_name\":\"${email}\",\"contact_id\":${contact_id},\"password\":\"${password}\",\"roles\":[${role_id}],\"is_active\":true}],\"match\":[\"username\"]}" --out=json | jq -r '.[0].id // empty')
            fi
            [[ -n "${uid}" ]] || echo "     WARN: could not create standalone user '${username}'" >&2
            ;;
    esac

    # Create UFMatch record (link CMS user to CiviCRM contact); Standalone
    # maintains this itself via the User entity.
    if [[ "${UF}" != "Standalone" && -n "${uid}" && -n "${contact_id}" ]]; then
        cv api4 UFMatch.create values="{\"uf_id\":${uid},\"contact_id\":${contact_id},\"uf_name\":\"${username}\"}" --out=json 2>/dev/null || true
    fi

    # Generate API key
    if [[ -n "${contact_id}" ]]; then
        # shellcheck disable=SC2311
        api_key="${role}_$(generate_api_key)"
        cv api4 Contact.update values="{\"id\":${contact_id},\"api_key\":\"${api_key}\"}" > /dev/null 2>&1 || true
        echo "${username}:${password}:${api_key}" >> "${API_KEYS_FILE}"
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
    printf "%-15s | Username: %-12s | Password: %-12s\n" "User" "${username}" "${password}"
    printf "%-15s | API Key:  %s\n" "" "${api_key}"
    echo ""
done < "${API_KEYS_FILE}"

echo "=========================================="
echo ""
echo "💡 Use these credentials for API testing:"
echo ""
echo "   Example curl request (APIv4):"
# shellcheck disable=SC2016,SC1003
cat << 'EOF'
   curl -X POST "http://localhost/civicrm/ajax/api4/Contact/get" \
     -H "Authorization: Basic $(echo -n username:password | base64)" \
     -H "X-Requested-With: XMLHttpRequest" \
     --data-urlencode 'params={"limit":5}'
EOF
echo ""
echo "=========================================="

# Flush caches so new roles/permissions take effect
echo ""
echo "     Flushing caches..."
case "${UF}" in
    Drupal8)   drush cache:rebuild 2>/dev/null || true ;;
    WordPress) wp cache flush 2>/dev/null || true ;;
    Standalone) : ;;
esac
cv flush 2>/dev/null || true

echo "     Credentials saved to ${API_KEYS_FILE}"
