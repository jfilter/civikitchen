#!/bin/bash
set -e

BUILDKIT_DIR="/home/buildkit/buildkit"

echo "==================================="
echo "CiviCRM Buildkit Docker Container"
echo "==================================="

# Check if buildkit is already installed (check for bin directory)
if [ ! -d "$BUILDKIT_DIR/bin" ]; then
    echo "Installing buildkit for the first time..."

    # If buildkit directory exists but is incomplete, initialize git in place
    # This handles the case where volume mounts have created the directory structure
    if [ -d "$BUILDKIT_DIR" ]; then
        echo "Buildkit directory exists, initializing in place..."
        # Fix ownership of the directory (may have been created by Docker with wrong permissions)
        sudo chown -R buildkit:buildkit "$BUILDKIT_DIR"
        cd "$BUILDKIT_DIR"
        git init
        git remote add origin https://github.com/civicrm/civicrm-buildkit.git
        git fetch --depth 1 origin master
        git checkout -f master
    else
        # Clone buildkit repository normally
        git clone https://github.com/civicrm/civicrm-buildkit.git "$BUILDKIT_DIR"
        cd "$BUILDKIT_DIR"
    fi

    # Download buildkit tools
    echo "Downloading buildkit tools..."
    ./bin/civi-download-tools

    # Add buildkit to PATH
    echo 'export PATH="$HOME/buildkit/bin:$PATH"' >> /home/buildkit/.bashrc
    export PATH="$HOME/buildkit/bin:$PATH"

    # Configure amp (non-interactive)
    echo "Configuring amp..."
    mkdir -p /home/buildkit/.amp

    cat > /home/buildkit/.amp/config.yml <<EOF
hosts_type: file
hosts_file_path: /etc/hosts
perm_type: none
httpd_type: apache24
httpd_restart_command: 'sudo apachectl graceful'
httpd_shared_ports:
  - 80
httpd_vhost_dir: /etc/apache2/sites-enabled
httpd_visibility: all
db_type: mysql_dsn
mysql_dsn: mysql://root:${MYSQL_ROOT_PASSWORD}@${MYSQL_HOST}:${MYSQL_PORT}
mysql_type: mycnf
mysql_cnf_path: /home/buildkit/.my.cnf
php_type: detect
redis_type: none
hosts_ip: 127.0.0.1
EOF

    # Create MySQL config file
    cat > /home/buildkit/.my.cnf <<EOF
[client]
host=${MYSQL_HOST}
port=${MYSQL_PORT}
user=root
password=${MYSQL_ROOT_PASSWORD}
EOF

    chmod 600 /home/buildkit/.my.cnf

    # Test amp
    echo "Testing amp configuration..."
    amp test || true

    echo "Buildkit installation complete!"
else
    echo "Buildkit already installed."
    export PATH="$HOME/buildkit/bin:$PATH"
fi

# Wait for MySQL to be ready
echo "===================================="
echo "Waiting for MySQL to be ready..."
echo "===================================="

max_attempts=30
attempt=0
while [ $attempt -lt $max_attempts ]; do
    if mysql -h"${MYSQL_HOST}" -P"${MYSQL_PORT}" -uroot -p"${MYSQL_ROOT_PASSWORD}" -e "SELECT 1" >/dev/null 2>&1; then
        echo "✓ MySQL is ready and accepting connections!"
        break
    fi

    attempt=$((attempt + 1))
    echo "Waiting for MySQL... ($attempt/$max_attempts)"
    sleep 2
done

if [ $attempt -eq $max_attempts ]; then
    echo "✗ MySQL failed to become ready after $max_attempts attempts"
    exit 1
fi

# Configure amp MySQL connection after MySQL is ready
echo "===================================="
echo "Configuring amp MySQL connection..."
echo "===================================="
amp config:set --mysql_dsn="mysql://root:${MYSQL_ROOT_PASSWORD}@${MYSQL_HOST}:${MYSQL_PORT}"
echo "✓ amp MySQL configuration complete!"

