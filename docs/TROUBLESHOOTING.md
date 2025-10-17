# Troubleshooting

Common issues and how to resolve them.

## Buildkit commands not found

Buildkit commands are now globally available! You can use short commands:
```bash
docker-compose exec civicrm civibuild list
docker-compose exec civicrm civix --help
docker-compose exec civicrm cv --help
```

Or from inside the container:
```bash
docker-compose exec civicrm bash
civibuild list  # Works immediately
```

## amp MySQL connection errors

This is now configured automatically during container startup. If you still see "Call to a member function getDriver() on null", check the logs:
```bash
docker-compose logs civicrm | grep "amp"
```

The entrypoint script automatically runs:
```bash
amp config:set --mysql_dsn="mysql://root:root@mysql:3306"
```

## Site URLs showing 404 errors

With the single-site-per-container model, URLs should be clean and work immediately. The site is accessible at the root URL (http://localhost:8080).

If you see 404s:
1. Check the site was created: `docker-compose logs civicrm | grep "Site creation complete"`
2. Verify Apache is running: `docker-compose exec civicrm sudo apachectl status`
3. Check Apache vhost: `docker-compose exec civicrm cat /etc/apache2/sites-available/000-default.conf`

## Permission issues

The container runs as the `buildkit` user. All buildkit operations should work without sudo.

## MySQL connection issues

Check that the MySQL container is running:
```bash
docker-compose ps
docker-compose logs mysql
```

## Site doesn't load

1. Verify Apache is running: `docker-compose logs civicrm`
2. Check site was created: `docker-compose exec civicrm civibuild show site`
3. Verify the site directory exists: `docker-compose exec civicrm ls -la ~/site/web`
4. Check Apache error logs: `docker-compose exec civicrm sudo tail -f /var/log/apache2/error.log`

## Reset everything

To start fresh:
```bash
docker-compose down -v
docker-compose up -d
```
**Warning:** This deletes all sites and databases!
