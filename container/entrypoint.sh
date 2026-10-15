#!/bin/sh
# Prepares the pages volume, then starts the web server (or the given command).
set -eu
# files in the pages folder get 644/755 by default; W2_UMASK=002 or 000 is for folders shared with other users
umask "${W2_UMASK:-022}"

pages=/var/www/html/pages
defaults=/usr/local/share/w2/pages

if [ ! -d "$pages" ] || [ ! -w "$pages" ]; then
	echo "W2: $pages is not writable for user $(id -u):$(id -g). Give that user the volume or" \
		"folder (e.g. chown 33:33, or podman run --userns=keep-id:uid=33,gid=33); see INSTALL.md." >&2
	exit 1
fi

# empty volume: start with the default pages
if [ -z "$(find "$pages" -mindepth 1 -maxdepth 1 -name '*.md' -print -quit)" ]; then
	cp -R "$defaults"/. "$pages"/
fi

# these files keep the pages and uploads from being served or executed by the web server;
# make sure they are there (also when the volume is an existing folder) and current
mkdir -p "$pages/images"
cp -f "$defaults/.htaccess" "$pages/.htaccess"
cp -f "$defaults/images/.htaccess" "$pages/images/.htaccess"

# git integration (GIT_COMMIT_ENABLED is set in the image's config.php)
if [ ! -e "$pages/.git" ]; then
	git -C "$pages" init -q -b main
	git -C "$pages" add -A
	git -C "$pages" -c user.name=W2 -c user.email=w2@localhost commit -q -m "Initial pages"
fi
git -C "$pages" config user.name > /dev/null || git -C "$pages" config user.name "${W2_GIT_NAME:-W2 wiki}"
git -C "$pages" config user.email > /dev/null || git -C "$pages" config user.email "${W2_GIT_EMAIL:-w2@localhost}"

exec "$@"