# Function to install extension dependencies
install_extension_dependencies() {
    echo "===================================="
    echo "Checking for extension dependencies..."
    echo "===================================="

    EXT_DIR="/home/buildkit/buildkit/build/site/web/sites/default/files/civicrm/ext"

    if [ ! -d "$EXT_DIR" ]; then
        echo "Extensions directory not found, skipping dependency installation"
        return 0
    fi

    # Find all civikitchen.json files
    for config_file in "$EXT_DIR"/*/civikitchen.json; do
        if [ ! -f "$config_file" ]; then
            continue
        fi

        echo "Found dependency config: $config_file"
        EXTENSION_DIR=$(dirname "$config_file")
        EXTENSION_NAME=$(basename "$EXTENSION_DIR")

        # Parse and install each dependency
        # Use jq to parse JSON (already available in buildkit)
        DEPS=$(cat "$config_file" | jq -r '.dependencies[]? | @json')

        if [ -z "$DEPS" ]; then
            echo "  No dependencies found in $EXTENSION_NAME"
            continue
        fi

        echo "$DEPS" | while IFS= read -r dep; do
            DEP_NAME=$(echo "$dep" | jq -r '.name')
            DEP_REPO=$(echo "$dep" | jq -r '.repo')
            DEP_VERSION=$(echo "$dep" | jq -r '.version')
            DEP_ENABLE=$(echo "$dep" | jq -r '.enable // true')
            DEP_SEED=$(echo "$dep" | jq -r '.seed // false')

            DEP_PATH="$EXT_DIR/$DEP_NAME"

            # Check if dependency already exists
            if [ -d "$DEP_PATH" ]; then
                echo "  ✓ Dependency $DEP_NAME already installed, skipping"
                continue
            fi

            echo "  Installing dependency: $DEP_NAME @ $DEP_VERSION"

            # Clone the repository
            cd "$EXT_DIR"
            if ! git clone "$DEP_REPO" "$DEP_NAME" 2>/dev/null; then
                echo "  ✗ Failed to clone $DEP_NAME from $DEP_REPO"
                continue
            fi

            # Checkout specified version
            cd "$DEP_PATH"
            if ! git checkout "$DEP_VERSION" 2>/dev/null; then
                echo "  ⚠ Warning: Could not checkout version $DEP_VERSION for $DEP_NAME"
            fi

            echo "  ✓ Installed $DEP_NAME"

            # Enable the extension if requested
            if [ "$DEP_ENABLE" = "true" ]; then
                cd /home/buildkit/buildkit/build/site/web
                # Wait for CiviCRM to be ready (check if settings file exists)
                if [ ! -f "sites/default/civicrm.settings.php" ]; then
                    echo "  ⚠ CiviCRM not yet ready, skipping enable for $DEP_NAME"
                elif cv ext:enable "$DEP_NAME" 2>&1 | tee /tmp/cv-enable-$DEP_NAME.log | grep -q "Enabling extension"; then
                    echo "  ✓ Enabled $DEP_NAME"
                else
                    echo "  ⚠ Could not enable $DEP_NAME (check logs: /tmp/cv-enable-$DEP_NAME.log)"
                fi
            fi

            # Track for seeding later (store in temp file)
            if [ "$DEP_SEED" != "false" ]; then
                echo "$DEP_NAME|$DEP_SEED" >> /tmp/extensions_to_seed.txt
            fi
        done
    done

    echo "✓ Dependency installation complete!"
}

# Function to run extension seeding
run_extension_seeding() {
    echo "===================================="
    echo "Running extension seeding..."
    echo "===================================="

    EXT_DIR="/home/buildkit/buildkit/build/site/web/sites/default/files/civicrm/ext"

    if [ ! -d "$EXT_DIR" ]; then
        echo "Extensions directory not found, skipping seeding"
        return 0
    fi

    # Flush cache to ensure extension list is current and prevent stale cache issues
    cd /home/buildkit/buildkit/build/site/web 2>/dev/null && cv flush 2>/dev/null || true

    # Source the seed loader script (modular seeding system)
    if [ -f "/home/buildkit/scripts/lib/seed-loader.sh" ]; then
        source /home/buildkit/scripts/lib/seed-loader.sh
    elif [ -f "/home/buildkit/scripts/lib/seed-common-extensions.sh" ]; then
        # Fallback to monolithic script for backward compatibility
        source /home/buildkit/scripts/lib/seed-common-extensions.sh
    elif [ -f "/seed-common-extensions.sh" ]; then
        # Fallback to old location for backward compatibility
        source /seed-common-extensions.sh
    fi

    # Seed extensions based on dependency configurations (from temp file)
    if [ -f "/tmp/extensions_to_seed.txt" ]; then
        while IFS='|' read -r ext_name seed_type; do
            SEED_MARKER="/tmp/.civicrm-seeded-$ext_name"

            # Check if already seeded (run once)
            if [ -f "$SEED_MARKER" ]; then
                echo "  ✓ $ext_name already seeded, skipping"
                continue
            fi

            if [ "$seed_type" = "true" ]; then
                # Use built-in seeding
                echo "  Running built-in seeding for $ext_name..."
                if declare -f seed_extension > /dev/null; then
                    seed_extension "$ext_name"
                    touch "$SEED_MARKER"
                else
                    echo "  ⚠️  Built-in seeding not available"
                fi
            elif [ "$seed_type" = "custom" ] || [ "$seed_type" != "false" ]; then
                # Custom seed script (look for it in extension directory)
                echo "  Running custom seeding for $ext_name..."
                EXT_PATH="$EXT_DIR/$ext_name"
                if [ -f "$EXT_PATH/seed.sh" ]; then
                    chmod +x "$EXT_PATH/seed.sh"
                    if bash "$EXT_PATH/seed.sh"; then
                        echo "  ✓ Custom seeding completed for $ext_name"
                        touch "$SEED_MARKER"
                    else
                        echo "  ✗ Custom seeding failed for $ext_name"
                    fi
                else
                    echo "  ⚠️  Custom seed script not found: $EXT_PATH/seed.sh"
                fi
            fi
        done < /tmp/extensions_to_seed.txt

        # Clean up temp file
        rm -f /tmp/extensions_to_seed.txt
    fi

    # Also support old-style seeding config for backward compatibility
    for config_file in "$EXT_DIR"/*/civikitchen.json; do
        if [ ! -f "$config_file" ]; then
            continue
        fi

        EXTENSION_DIR=$(dirname "$config_file")
        EXTENSION_NAME=$(basename "$EXTENSION_DIR")

        # Check if seeding is enabled (old format)
        SEED_ENABLED=$(cat "$config_file" | jq -r '.seeding.enabled // false')

        if [ "$SEED_ENABLED" != "true" ]; then
            continue
        fi

        SEED_SCRIPT=$(cat "$config_file" | jq -r '.seeding.script // ""')
        RUN_ONCE=$(cat "$config_file" | jq -r '.seeding.runOnce // true')
        SEED_MARKER="$EXTENSION_DIR/.civicrm-seeded"

        if [ -z "$SEED_SCRIPT" ]; then
            echo "  ⚠ Seeding enabled for $EXTENSION_NAME but no script specified"
            continue
        fi

        # Check if already seeded
        if [ "$RUN_ONCE" = "true" ] && [ -f "$SEED_MARKER" ]; then
            echo "  ✓ $EXTENSION_NAME already seeded (runOnce=true), skipping"
            continue
        fi

        SEED_PATH="$EXTENSION_DIR/$SEED_SCRIPT"

        if [ ! -f "$SEED_PATH" ]; then
            echo "  ✗ Seed script not found: $SEED_PATH"
            continue
        fi

        echo "  Running seed script for $EXTENSION_NAME..."

        # Make script executable and run it
        chmod +x "$SEED_PATH"
        if bash "$SEED_PATH"; then
            echo "  ✓ Seeding completed for $EXTENSION_NAME"

            # Create marker file if runOnce is true
            if [ "$RUN_ONCE" = "true" ]; then
                touch "$SEED_MARKER"
            fi
        else
            echo "  ✗ Seeding failed for $EXTENSION_NAME"
        fi
    done

    echo "✓ Extension seeding complete!"
}

