-- Grant the dev user global privileges so headless extension tests can
-- create/drop their own scratch database (civicrm_test by default).
GRANT ALL PRIVILEGES ON *.* TO 'civicrm'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
