#!/bin/bash
set -e

# on empty volume copy default pages
if ! test -f /pages/Home.md ; then
	cp /var/www/html/pages/* /pages

	(
		cd /pages
		git init
		git config user.email "w2wiki@w2wiki"
		git config user.name "w2wiki"

		git add -A .
		git commit -m "Init with default pages"
	)
fi

mkdir -p /pages/images
chown -R www-data:www-data /pages

exec apache2-foreground "$@"