# Auto-create CiviCRM site if requested
if [ -n "${CIVICRM_SITE_TYPE}" ] && [ "${CIVICRM_SITE_TYPE}" != "false" ]; then
    SITE_DIR="/home/buildkit/buildkit/build/site"
    SITE_URL="http://${HTTPD_DOMAIN}:${HTTPD_PORT}"

    # Check if site is actually installed (not just directory exists)
    # Volume mounts may create the directory structure before site installation
    if [ ! -f "$SITE_DIR/web/index.php" ]; then
        echo "===================================="
        echo "Auto-creating CiviCRM site"
        echo "Site type: ${CIVICRM_SITE_TYPE}"
        echo "URL: $SITE_URL"
        if [ -n "${CIVICRM_VERSION}" ]; then
            echo "CiviCRM version: ${CIVICRM_VERSION}"
        fi
        echo "===================================="

        # Note: We don't use --force to avoid issues with mount points
        # Civibuild will use existing site directory if it exists
        if [ -n "${CIVICRM_VERSION}" ]; then
            civibuild create site --type "${CIVICRM_SITE_TYPE}" --url "$SITE_URL" --civi-ver "${CIVICRM_VERSION}"
        else
            civibuild create site --type "${CIVICRM_SITE_TYPE}" --url "$SITE_URL"
        fi

        echo "===================================="
        echo "Site creation complete!"
        echo "Access your site at: http://localhost:${HTTPD_PORT}"
        echo "===================================="

        # Set up extensions directory symlink
        echo "===================================="
        echo "Setting up extensions directory..."
        echo "===================================="

        EXT_TARGET="/home/buildkit/buildkit/build/site/web/sites/default/files/civicrm/ext"
        EXT_MOUNT="/home/buildkit/extensions-mount"

        # Create parent directory if it doesn't exist
        mkdir -p "$(dirname "$EXT_TARGET")"

        # If ext directory exists as a regular directory, remove it
        if [ -d "$EXT_TARGET" ] && [ ! -L "$EXT_TARGET" ]; then
            rm -rf "$EXT_TARGET"
        fi

        # Create symlink from site to mount point
        if [ ! -L "$EXT_TARGET" ]; then
            ln -s "$EXT_MOUNT" "$EXT_TARGET"
            echo "✓ Created symlink: $EXT_TARGET -> $EXT_MOUNT"
        else
            echo "✓ Symlink already exists"
        fi

        # Install extension dependencies and run seeding
        install_extension_dependencies
        run_extension_seeding

        # Setup API users with different permission levels
        echo "===================================="
        echo "Setting up API users..."
        echo "===================================="
        STACK_NAME="${STACK:-eu-nonprofit}"
        if [ -f "/home/buildkit/scripts/lib/configure-api-users-from-json.sh" ]; then
            bash /home/buildkit/scripts/lib/configure-api-users-from-json.sh "/config/${STACK_NAME}/civikitchen.json"
        fi
        echo "✓ API users setup complete!"
    else
        echo "Site already exists, skipping creation."

        # Set up extensions directory symlink
        echo "===================================="
        echo "Setting up extensions directory..."
        echo "===================================="

        EXT_TARGET="/home/buildkit/buildkit/build/site/web/sites/default/files/civicrm/ext"
        EXT_MOUNT="/home/buildkit/extensions-mount"

        # Create parent directory if it doesn't exist
        mkdir -p "$(dirname "$EXT_TARGET")"

        # If ext directory exists as a regular directory, remove it
        if [ -d "$EXT_TARGET" ] && [ ! -L "$EXT_TARGET" ]; then
            rm -rf "$EXT_TARGET"
        fi

        # Create symlink from site to mount point
        if [ ! -L "$EXT_TARGET" ]; then
            ln -s "$EXT_MOUNT" "$EXT_TARGET"
            echo "✓ Created symlink: $EXT_TARGET -> $EXT_MOUNT"
        else
            echo "✓ Symlink already exists"
        fi

        # Still check for new dependencies and seeding even if site exists
        install_extension_dependencies
        run_extension_seeding

        # Setup API users with different permission levels
        echo "===================================="
        echo "Setting up API users..."
        echo "===================================="
        STACK_NAME="${STACK:-eu-nonprofit}"
        if [ -f "/home/buildkit/scripts/lib/configure-api-users-from-json.sh" ]; then
            bash /home/buildkit/scripts/lib/configure-api-users-from-json.sh "/config/${STACK_NAME}/civikitchen.json"
        fi
        echo "✓ API users setup complete!"
    fi
