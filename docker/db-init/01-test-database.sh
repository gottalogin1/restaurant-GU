#!/bin/bash
# Runs once, when the MariaDB volume is first created.
# Creates the database PHPUnit uses. The WordPress test suite drops its tables on every run,
# so it must never be the site database.
set -e
mariadb -uroot -p"${MARIADB_ROOT_PASSWORD}" <<-SQL
	CREATE DATABASE IF NOT EXISTS \`wordpress_test\`;
	GRANT ALL PRIVILEGES ON \`wordpress_test\`.* TO '${MARIADB_USER}'@'%';
	FLUSH PRIVILEGES;
SQL
