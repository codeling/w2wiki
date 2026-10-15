#!/usr/bin/env bash
# Runs the tests in tests/Server against the wiki served by a real web server in containers.
#
#   tests/Server/run.sh apache      # php:apache with the .htaccess files of W2
#   tests/Server/run.sh nginx       # nginx + php-fpm with the rules from INSTALL.md
#   tests/Server/run.sh container   # the image built from the Containerfile (or W2_IMAGE)
#
# With W2_SUBFOLDER=/w2 the wiki is installed in that subfolder of the web root instead
# (not for "container": the image serves the wiki from its web root).
# With W2_BROWSER=1 the browser tests are run against the server as well.
# Needs Docker or Podman (W2_CONTAINER_ENGINE, default: docker if installed), and "composer install"
# done. apache and nginx use host networking and the ports given by W2_SERVER_PORT (default 8080)
# and, for nginx, 9000; "container" publishes the port 8080 of the image as W2_SERVER_PORT.
set -euo pipefail

kind=${1:-}
[[ $kind == apache || $kind == nginx || $kind == container ]] || { echo "usage: $0 apache|nginx|container" >&2; exit 2; }
engine=${W2_CONTAINER_ENGINE:-$(command -v docker > /dev/null && echo docker || echo podman)}
command -v "$engine" > /dev/null || { echo "$engine not found; set W2_CONTAINER_ENGINE to docker or podman" >&2; exit 2; }
port=${W2_SERVER_PORT:-8080}
subfolder=${W2_SUBFOLDER:-}
[[ -z $subfolder || $subfolder =~ ^/[A-Za-z0-9_-]+$ ]] || { echo "W2_SUBFOLDER must look like /w2" >&2; exit 2; }
[[ $kind != container || -z $subfolder ]] || { echo "W2_SUBFOLDER is not supported with container" >&2; exit 2; }
php_version=${W2_PHP_VERSION:-8.3}
nginx_image=${W2_NGINX_IMAGE:-nginx:alpine}
repo=$(cd "$(dirname "$0")/../.." && pwd)
root=$(mktemp -d)
nginx_conf=
conf=
built_image=
containers=()
php_image="php:$php_version-$([[ $kind == apache ]] && echo apache || echo fpm)"
image=${W2_IMAGE:-}
# the mount option "z" labels the mounted files for SELinux (Podman on Fedora etc., where containers
# can't read them otherwise); it makes no difference elsewhere
z=z

cleanup() {
    for container in "${containers[@]:-}"; do
        [[ -n $container ]] && "$engine" rm -f "$container" > /dev/null 2>&1 || true
    done
    # files created by the web server user may not be removable by us (with rootless Podman the
    # user in the container is root for the files of the current user, and it is root in Docker)
    "$engine" run --rm -v "$root:/w:$z" "${image:-$php_image}" sh -c 'rm -rf /w/* /w/.[!.]*' > /dev/null 2>&1 || true
    [[ -n $conf ]] && rm -rf "$conf" || true
    [[ -n $built_image ]] && "$engine" rmi -f "$built_image" > /dev/null 2>&1 || true
    rm -rf "$root" 2> /dev/null || true
    [[ -n $nginx_conf ]] && rm -f "$nginx_conf" || true
}
trap cleanup EXIT

