# CiviCRM EU-NGO All-In-One Docker Image

A fully configured, production-ready CiviCRM instance with Drupal 10 and a complete suite of European nonprofit extensions. Perfect for NGOs, nonprofits, and organizations needing GDPR compliance, SEPA banking, contract management, and fundraising tools.

## 🚀 Quick Start

```bash
# Run the container (simplest method)
docker run -d -p 8080:80 --name civicrm-eu ghcr.io/jfilter/civicrm-eu-ngo:latest

# Wait ~20-30 seconds for startup
# Open your browser: http://localhost:8080
```

**Default Login Credentials:**
- Username: `admin`
- Password: `admin`

⚠️ **Important:** Change the default password immediately in production environments!

## 📦 What's Included

### Platform Stack
- **CiviCRM**: 6.7.1
- **Drupal**: 10 (latest stable)
- **PHP**: 8.2
- **Database**: MariaDB 10.11 (embedded)
- **Web Server**: Apache 2.4

### EU Nonprofit Extensions (9 Extensions)

All extensions are **pre-installed, enabled, and seeded with demo data**:

1. **org.project60.banking** (v0.7.5)
   - Banking transaction processing
   - Payment matching and reconciliation

2. **org.project60.sepa** (v1.5.2)
   - SEPA Direct Debit support
   - European payment standard compliance

3. **de.systopia.contract**
   - Membership contract management
   - Recurring contribution handling

4. **de.systopia.twingle**
   - German fundraising platform integration
   - Online donation forms

5. **de.systopia.gdprx**
   - GDPR compliance tools
   - Data protection features

6. **de.systopia.xcm**
   - Extended Contact Matcher
   - Intelligent contact deduplication

7. **de.systopia.identitytracker**
   - Contact identity tracking
   - Multi-channel contact management

8. **org.civicrm.shoreditch**
   - Modern UI theme
   - Enhanced user experience

9. **org.civicrm.contactlayout**
   - Customizable contact layouts
   - Flexible contact page design

## 💾 Data Persistence

### With Persistent Database (Recommended)

To keep your data between container restarts:

```bash
docker run -d -p 8080:80 \
  --name civicrm-eu \
  -v civicrm-db:/var/lib/mysql \
  ghcr.io/jfilter/civicrm-eu-ngo:latest
```

### Backup Your Data

```bash
# Create a backup
docker exec civicrm-eu mysqldump -u root -proot civicrm > backup.sql

# Restore a backup
docker exec -i civicrm-eu mysql -u root -proot civicrm < backup.sql
```

## 🔧 Configuration

### Environment Variables

Customize the container with environment variables:

```bash
docker run -d -p 8080:80 \
  --name civicrm-eu \
  -e CIVIBUILD_ADMIN_PASS="your-secure-password" \
  -v civicrm-db:/var/lib/mysql \
  ghcr.io/jfilter/civicrm-eu-ngo:latest
```

### Port Mapping

Change the external port:

```bash
# Access on port 8888 instead of 8080
docker run -d -p 8888:80 --name civicrm-eu ghcr.io/jfilter/civicrm-eu-ngo:latest
```

## 🐳 Docker Compose

For easier management, use Docker Compose. Save this as `docker-compose.yml`:

```yaml
version: '3.8'

services:
  civicrm-eu:
    image: ghcr.io/jfilter/civicrm-eu-ngo:latest
    container_name: civicrm-eu
    ports:
      - "8080:80"
    volumes:
      - civicrm-db:/var/lib/mysql
    restart: unless-stopped
    environment:
      - CIVIBUILD_ADMIN_PASS=your-secure-password

volumes:
  civicrm-db:
```

Run with:
```bash
docker-compose up -d
```

## 🔍 Accessing the Database

The embedded MariaDB can be accessed from inside the container:

```bash
# Connect to MySQL
docker exec -it civicrm-eu mysql -u root -proot civicrm

# Or as the civicrm user
docker exec -it civicrm-eu mysql -u civicrm -pcivicrm civicrm
```

**Database Credentials:**
- Root user: `root` / `root`
- CiviCRM user: `civicrm` / `civicrm`
- Database name: `civicrm`

## 🔌 API Access

The image includes **7 pre-configured API users** with different permission levels for comprehensive API testing and integration:

### Available API Users

| Username | Password | Purpose |
|----------|----------|---------|
| `admin` | `admin` | Full administrative access |
| `demo` | `demo` | Extensive permissions for testing |
| `readonly` | `readonly` | View-only access (no create/update/delete) |
| `fundraiser` | `fundraiser` | CiviContribute, campaigns, donations |
| `eventmanager` | `eventmanager` | CiviEvent management |
| `caseworker` | `caseworker` | CiviCase management |
| `bankimporter` | `bankimporter` | CiviBanking imports + activities |

### Getting Your API Key

API keys are automatically generated on first startup and displayed in the container logs:

```bash
# View API credentials
docker logs civicrm-eu | grep -A 20 "API User Credentials"
```

Or retrieve them manually:

```bash
# Get API key for a specific user
docker exec -it civicrm-eu bash -c \
  "cd /home/buildkit/buildkit/build/site/web && \
   /home/buildkit/buildkit/bin/cv api4 User.get +w name='fundraiser' +s 'contact_id.api_key'"
```

### Making API Requests

**Example: Get contacts using APIv4**

```bash
curl -X POST "http://localhost:8080/civicrm/ajax/api4/Contact/get" \
  -H "X-Civi-Auth: Bearer YOUR_API_KEY" \
  -H "X-Requested-With: XMLHttpRequest" \
  -H "Content-Type: application/json" \
  -d '{"select":["id","display_name"],"limit":10}'
```

**Example: Create a contribution (fundraiser user)**

