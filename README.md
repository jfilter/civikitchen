# civikitchen

CiviCRM Docker images for development and testing.

## Images

### Standalone (dev)

Adds dev tools (pcov, civix, phpunit, phpstan) to the official `civicrm/civicrm` image. Use this for extension development.

```dockerfile
# In your extension's .docker/Dockerfile:
FROM ghcr.io/jfilter/civicrm-dev:standalone
```

Or reference directly in compose.yaml:

```yaml
services:
  app:
    image: ghcr.io/jfilter/civicrm-dev:standalone
    ports:
      - 8762:80
    volumes:
      - ../:/var/www/html/ext/myextension
    environment:
      CIVICRM_DB_HOST: db
      CIVICRM_DB_NAME: civicrm
      CIVICRM_DB_USER: civicrm
      CIVICRM_DB_PASSWORD: ${MYSQL_PASSWORD}
    depends_on:
      db:
        condition: service_healthy
  db:
    image: mariadb:10.11
    environment:
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD}
      MYSQL_DATABASE: civicrm
      MYSQL_USER: civicrm
      MYSQL_PASSWORD: ${MYSQL_PASSWORD}
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 5s
      timeout: 5s
      retries: 10
```

**Included tools:** pcov (coverage), civix (scaffolding), phpunit 9, phpstan

### Drupal 10 (dev)

CiviCRM on Drupal 10 via buildkit. Site is built on first container start (requires MariaDB).

```yaml
services:
  app:
    build: ./images/drupal10
    ports:
      - 8080:80
    environment:
      MYSQL_HOST: db
      MYSQL_ROOT_PASSWORD: root
    depends_on:
      - db
  db:
    image: mariadb:10.11
    environment:
      MYSQL_ROOT_PASSWORD: root
```

### WordPress (dev)

CiviCRM on WordPress via buildkit. Same pattern as Drupal 10.

### EU-NGO (all-in-one demo)

Pre-built single-container image with CiviCRM + Drupal 10 + 9 EU nonprofit extensions + embedded MariaDB + demo data. For demos and evaluation.

```bash
docker run -d -p 8080:80 --name civicrm ghcr.io/jfilter/civicrm-eu-ngo:latest
# Wait ~30s, then open http://localhost:8080
# Login: admin / admin
```

See [allinone/README.md](allinone/README.md) for details.

## Building locally

```bash
# Standalone
docker build -t civicrm-dev:standalone images/standalone/

# Drupal 10 (with specific PHP version)
docker build --build-arg PHP_VERSION=8.3 -t civicrm-dev:drupal10 images/drupal10/

# WordPress
docker build --build-arg PHP_VERSION=8.3 -t civicrm-dev:wordpress images/wordpress/
```

## License

AGPL-3.0 - see [LICENSE.md](LICENSE.md)
