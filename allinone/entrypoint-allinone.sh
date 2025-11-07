#!/bin/bash
set -e

echo "========================================"
echo "CiviCRM EU-NGO All-In-One Container"
echo "========================================"
echo ""

# Flag file to track if site has been created
SITE_CREATED_FLAG="/home/buildkit/.site-created"
DB_IMPORTED_FLAG="/home/buildkit/.db-imported"
DB_DUMP_FILE="/home/buildkit/civicrm-initial.sql"

# Start MariaDB
echo "Starting MariaDB..."
sudo service mariadb start
sleep 3

# Initialize MariaDB root password if first run
if [ ! -f "/var/lib/mysql/.mysql_initialized" ]; then
    echo "Initializing MariaDB..."
    sudo mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED BY 'root';" || true
    sudo touch /var/lib/mysql/.mysql_initialized
fi

# Check if we have a pre-built database to import
if [ -f "$DB_DUMP_FILE" ] && [ ! -f "$DB_IMPORTED_FLAG" ]; then
    echo "=========================================="
    echo "Found pre-built database! Importing..."
    echo "=========================================="
    echo ""

    # Import the database
    mysql -u root -proot < "$DB_DUMP_FILE"

    # Flush privileges (the database dump already has the correct authentication settings)
    mysql -u root -proot -e "FLUSH PRIVILEGES;"

    # Mark as imported
    touch "$DB_IMPORTED_FLAG"
    touch "$SITE_CREATED_FLAG"

    echo "✓ Database imported successfully"
    echo ""
fi

# If site not created yet, create it with civibuild
if [ ! -f "$SITE_CREATED_FLAG" ]; then
    echo "=========================================="
    echo "First run - Creating CiviCRM site..."
    echo "This will take 5-10 minutes."
    echo "=========================================="
    echo ""

    # Fix git ownership issues
    git config --global --add safe.directory /home/buildkit/buildkit
    git config --global --add safe.directory /home/buildkit/buildkit/build/site

    # Configure AMP
    echo "Configuring buildkit..."
    cd /home/buildkit/buildkit
    /home/buildkit/buildkit/bin/amp config:set \
        --mysql_type='dsn' \
        --mysql_dsn='mysql://root:root@127.0.0.1:3306' \
        --httpd_type=apache24 \
        --httpd_restart_command='sudo apachectl graceful' \
        --hosts_type=file \
        --perm_type=none

    # Create site
    echo ""
    echo "Creating CiviCRM site with buildkit..."
    echo "(This may take several minutes...)"
    echo ""

    /home/buildkit/buildkit/bin/civibuild create site \
        --type "${CIVICRM_SITE_TYPE}" \
        --civi-ver "${CIVICRM_VERSION}" \
        --url http://localhost:80 \
        --admin-pass "${CIVIBUILD_ADMIN_PASS}"

    if [ $? -eq 0 ]; then
        echo ""
        echo "✓ Site created successfully"

        # Clone and enable extensions
        echo ""
        echo "Setting up EU-NGO extensions..."

        EXT_DIR="/home/buildkit/buildkit/build/site/web/sites/default/files/civicrm/ext"
        JSON_FILE="/home/buildkit/stacks/eu-nonprofit/civikitchen.json"

        mkdir -p "$EXT_DIR"
        cd "$EXT_DIR"

        # Clone extensions (disable exit on error temporarily)
        set +e
        cat "$JSON_FILE" | jq -r '.dependencies[] | "\(.repo)|\(.name)|\(.version)"' | while IFS='|' read -r repo name version; do
            if [ ! -d "$name" ]; then
                echo "  Cloning $name @ $version"
                if git clone "$repo" "$name" 2>&1 | grep -E "(error:|fatal:|✓)" || true; then
                    if [ -d "$name" ]; then
                        cd "$name"
                        git checkout "$version" 2>/dev/null || echo "  Using default branch for $name"
                        cd "$EXT_DIR"
                    fi
                else
                    echo "  ✗ Failed to clone $name"
                fi
            fi
        done
        set -e

        # Enable extensions
        echo ""
        echo "Enabling extensions..."
        cd /home/buildkit/buildkit/build/site/web

        set +e
        /home/buildkit/buildkit/bin/cv ext:enable org.project60.banking org.project60.sepa || echo "  Warning: Some extensions may already be enabled"
        /home/buildkit/buildkit/bin/cv ext:enable de.systopia.contract de.systopia.twingle || echo "  Warning: Some extensions may already be enabled"
        /home/buildkit/buildkit/bin/cv ext:enable de.systopia.gdprx de.systopia.xcm || echo "  Warning: Some extensions may already be enabled"
        /home/buildkit/buildkit/bin/cv ext:enable de.systopia.identitytracker || echo "  Warning: Some extensions may already be enabled"
        /home/buildkit/buildkit/bin/cv ext:enable org.civicrm.shoreditch org.civicrm.contactlayout || echo "  Warning: Some extensions may already be enabled"
        set -e

        # Run seeding scripts
        echo ""
        echo "Seeding extension data..."
        for seed_script in /home/buildkit/scripts/lib/seeds/*.sh; do
            if [ -f "$seed_script" ]; then
                echo "  Running $(basename $seed_script)"
                bash "$seed_script" 2>&1 | grep -E "(✓|✗|Seeding|Creating)" || true
            fi
        done

        /home/buildkit/buildkit/bin/cv flush

        echo ""
        echo "✓ Extensions configured"

        # Mark site as created
        touch "$SITE_CREATED_FLAG"

        echo ""
        echo "=========================================="
        echo "✓ CiviCRM site ready!"
        echo "=========================================="
    else
        echo ""
        echo "✗ Site creation failed"
        exit 1
    fi
else
    echo "Site already created, starting services..."
fi

# Start Apache
echo ""
echo "Starting Apache..."
sudo apachectl start

echo ""
echo "=========================================="
echo "✓ CiviCRM is ready!"
echo "=========================================="
echo ""
echo "Access at: http://localhost"
echo "Username: admin"
echo "Password: admin"
echo ""
echo "Extensions installed:"
echo "  - org.project60.banking"
echo "  - org.project60.sepa"
echo "  - de.systopia.contract"
echo "  - de.systopia.twingle"
echo "  - de.systopia.gdprx"
echo "  - de.systopia.xcm"
echo "  - de.systopia.identitytracker"
echo "  - org.civicrm.shoreditch"
echo "  - org.civicrm.contactlayout"
echo ""

# Keep container running
tail -f /var/log/apache2/access.log /var/log/apache2/error.log