fi

# Configure static Apache vhost for the single site
echo "Configuring Apache for single-site setup..."

sudo tee /etc/apache2/sites-available/000-default.conf > /dev/null <<EOF
<VirtualHost *:80>
    ServerAdmin webmaster@localhost
    ServerName ${HTTPD_DOMAIN}
    DocumentRoot /home/buildkit/buildkit/build/site/web

    <Directory /home/buildkit/buildkit/build/site/web>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Fallback for when site doesn't exist yet
    <Directory /home/buildkit>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOF

echo "==================================="
echo "Starting Apache..."
echo "==================================="
echo ""
echo "Services available at:"
echo "  - CiviCRM site: http://localhost:${HTTPD_PORT}"
echo "  - PHPMyAdmin: http://localhost:8081"
echo "  - Maildev: http://localhost:1080"
echo ""
if [ -z "${CIVICRM_SITE_TYPE}" ] || [ "${CIVICRM_SITE_TYPE}" = "false" ]; then
    echo "No auto-site configured. To create a site manually:"
    echo "  docker-compose exec civicrm civibuild create site --type drupal10-demo --url http://${HTTPD_DOMAIN}"
    echo ""
fi
echo "To access the container shell:"
echo "  docker-compose exec civicrm bash"
echo ""

# Start Apache in foreground
exec sudo apachectl -D FOREGROUND
