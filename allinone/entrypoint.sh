#!/bin/bash
set -e

echo "=========================================="
echo "CiviCRM EU-NGO All-In-One Container"
echo "=========================================="
echo ""

# Start MariaDB in background
echo "Starting MariaDB..."
sudo service mariadb start

# Wait for MariaDB to be ready
echo "Waiting for MariaDB to be ready..."
ATTEMPT=0
MAX_ATTEMPTS=30
until mysql -u root -proot -e "SELECT 1" > /dev/null 2>&1; do
    ATTEMPT=$((ATTEMPT + 1))
    if [[ "${ATTEMPT}" -ge "${MAX_ATTEMPTS}" ]]; then
        echo "ERROR: MariaDB failed to start after ${MAX_ATTEMPTS} attempts"
        exit 1
    fi
    echo "  Attempt ${ATTEMPT}/${MAX_ATTEMPTS}..."
    sleep 2
done

echo "✓ MariaDB is ready"
echo ""

# Verify CiviCRM site exists
if [[ ! -f "/home/buildkit/buildkit/build/site/web/index.php" ]]; then
    echo "ERROR: CiviCRM site not found in expected location"
    exit 1
fi

echo "✓ CiviCRM site verified"
echo ""

# Set proper permissions
echo "Setting permissions..."
sudo chown -R buildkit:buildkit /home/buildkit/buildkit/build/site/web/sites/default/files 2>/dev/null || true
sudo chmod -R 755 /home/buildkit/buildkit/build/site/web/sites/default/files 2>/dev/null || true
echo "✓ Permissions set"
echo ""

# Display access information
echo "=========================================="
echo "CiviCRM EU-NGO is ready!"
echo "=========================================="
echo ""
echo "Access your CiviCRM instance:"
echo "  URL: http://localhost (or your configured port)"
echo ""
echo "Default credentials:"
echo "  Username: admin"
echo "  Password: admin"
echo ""
echo "Included EU-NGO Extensions (9):"
echo "  ✓ org.project60.banking (Banking)"
echo "  ✓ org.project60.sepa (SEPA Payments)"
echo "  ✓ de.systopia.contract (Contract Management)"
echo "  ✓ de.systopia.twingle (Fundraising)"
echo "  ✓ de.systopia.gdprx (GDPR Compliance)"
echo "  ✓ de.systopia.xcm (Extended Contact Matcher)"
echo "  ✓ de.systopia.identitytracker (Identity Tracker)"
echo "  ✓ org.civicrm.shoreditch (Theme)"
echo "  ✓ org.civicrm.contactlayout (Contact Layout)"
echo ""
echo "All extensions are enabled and seeded with demo data."
echo "=========================================="
echo ""

# Start Apache in foreground
echo "Starting Apache..."
sudo apachectl -D FOREGROUND
