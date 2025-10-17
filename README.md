# civikitchen

## CiviCRM Buildkit Docker Setup

![Docker](https://img.shields.io/badge/docker-%230db7ed.svg?style=flat&logo=docker&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-7.4%20%7C%208.0%20%7C%208.1%20%7C%208.2%20%7C%208.3-777BB4?style=flat&logo=php&logoColor=white)
![MariaDB](https://img.shields.io/badge/MariaDB-10.11-003545?style=flat&logo=mariadb&logoColor=white)
![License](https://img.shields.io/badge/License-AGPL%20v3-blue.svg?style=flat)

**civikitchen** is a modern Docker-based development environment for CiviCRM using the generic buildkit installation approach.

## Table of Contents

- [Features](#features)
- [Verified Working](#verified-working)
- [Prerequisites](#prerequisites)
- [Quick Start](#quick-start)
- [System Requirements](#system-requirements)
- [Site Access](#site-access)
- [Services & Ports](#services--ports)
- [Common Commands](#common-commands)
- [Quick FAQ](#quick-faq)
- [Next Steps](#next-steps)
- [Documentation](#documentation)
- [Resources](#resources)

## Features

- ✅ **Zero-config setup** - just run `docker-compose up -d` and you're ready!
- ✅ **One-site-per-container model** - simple, predictable, follows Docker best practices
- ✅ **Auto-creates Drupal 10 + CiviCRM demo site** with full data on first startup
- ✅ **Configurable PHP version** (7.4, 8.0, 8.1, 8.2, 8.3) - set at build time
- ✅ **Configurable CiviCRM version** - specify exact version or use latest
- ✅ **Multi-site testing** - run multiple containers simultaneously on different ports
- ✅ CiviCRM Buildkit with all tools (civibuild, civix, cv, amp, etc.)
- ✅ PHP 8.2 with Apache 2.4 (default, configurable)
- ✅ MariaDB 10.11
- ✅ PHPMyAdmin for database management
- ✅ Maildev for email testing
- ✅ Persistent volumes for data and buildkit sites
- ✅ Clean, maintainable setup using official buildkit installation approach

## Verified Working

✅ **CMS:** Drupal 10, Drupal 9, WordPress
✅ **CiviCRM:** All core components (Events, Contributions, Memberships, Mailings, etc.)
✅ **Testing:** Automated e2e tests across PHP 7.4-8.3 and CiviCRM 6.5-master

## Prerequisites

- Docker
- Docker Compose

## Quick Start

**1. (Optional) Customize configuration**

```bash
cp .env.example .env
# Edit .env to change PHP version, site type, port, etc.
```

> **Note:** By default, creates **Drupal 10 + CiviCRM demo site** on port 8080

**2. Start the containers**

```bash
docker-compose up -d
```

**3. Wait for initialization (3-5 minutes)**

```bash
docker-compose logs -f civicrm
```

Look for: `Site creation complete! Access your site at: http://localhost:8080`

**That's it!** Your site is ready at **http://localhost:8080** 🎉

> **First run automatically:**
> - Builds Docker image
> - Installs buildkit tools
> - Creates CiviCRM site with demo data
> - Configures all services

## System Requirements

**Minimum:**
- **RAM:** 4 GB (8 GB recommended for development)
- **CPU:** 2 cores
- **Disk:** 10 GB free space
- **Docker:** 20.10+ with Docker Compose

**Note:** Initial site creation takes 3-5 minutes and downloads ~500 MB of dependencies.

## Site Access

| Component | URL | Credentials |
|-----------|-----|-------------|
| **Site** | http://localhost:8080 | Demo: `demo` / `demo` |
| **Admin** | http://localhost:8080 | Admin password in logs ⬇️ |
| **CiviCRM** | http://localhost:8080/civicrm | (after login) |

**Find admin password:**
```bash
docker-compose logs civicrm | grep -A 10 "Site creation complete"
```

## Services & Ports

| Service       | URL                   | Description         |
| ------------- | --------------------- | ------------------- |
| CiviCRM Sites | http://localhost:8080 | Your buildkit sites |
| PHPMyAdmin    | http://localhost:8081 | Database management |
| Maildev       | http://localhost:1080 | Email testing UI    |
| MySQL         | localhost:3306        | Database server     |

## Common Commands

### Container Management

**Start containers:**
```bash
docker-compose up -d
```

**Stop containers:**
```bash
docker-compose down
```

**Restart containers:**
```bash
docker-compose restart
```

**View logs:**
```bash
docker-compose logs -f civicrm        # Follow CiviCRM logs
docker-compose logs -f                # All services
docker-compose logs civicrm | grep ERROR   # Search for errors
```

**Access container shell:**
```bash
docker-compose exec civicrm bash
```

### Site Management

**Check site status:**
```bash
docker-compose exec civicrm civibuild show site
```

**List available site types:**
```bash
docker-compose exec civicrm civibuild list
```

**Change site type:**
```bash
# 1. Update CIVICRM_SITE_TYPE in .env
# 2. Recreate container with fresh data
docker-compose down -v
docker-compose up -d
```

**Clear CiviCRM caches:**
```bash
docker-compose exec civicrm cv flush
```

### Rebuild & Reset

**Rebuild after changing PHP version:**
```bash
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

**Complete reset (deletes all data):**
```bash
docker-compose down -v     # Removes volumes
docker-compose up -d       # Fresh start
```

> **Warning:** `docker-compose down -v` deletes all sites and databases!

## Directory Structure

```
.
├── Dockerfile              # CiviCRM buildkit image
├── docker-compose.yml      # Service orchestration
├── entrypoint.sh          # Buildkit installation & site creation script
├── .env.example           # Environment template
├── .env                   # Your local config (git-ignored)
├── README.md              # This file
└── docs/                  # Documentation
    ├── TESTING.md         # Testing guide
    ├── TROUBLESHOOTING.md # Common issues and solutions
    └── ADVANCED.md        # Advanced configuration

Docker volumes (managed by Docker):
├── buildkit-home          # Contains buildkit tools and the site at /home/buildkit/site
└── mysql-data             # Database files
```

## Quick FAQ

**Q: How do I change the PHP version?**
A: Set `PHP_VERSION` in `.env`, then rebuild: `docker-compose build --no-cache && docker-compose up -d`. See [ADVANCED.md](docs/ADVANCED.md#php-version) for details.

**Q: How do I switch from Drupal to WordPress?**
A: Update `CIVICRM_SITE_TYPE=wp-demo` in `.env`, then run `docker-compose down -v && docker-compose up -d`. See [ADVANCED.md](docs/ADVANCED.md#changing-site-type).

**Q: Site isn't loading or shows errors?**
A: Check logs with `docker-compose logs civicrm`. See [TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) for common issues.

**Q: How do I run tests?**
A: Run `npm test` for Playwright tests. See [TESTING.md](docs/TESTING.md) for comprehensive testing guide.

**Q: Can I run multiple sites simultaneously?**
A: Yes! Use multiple containers on different ports. See [ADVANCED.md](docs/ADVANCED.md#multi-site-testing).

## Next Steps

**For Development:**
- **[Advanced Configuration](docs/ADVANCED.md)** - Multi-site setup, custom commands, environment variables
- **[CiviCRM Developer Guide](https://docs.civicrm.org/dev/en/latest/)** - Official development documentation
- **[civix Documentation](https://docs.civicrm.org/dev/en/latest/extensions/civix/)** - Extension development

**For Testing:**
- **[Testing Guide](docs/TESTING.md)** - Playwright e2e tests, PHP/CiviCRM version testing

**Having Issues?**
- **[Troubleshooting](docs/TROUBLESHOOTING.md)** - Common problems and solutions

## Documentation

- **[Testing Guide](docs/TESTING.md)** - Comprehensive testing documentation including PHP and CiviCRM version testing
- **[Troubleshooting](docs/TROUBLESHOOTING.md)** - Common issues and how to resolve them
- **[Advanced Usage](docs/ADVANCED.md)** - Advanced configuration, multi-site setup, custom commands

## Resources

- [Buildkit Documentation](https://docs.civicrm.org/dev/en/latest/tools/buildkit/)
- [CiviCRM Developer Guide](https://docs.civicrm.org/dev/en/latest/)
- [civibuild Reference](https://docs.civicrm.org/dev/en/latest/tools/civibuild/)
- [civix Documentation](https://docs.civicrm.org/dev/en/latest/extensions/civix/)

## License

Licensed under [AGPL-3.0](LICENSE.md) - Copyright (C) 2025 Johannes Filter
