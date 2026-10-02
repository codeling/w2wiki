#!/usr/bin/env bash
# Runs the tests in tests/Server against the wiki served by a real web server in Docker containers.
#
#   tests/Server/run.sh apache    # php:apache with the .htaccess files of W2
#   tests/Server/run.sh nginx     # nginx + php-fpm with the rules from INSTALL.md
#
# With W2_BROWSER=1 the browser tests are run against the server as well.
# Needs Docker (with host networking), and "composer install" done. The containers use the
# ports given by W2_SERVER_PORT (default 8080) and, for nginx, 9000.
set -euo pipefail

kind=${1:-}
[[ $kind == apache || $kind == nginx ]] || { echo "usage: $0 apache|nginx" >&2; exit 2; }
port=${W2_SERVER_PORT:-8080}
php_version=${W2_PHP_VERSION:-8.3}
nginx_image=${W2_NGINX_IMAGE:-nginx:alpine}
repo=$(cd "$(dirname "$0")/../.." && pwd)
root=$(mktemp -d)
nginx_conf=
containers=()
php_image="php:$php_version-$([[ $kind == apache ]] && echo apache || echo fpm)"

cleanup() {
    for container in "${containers[@]:-}"; do
        [[ -n $container ]] && docker rm -f "$container" > /dev/null 2>&1 || true
    done
    # files created by the web server user may not be removable by us
    docker run --rm -v "$root:/w" "$php_image" sh -c 'rm -rf /w/* /w/.[!.]*' > /dev/null 2>&1 || true
    rm -rf "$root" 2> /dev/null || true
    [[ -n $nginx_conf ]] && rm -f "$nginx_conf" || true
}
trap cleanup EXIT

# a copy of the wiki to be served (including files not committed yet, but without tests and git data)
(cd "$repo" && git ls-files -co --exclude-standard -z | grep -zv '^tests/\|^\.github/' | tar --null --ignore-failed-read -T - -cf - 2> /dev/null) | tar -x -C "$root"
mkdir -p "$root/pages/images"
ln -s pages/images "$root/images"     # see README.md: uploads are served statically from here
chmod 755 "$root"
chmod -R a+rwX "$root/pages"          # the web server user needs to write pages and uploads

# (Apache's default alias for /icons/ hides the icons of the wiki, which are referenced as /icons/..., so it is removed)
name="w2test-$$"
case $kind in
    apache)
        containers+=("$name-apache")
        docker run -d --name "$name-apache" --network host \
            -v "$root:/var/www/html" \
            -v "$repo/tests/Server/apache.conf:/etc/apache2/conf-enabled/w2.conf:ro" \
            "$php_image" sh -c "a2enmod headers > /dev/null &&
                sed -i '/^Alias \/icons\//d' /etc/apache2/mods-available/alias.conf &&
                sed -i 's/^Listen 80\$/Listen $port/' /etc/apache2/ports.conf &&
                sed -i 's/:80>/:$port>/' /etc/apache2/sites-available/000-default.conf &&
                echo 'ServerName localhost' > /etc/apache2/conf-enabled/servername.conf &&
                exec apache2-foreground" > /dev/null
        ;;
    nginx)
        nginx_conf=$(mktemp)
        sed "s/@PORT@/$port/" "$repo/tests/Server/nginx/w2.conf.template" > "$nginx_conf"
        chmod 644 "$nginx_conf"
        containers+=("$name-fpm" "$name-nginx")
        docker run -d --name "$name-fpm" --network host -v "$root:/var/www/html" "$php_image" > /dev/null
        docker run -d --name "$name-nginx" --network host -v "$root:/var/www/html:ro" \
            -v "$nginx_conf:/etc/nginx/conf.d/default.conf:ro" "$nginx_image" > /dev/null
        ;;
esac

url="http://127.0.0.1:$port"
for _ in $(seq 1 60); do
    curl -fsS -o /dev/null "$url/index.php" 2> /dev/null && break
    sleep 1
done
if ! curl -fsS -o /dev/null "$url/index.php"; then
    for container in "${containers[@]}"; do docker logs "$container" 2>&1 | tail -20; done
    echo "The web server did not start" >&2
    exit 1
fi

cd "$repo"
W2_SERVER_URL=$url W2_SERVER_ROOT=$root vendor/bin/phpunit --testsuite server

# also try to run scripts in a real browser (needs "npm ci" and the browsers in tests/Browser)
if [[ ${W2_BROWSER:-} == 1 ]]; then
    cd "$repo/tests/Browser"
    W2_BASE_URL=$url W2_SERVER_ROOT=$root npx playwright test canary.spec.js xss.spec.js csp.spec.js
fi
