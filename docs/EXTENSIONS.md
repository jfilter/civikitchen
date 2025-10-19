# CiviCRM Extension Development

Your extension development environment is ready! The `./extensions/` directory is mounted to the container for live editing.

## Table of Contents

- [Working with Custom Extensions](#working-with-custom-extensions)
- [Dependency Management](#dependency-management)
- [Extension Seeding](#extension-seeding)
- [Quick Start](#quick-start)
- [Common Commands](#common-commands)
- [Extension Structure](#extension-structure)
- [Troubleshooting](#troubleshooting)
- [Resources](#resources)

## Working with Custom Extensions

Extensions should be developed in their own git repositories and linked into the `./extensions/` directory.

### Symlinking Extensions

The recommended workflow is to develop your extension in a separate git repository and symlink it:

```bash
# 1. Develop your extension in its own repo
cd ~/projects
git clone git@github.com:yourorg/com.yourorg.myextension.git

# 2. Symlink into civikitchen
cd /path/to/civikitchen/extensions
ln -s ~/projects/com.yourorg.myextension .

# 3. Restart container (to install dependencies and run seeding)
cd /path/to/civikitchen
docker-compose restart civicrm
```

Or use the helper script:

```bash
./scripts/link-extension.sh ~/projects/com.yourorg.myextension
```

### Benefits of Symlinking

- ✅ Extensions remain in their own git repos with full history
- ✅ Work on multiple extensions simultaneously (multiple symlinks)
- ✅ Easy cleanup: just remove the symlink
- ✅ No git submodule complexity

## Dependency Management

Your extension can declare dependencies on other CiviCRM extensions. Dependencies are automatically installed when the container starts.

### Defining Dependencies

Create a `civikitchen.json` file in your extension's root directory:

```json
{
  "dependencies": [
    {
      "name": "org.project60.banking",
      "repo": "https://github.com/Project60/org.project60.banking.git",
      "version": "0.7.5",
      "enable": true
    },
    {
      "name": "org.project60.sepa",
      "repo": "https://github.com/Project60/org.project60.sepa.git",
      "version": "v1.5.2",
      "enable": true
    }
  ]
}
```

### Dependency Fields

- **name** (required): The extension key (e.g., `org.project60.banking`)
- **repo** (required): Git repository URL
- **version** (required): Git tag, branch, or commit SHA to checkout
- **enable** (optional, default: `true`): Whether to auto-enable the extension

### How It Works

1. Container scans `./extensions/*/civikitchen.json` on startup
2. Clones missing dependencies into the extensions directory
3. Checks out the specified version
4. Enables dependencies (if `enable: true`)
5. Skips dependencies that are already installed (idempotent)

### Manual Dependency Installation

Install dependencies without restarting:

```bash
./scripts/install-dependencies.sh
```

View current dependencies:

```bash
./scripts/list-extensions.sh
```

## Extension Seeding

Your extension can provide seed scripts to populate test data for development.

### Defining Seeding

Add a seeding configuration to your `civikitchen.json`:

```json
{
  "dependencies": [...],
  "seeding": {
    "enabled": true,
    "script": "scripts/seed-dev-data.sh",
    "runOnce": true
  }
}
```

### Seeding Fields

- **enabled** (required): Set to `true` to enable seeding
- **script** (required): Path to seed script relative to extension root
- **runOnce** (optional, default: `true`): If true, seeding runs once and creates a `.civicrm-seeded` marker file

### Creating Seed Scripts

Create a seed script in your extension repository (e.g., `scripts/seed-dev-data.sh`):

```bash
#!/bin/bash
set -e

# Navigate to site root
cd /home/buildkit/buildkit/build/site/web

echo "Seeding test data for com.yourorg.myextension..."

# Create test contacts using CiviCRM API
cv api4 Contact.create values='{
  "first_name": "Test",
  "last_name": "Banking User",
  "contact_type": "Individual"
}'

# Create test contributions
cv api4 Contribution.create values='{
  "contact_id": 202,
  "financial_type_id": 1,
  "total_amount": "100.00",
  "source": "Test seed data"
}'

# Add more seed data as needed for your extension

echo "✓ Seeding complete!"
```

Make sure your seed script is:
- **Executable**: `chmod +x scripts/seed-dev-data.sh`
- **Idempotent**: Safe to run multiple times if `runOnce` is `false`
- **Well-logged**: Print what it's creating for debugging

### Seeding Workflow

Seeding runs automatically after dependency installation:

1. Container starts/restarts
2. Dependencies are installed
3. Seed scripts execute for each extension
4. Marker files created (if `runOnce: true`)

### Manual Seeding

Run seeding manually:

```bash
# Seed all extensions
./scripts/seed-extensions.sh

# Force re-seed (ignore runOnce markers)
./scripts/seed-extensions.sh --force

# Reset seed markers to allow re-seeding
./scripts/reset-seed-markers.sh
```

### Example: Full Extension Setup

Here's a complete example showing dependencies and seeding:

```bash
# Your extension repo structure:
com.yourorg.myextension/
├── info.xml
├── myextension.php
├── civikitchen.json     # Dependencies + seeding config
├── scripts/
│   └── seed-dev-data.sh          # Seed script
├── CRM/
└── templates/
```

```json
// civikitchen.json
{
  "dependencies": [
    {
      "name": "org.project60.banking",
      "repo": "https://github.com/Project60/org.project60.banking.git",
      "version": "0.7.5",
      "enable": true
    }
  ],
  "seeding": {
    "enabled": true,
    "script": "scripts/seed-dev-data.sh",
    "runOnce": true
  }
}
```

## Quick Start

### Create Extension (Quick Test)

For quick testing, you can create an extension directly in the container:

```bash
docker-compose exec civicrm bash -c "cd /home/buildkit/buildkit/build/site/web/sites/default/files/civicrm/ext && civix generate:module com.yourorg.extensionname"
```

### Enable Extension

```bash
docker-compose exec civicrm bash -c "cd /home/buildkit/buildkit/build/site/web && cv ext:enable com.yourorg.extensionname"
```

### Development Workflow

1. Edit code in `./extensions/` (or your symlinked extension) using your local IDE
2. Clear caches: `docker-compose exec civicrm bash -c "cd /home/buildkit/buildkit/build/site/web && cv flush"`
3. Test at http://localhost:8080
4. Check logs: `docker-compose logs -f civicrm`

## Common Commands

```bash
# List extensions
docker-compose exec civicrm bash -c "cd /home/buildkit/buildkit/build/site/web && cv ext:list"

# Enable/disable
docker-compose exec civicrm bash -c "cd /home/buildkit/buildkit/build/site/web && cv ext:enable com.yourorg.extensionname"
docker-compose exec civicrm bash -c "cd /home/buildkit/buildkit/build/site/web && cv ext:disable com.yourorg.extensionname"

# Uninstall
docker-compose exec civicrm bash -c "cd /home/buildkit/buildkit/build/site/web && cv ext:uninstall com.yourorg.extensionname"

# Generate components
docker-compose exec civicrm bash -c "cd /home/buildkit/buildkit/build/site/web/sites/default/files/civicrm/ext/com.yourorg.extensionname && civix generate:page MyPage civicrm/mypage"
docker-compose exec civicrm bash -c "cd /home/buildkit/buildkit/build/site/web/sites/default/files/civicrm/ext/com.yourorg.extensionname && civix generate:api MyEntity MyAction"

# Run tests
docker-compose exec civicrm bash -c "cd /home/buildkit/buildkit/build/site/web/sites/default/files/civicrm/ext/com.yourorg.extensionname && phpunit"
```

## Extension Structure

```
com.yourorg.extensionname/
├── info.xml                 # Extension metadata
├── extensionname.php        # Main file with hooks
├── extensionname.civix.php  # Generated helpers
├── CRM/Extensionname/       # PHP classes (Page/, Form/, BAO/)
├── api/v3/                  # API v3 endpoints
├── templates/               # Smarty templates
├── xml/Menu/                # Routing configuration
├── sql/                     # Database schemas
├── managed/                 # Managed entities
└── tests/phpunit/           # Unit tests
```

## Troubleshooting

**Extension not showing:**
```bash
# Verify mount
docker-compose exec civicrm ls -la /home/buildkit/buildkit/build/site/web/sites/default/files/civicrm/ext

# Refresh in UI: Administer > System Settings > Manage Extensions > Refresh
```

**Changes not taking effect:**
```bash
# Clear caches
docker-compose exec civicrm bash -c "cd /home/buildkit/buildkit/build/site/web && cv flush"

# Check logs
docker-compose logs civicrm

# Restart Apache
docker-compose exec civicrm service apache2 restart
```

**View error logs:**
```bash
# Apache errors
docker-compose exec civicrm tail -f /var/log/apache2/error.log

# CiviCRM logs
docker-compose exec civicrm tail -f /home/buildkit/buildkit/build/site/web/sites/default/files/civicrm/ConfigAndLog/*.log
```

## Resources

**Official Documentation:**
- [Extension Development Guide](https://docs.civicrm.org/dev/en/latest/extensions/)
- [Hook Reference](https://docs.civicrm.org/dev/en/latest/hooks/)
- [civix Documentation](https://docs.civicrm.org/dev/en/latest/extensions/civix/)
- [API Documentation](https://docs.civicrm.org/dev/en/latest/api/)

**Project Guides:**
- [Advanced Configuration](ADVANCED.md) - Multi-site, PHP versions

**Community:**
- [CiviCRM Stack Exchange](https://civicrm.stackexchange.com/)
- [CiviCRM Chat](https://chat.civicrm.org/)
- [Extension Directory](https://civicrm.org/extensions)
