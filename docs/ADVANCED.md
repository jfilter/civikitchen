# Advanced Usage

This guide covers advanced configuration and usage scenarios for the CiviCRM Buildkit Docker setup.

## Configuring PHP and CiviCRM Versions

### PHP Version

You can specify the PHP version at build time by setting `PHP_VERSION` in your `.env` file:

```bash
# .env file
PHP_VERSION=8.3
```

**Important:** Changing PHP version requires rebuilding the Docker image:
```bash
docker-compose build --no-cache
docker-compose up -d
```

**Supported PHP versions:** 8.1, 8.2, 8.3, 8.4 (default: 8.2)

### CiviCRM Version

You can specify a CiviCRM version by setting `CIVICRM_VERSION` in your `.env` file:

```bash
# .env file
CIVICRM_VERSION=6.7.1
```

**Note:** CiviCRM version only affects auto-created sites. Leave empty for the latest stable version.

**Examples:**
- `CIVICRM_VERSION=6.7.1` - Install specific stable version (default)
- `CIVICRM_VERSION=6.6.3` - Install previous stable version
- `CIVICRM_VERSION=master` - Install development version
- `CIVICRM_VERSION=` - Install latest stable

After changing the CiviCRM version, you'll need to recreate the container with a new volume:
```bash
docker-compose down -v
docker-compose up -d
```

**Note:** This will delete the existing site data. Back up first if needed!

## Changing Site Type

Each container manages **one CiviCRM site**. To switch to a different site type:

**Available site types:**
- `drupal10-demo` - **Recommended**: Drupal 10 + CiviCRM with demo data
- `drupal9-demo` - Drupal 9 + CiviCRM with demo data
- `dmaster` - Drupal 7 + CiviCRM (legacy, EOL)
- `wp-demo` - WordPress + CiviCRM with demo data
- `standalone` - CiviCRM Standalone

**Steps:**
1. Update `CIVICRM_SITE_TYPE` in `.env`:
   ```bash
   CIVICRM_SITE_TYPE=wp-demo
   ```

2. Recreate the container:
   ```bash
   docker-compose down -v
   docker-compose up -d
   ```

## Multi-Site Testing

To test multiple CiviCRM sites simultaneously (e.g., Drupal 10 + WordPress), run multiple containers:

**Example docker-compose.yml:**
```yaml
services:
  civicrm-drupal10:
    build:
      context: .
      args:
        PHP_VERSION: 8.2
    ports:
      - "8080:80"
    volumes:
      - drupal10-site:/home/buildkit
    environment:
      - MYSQL_HOST=mysql
      - CIVICRM_SITE_TYPE=drupal10-demo
    depends_on:
      - mysql
    networks:
      - civicrm-network

  civicrm-wordpress:
    build:
      context: .
      args:
        PHP_VERSION: 8.2
    ports:
      - "8081:80"
    volumes:
      - wordpress-site:/home/buildkit
    environment:
      - MYSQL_HOST=mysql
      - CIVICRM_SITE_TYPE=wp-demo
    depends_on:
      - mysql
    networks:
      - civicrm-network

volumes:
  drupal10-site:
  wordpress-site:
  mysql-data:
```

Then start specific containers:
```bash
docker-compose up -d civicrm-drupal10 civicrm-wordpress mysql
```

Access sites:
- Drupal 10: http://localhost:8080
- WordPress: http://localhost:8081

## Common Commands

**Note:** Buildkit commands are globally available! Use short commands from both inside and outside the container.

### Manually create/recreate the site
```bash
# Inside container
docker-compose exec civicrm bash
civibuild create site --type drupal10-demo --url http://localhost:8080 --force
```

### List available site types
```bash
docker-compose exec civicrm civibuild list
```

### Show site info
```bash
docker-compose exec civicrm civibuild show site
```

### Destroy the site
```bash
docker-compose exec civicrm civibuild destroy site --force
```

### Run tests
```bash
docker-compose exec civicrm bash
cd ~/site/web/sites/all/modules/civicrm
# or appropriate path for your CMS (Drupal 10: ~/site/web/core/modules/contrib/civicrm)
phpunit tests/phpunit/api/v4/...
```

### Use civix to create extensions
```bash
docker-compose exec civicrm bash
cd ~/site/web
civix generate:module com.example.myextension
```

### Access MySQL directly
```bash
docker-compose exec mysql mysql -uroot -proot
```

## Environment Variables

Edit `.env` file to customize:

```env
# Build Configuration
# PHP version to install (requires rebuild: docker-compose build --no-cache)
# Supported versions: 8.1, 8.2, 8.3, 8.4
PHP_VERSION=8.2

# MySQL Configuration
MYSQL_ROOT_PASSWORD=root
MYSQL_DATABASE=civicrm
MYSQL_USER=civicrm
MYSQL_PASSWORD=civicrm

# Apache/HTTP Configuration
HTTPD_DOMAIN=localhost
HTTPD_PORT=8080

# Single-Site Configuration
# This container follows a one-site-per-container model
# Each container creates and manages exactly one CiviCRM site

# Site type to automatically create on first startup
# Available types: drupal10-demo, drupal9-demo, wp-demo, standalone, dmaster
# Set to "false" or leave empty to disable auto-creation
CIVICRM_SITE_TYPE=drupal10-demo

# CiviCRM version to install (optional)
# Leave empty for latest stable, or specify version like "6.7.1" or "master"
# Examples: 6.7.1, 6.6.3, 6.5.1, master
CIVICRM_VERSION=6.7.1
```

## Custom amp Configuration

The amp configuration is auto-generated on first run. To customize:
```bash
docker-compose exec civicrm bash
nano ~/.amp/config.yml
amp test
```

## Running Multiple CiviCRM Instances

This setup follows a one-site-per-container model. For multiple sites, use the multi-container approach shown in the "Multi-Site Testing" section above.

## Email Testing with Maildev

Configure your CiviCRM site to use Maildev for outbound email:
- SMTP Host: `maildev`
- SMTP Port: `1025`
- View emails at: http://localhost:1080

## Updating Buildkit

To update buildkit tools:
```bash
docker-compose exec civicrm bash
cd ~/buildkit
git pull
./bin/civi-download-tools
```
