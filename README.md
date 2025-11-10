# civikitchen

## CiviCRM Buildkit Docker Setup

![Docker](https://img.shields.io/badge/docker-%230db7ed.svg?style=flat&logo=docker&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.1%20%7C%208.2%20%7C%208.3%20%7C%208.4-777BB4?style=flat&logo=php&logoColor=white)
![MariaDB](https://img.shields.io/badge/MariaDB-10.11-003545?style=flat&logo=mariadb&logoColor=white)
![License](https://img.shields.io/badge/License-AGPL%20v3-blue.svg?style=flat)

**civikitchen** is a modern Docker-based development environment for CiviCRM using buildkit.

## Table of Contents

- [civikitchen](#civikitchen)
  - [CiviCRM Buildkit Docker Setup](#civicrm-buildkit-docker-setup)
  - [Table of Contents](#table-of-contents)
  - [Features](#features)
  - [Verified Working](#verified-working)
  - [🚀 Pre-Built All-in-One Image](#-pre-built-all-in-one-image)
  - [Prerequisites](#prerequisites)
  - [Quick Start](#quick-start)
  - [System Requirements](#system-requirements)
  - [Site Access](#site-access)
  - [Services \& Ports](#services--ports)
  - [Common Commands](#common-commands)
    - [Container Management](#container-management)
    - [Site Management](#site-management)
    - [Rebuild \& Reset](#rebuild--reset)
  - [Quick FAQ](#quick-faq)
  - [Documentation](#documentation)
  - [Resources](#resources)
  - [License](#license)

## Features

- ✅ **Zero-config** - `docker-compose up -d` creates a ready-to-use Drupal 10 + CiviCRM demo site
- ✅ **Flexible** - Configure PHP (7.4-8.3) and CiviCRM versions via environment variables
- ✅ **Complete** - Full Buildkit tools, MariaDB, PHPMyAdmin, and Maildev for email testing
- ✅ **Multi-site** - Run multiple CiviCRM instances on different ports simultaneously

## Verified Working

✅ **CMS:** Drupal 10, Drupal 9, WordPress
✅ **CiviCRM:** All core components (Events, Contributions, Memberships, Mailings, etc.)
✅ **Testing:** Automated e2e tests across PHP 8.1-8.4 and CiviCRM 6.5-6.7

## 🚀 Pre-Built All-in-One Image

Want to get started instantly? We publish pre-built images to GitHub Container Registry:

[![GHCR](https://img.shields.io/badge/GHCR-civicrm--eu--ngo-blue?logo=docker)](https://github.com/jfilter/civikitchen/pkgs/container/civicrm-eu-ngo)

```bash
docker run -d -p 8080:80 --name civicrm ghcr.io/jfilter/civicrm-eu-ngo:latest
```

**Ready in 30 seconds!** Pre-configured with:
- ✅ CiviCRM 6.7.1 + Drupal 10
- ✅ 9 EU nonprofit extensions (CiviBanking, SEPA, GDPR, etc.)
- ✅ Demo data pre-loaded
- ✅ Multi-architecture support (ARM64 + AMD64)

**[📖 View full documentation →](allinone/README.md)**

> **Development vs. Production:**
> Use the pre-built image for **quick testing, demos, or production**.
> Use docker-compose (below) for **development and customization**.

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

**3. Wait for initialization (5-10 minutes)**

```bash
docker-compose logs -f civicrm
```

Look for: `Site creation complete! Access your site at: http://localhost:8080`

**That's it!** Your site is ready at **http://localhost:8080** 🎉

> **First run automatically:**
>
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

**Note:** Initial site creation takes 5-10 minutes on first run while downloading dependencies.

## Site Access

**Access:** http://localhost:8080
- Demo: `demo` / `demo`
- Admin: `admin` / (password in logs ⬇️)

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

## Documentation

- **[Advanced Usage](docs/ADVANCED.md)** - Multi-site setup, custom commands, environment variables
- **[Extension Development](docs/EXTENSIONS.md)** - Complete guide for developing CiviCRM extensions
- **[Testing Guide](docs/TESTING.md)** - Playwright e2e tests, PHP/CiviCRM version testing
- **[Troubleshooting](docs/TROUBLESHOOTING.md)** - Common issues and solutions

## Example Setups

### European Nonprofit Stack 🌍

A complete setup for European nonprofit organizations featuring CiviBanking, CiviSEPA, GDPR tools, and more.

**Quick Start:**

```bash
mkdir -p stacks/eu-nonprofit
cp examples/civikitchen-eu-nonprofit.json stacks/eu-nonprofit/civikitchen.json
docker-compose restart civicrm
```

**What it includes:**
- CiviBanking & CiviSEPA for payment processing
- Contract management for recurring donations
- GDPR compliance tools
- Twingle integration (German donation forms)
- XCM & Identity Tracker for contact management
- Shoreditch theme
- **Automatic sample data seeding** for each extension

That's it! Extensions install automatically with built-in sample data.

## Resources

- [Buildkit Documentation](https://docs.civicrm.org/dev/en/latest/tools/buildkit/)
- [CiviCRM Developer Guide](https://docs.civicrm.org/dev/en/latest/)
- [civibuild Reference](https://docs.civicrm.org/dev/en/latest/tools/civibuild/)
- [civix Documentation](https://docs.civicrm.org/dev/en/latest/extensions/civix/)

## License

Licensed under [AGPL-3.0](LICENSE.md) - Copyright (C) 2025 Johannes Filter