conf=$(mktemp -d)
cp "$repo/tests/Server/php-test.ini" "$repo/tests/Server/apache.conf" "$conf"
chmod 755 "$conf"
chmod 644 "$conf"/*

if [[ $kind == container ]]; then
    if [[ -z $image ]]; then
        image="w2test-image-$$"
        built_image=$image
        "$engine" build -q -f "$repo/Containerfile" -t "$image" "$repo" > /dev/null
    fi
    # the files of the image, which the tests need to see and change (config.php, .git, vendor, uploads)
    "$engine" run --rm --entrypoint tar "$image" -C /var/www/html -cf - . | tar -x -C "$root"
else
    # a copy of the wiki to be served (including files not committed yet, but without tests and git data)
    (cd "$repo" && git ls-files -co --exclude-standard -z | grep -zv '^tests/\|^\.github/' | tar --null --ignore-failed-read -T - -cf - 2> /dev/null) | tar -x -C "$root"
    ln -s pages/images "$root/images"     # see README.md: uploads are served statically from here
fi
mkdir -p "$root/pages/images"
chmod 755 "$root"
chmod -R a+rwX "$root/pages"          # the web server user needs to write pages and uploads

name="w2test-$$"
case $kind in
    apache)
        containers+=("$name-apache")
        "$engine" run -d --name "$name-apache" --network host \
            -v "$root:/var/www/html$subfolder:$z" \
            -v "$conf/php-test.ini:/usr/local/etc/php/conf.d/w2-test.ini:ro,$z" \
            -v "$conf/apache.conf:/etc/apache2/conf-enabled/w2.conf:ro,$z" \
            "$php_image" sh -c "a2enmod headers > /dev/null &&
                sed -i 's/^Listen 80\$/Listen $port/' /etc/apache2/ports.conf &&
                sed -i 's/:80>/:$port>/' /etc/apache2/sites-available/000-default.conf &&
                echo 'ServerName localhost' > /etc/apache2/conf-enabled/servername.conf &&
                exec apache2-foreground" > /dev/null
        ;;
    nginx)
        nginx_conf=$(mktemp)
        # (the rules from INSTALL.md are for the web root; in a subfolder their prefix locations get the prefix)
        sed -E "s/@PORT@/$port/; s#(\^~ )/#\1$subfolder/#" "$repo/tests/Server/nginx/w2.conf.template" > "$nginx_conf"
        chmod 644 "$nginx_conf"
        containers+=("$name-fpm" "$name-nginx")
        "$engine" run -d --name "$name-fpm" --network host -v "$root:/var/www/html$subfolder:$z" \
            -v "$conf/php-test.ini:/usr/local/etc/php/conf.d/w2-test.ini:ro,$z" "$php_image" > /dev/null
        "$engine" run -d --name "$name-nginx" --network host -v "$root:/var/www/html$subfolder:ro,$z" \
            -v "$nginx_conf:/etc/nginx/conf.d/default.conf:ro,$z" "$nginx_image" > /dev/null
        ;;
    container)
        containers+=("$name-w2")
        # the pages folder is mounted separately, as it is a volume of the image; W2_UMASK lets the
        # tests (running as another user) change what the web server user has created there
        "$engine" run -d --name "$name-w2" -p "127.0.0.1:$port:8080" -e W2_UMASK=000 \
            -v "$root:/var/www/html:$z" -v "$root/pages:/var/www/html/pages:$z" \
            -v "$conf/php-test.ini:/usr/local/etc/php/conf.d/w2-test.ini:ro,$z" "$image" > /dev/null
        ;;
esac

url="http://127.0.0.1:$port"
for _ in $(seq 1 60); do
    curl -fsS -o /dev/null "$url$subfolder/index.php" 2> /dev/null && break
    sleep 1
done
if ! curl -fsS -o /dev/null "$url$subfolder/index.php"; then
    for container in "${containers[@]}"; do "$engine" logs "$container" 2>&1 | tail -20; done
    echo "The web server did not start" >&2
    exit 1
fi

cd "$repo"
W2_SERVER_URL=$url W2_SERVER_PREFIX=$subfolder W2_SERVER_ROOT=$root vendor/bin/phpunit --testsuite server

# also try to run scripts in a real browser (needs "npm ci" and the browsers in tests/Browser)
if [[ ${W2_BROWSER:-} == 1 ]]; then
    cd "$repo/tests/Browser"
    W2_BASE_URL=$url$subfolder W2_SERVER_ROOT=$root npx playwright test canary.spec.js xss.spec.js csp.spec.js
fi