```bash
curl -X POST "http://localhost:8080/civicrm/ajax/api4/Contribution/create" \
  -H "X-Civi-Auth: Bearer YOUR_API_KEY" \
  -H "X-Requested-With: XMLHttpRequest" \
  -H "Content-Type: application/json" \
  -d '{
    "values": {
      "contact_id": 123,
      "financial_type_id": 1,
      "total_amount": 100.00,
      "receive_date": "2025-11-11"
    }
  }'
```

### API Documentation

For complete API documentation including:
- Detailed permission levels for each user
- All authentication methods
- API endpoint reference
- Testing examples
- Troubleshooting guide

See: [API-USERS.md](./API-USERS.md)

## 🛠️ Common Tasks

### Check Container Logs

```bash
docker logs civicrm-eu
docker logs -f civicrm-eu  # Follow logs
```

### Restart the Container

```bash
docker restart civicrm-eu
```

### Stop and Remove

```bash
docker stop civicrm-eu
docker rm civicrm-eu
```

### Update to Latest Version

```bash
docker pull ghcr.io/jfilter/civicrm-eu-ngo:latest
docker stop civicrm-eu
docker rm civicrm-eu
docker run -d -p 8080:80 --name civicrm-eu \
  -v civicrm-db:/var/lib/mysql \
  ghcr.io/jfilter/civicrm-eu-ngo:latest
```

## 🏥 Health Check

The container includes a health check that monitors Apache:

```bash
# Check container health
docker inspect --format='{{.State.Health.Status}}' civicrm-eu
```

## 📊 Demo Data

The image includes **demo data** for all extensions to help you explore features:

- Sample contacts and organizations
- Example financial types and transactions
- Pre-configured SEPA settings
- Sample contracts and memberships
- GDPR consent examples

**Note:** This is demo data for evaluation purposes. For production use, you should clear or replace this data.

## 🔒 Security Considerations

### For Production Use:

1. **Change default passwords immediately**
   ```bash
   # Access the container and change passwords
   docker exec -it civicrm-eu bash
   ```

2. **Use HTTPS** with a reverse proxy (nginx, Traefik, etc.)

3. **Regular backups** of the database volume

4. **Keep the image updated** to get security patches

5. **Restrict database access** if exposing ports

### Database Security

```bash
# Change root password
docker exec -it civicrm-eu mysql -u root -proot -e \
  "ALTER USER 'root'@'localhost' IDENTIFIED BY 'new-secure-password';"
```

## 🆘 Troubleshooting

### Container Won't Start

```bash
# Check logs
docker logs civicrm-eu

# Check if port is already in use
netstat -an | grep 8080

# Use different port
docker run -d -p 8888:80 --name civicrm-eu ghcr.io/jfilter/civicrm-eu-ngo:latest
```

### Cannot Access the Site

1. Wait 30-60 seconds for full startup
2. Check container is running: `docker ps`
3. Check logs: `docker logs civicrm-eu`
4. Verify port mapping: `docker port civicrm-eu`

### Database Issues

```bash
# Restart MariaDB inside container
docker exec -it civicrm-eu service mariadb restart

# Check MariaDB status
docker exec -it civicrm-eu service mariadb status
```

### Clear Cache

```bash
# Clear CiviCRM cache
docker exec -it civicrm-eu /home/buildkit/buildkit/bin/cv flush
```

## 📚 Resources

- [CiviCRM Documentation](https://docs.civicrm.org/)
- [Drupal Documentation](https://www.drupal.org/docs)
- [Extension Documentation](https://docs.civicrm.org/sysadmin/en/latest/customize/extensions/)

### Extension-Specific Resources

- [Banking Extension](https://github.com/Project60/org.project60.banking)
- [SEPA Extension](https://github.com/Project60/org.project60.sepa)
- [SYSTOPIA Extensions](https://github.com/systopia)

## 🤝 Contributing

Found a bug or have a suggestion? Please open an issue on the [GitHub repository](https://github.com/jfilter/civikitchen).

## 📄 License

This image packages open-source software:
- CiviCRM: AGPL-3.0
- Drupal: GPL-2.0
- Extensions: Various open-source licenses (see individual extensions)

## 🏷️ Image Tags

Available on [GitHub Container Registry](https://github.com/jfilter/civikitchen/pkgs/container/civicrm-eu-ngo):

- `latest`: Multi-architecture (ARM64 + AMD64) latest stable build
- `latest-arm64`: ARM64-specific build
- `latest-amd64`: AMD64/x86_64-specific build

The `latest` tag automatically selects the correct architecture for your platform.

## ⚡ Performance Tips

### Increase Memory Limit

```bash
docker run -d -p 8080:80 \
  --name civicrm-eu \
  --memory="2g" \
  --cpus="2" \
  ghcr.io/jfilter/civicrm-eu-ngo:latest
```

### Use tmpfs for Better Performance

```bash
docker run -d -p 8080:80 \
  --name civicrm-eu \
  --tmpfs /tmp:rw,noexec,nosuid,size=512m \
  -v civicrm-db:/var/lib/mysql \
  ghcr.io/jfilter/civicrm-eu-ngo:latest
```

## 🌍 Use Cases

This image is perfect for:

- **European NGOs** needing GDPR and SEPA compliance
- **Nonprofits** managing memberships and contracts
- **Fundraising organizations** using online donation tools
- **Development and testing** of CiviCRM extensions
- **Training and demonstrations** with pre-populated data
- **Rapid prototyping** of CRM workflows

## ⏱️ Startup Time

- **Initial startup**: ~20-30 seconds
- **Subsequent restarts**: ~10-15 seconds

The image is fully pre-configured, so there's no installation or setup process - just start and use!

---

**Need help?** Open an issue or check the [CiviCRM community forums](https://civicrm.org/forums).
