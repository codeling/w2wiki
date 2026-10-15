# W2 wiki on Apache with PHP. Build with Docker or Podman, from the root of the repository:
#   docker build -f Containerfile -t w2wiki .      (podman finds the Containerfile by itself)
# See "Running in a container" in INSTALL.md.
ARG PHP_VERSION=8.3
# Debian release of the base image: the package names of ImageMagick and libheif below are for bookworm
ARG DEBIAN_RELEASE=bookworm
FROM php:${PHP_VERSION}-apache-${DEBIAN_RELEASE}

LABEL org.opencontainers.image.title="W2 wiki" \
      org.opencontainers.image.description="A web-based, wiki-like notepad that you host yourself" \
      org.opencontainers.image.source="https://github.com/codeling/w2wiki" \
      org.opencontainers.image.licenses="MIT"

# git: for the git integration; Imagick (with HEIC support): resizing, rotating and converting uploaded images
RUN set -eux; \
	apt-get update; \
	apt-get install -y --no-install-recommends git libmagickcore-6.q16-6-extra libheif1; \
	savedAptMark="$(apt-mark showmanual)"; \
	apt-get install -y --no-install-recommends $PHPIZE_DEPS libmagickwand-dev; \
	pecl install imagick; \
	docker-php-ext-enable imagick; \
	apt-mark auto '.*' > /dev/null; \
	apt-mark manual $savedAptMark; \
	find /usr/local -type f -executable -exec ldd '{}' ';' \
		| awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); print so }' \
		| sort -u | xargs -r dpkg-query --search | cut -d: -f1 | sort -u | xargs -r apt-mark manual; \
	apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
	rm -rf /var/lib/apt/lists/* /tmp/pear; \
	php -r 'new Imagick();'; \
	php -r 'if (!Imagick::queryFormats("HEIC")) { fwrite(STDERR, "WARNING: ImageMagick has no HEIC support\n"); }'

# Apache: .htaccess files allowed, mod_headers on (both needed by the protection of pages and uploads),
# port 8080 so that no root is needed. The pid and lock files go to /tmp, so that it is the only
# folder besides the pages which has to be writable (podman/docker run --read-only --tmpfs /tmp).
ENV APACHE_RUN_DIR=/tmp/apache2 APACHE_LOCK_DIR=/tmp/apache2 APACHE_PID_FILE=/tmp/apache2/apache2.pid
COPY container/w2.conf /etc/apache2/conf-enabled/w2.conf
COPY container/php.ini "$PHP_INI_DIR/conf.d/w2.ini"
RUN set -eux; \
	a2enmod headers; \
	sed -i 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf; \
	sed -i 's/:80>/:8080>/' /etc/apache2/sites-available/000-default.conf; \
	mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
	# the pages folder may belong to another user than the one in the container (bind mounts)
	git config --system --add safe.directory /var/www/html/pages

WORKDIR /var/www/html
# the code is not writable for the web server user, only the pages (and uploads) are
COPY . .
RUN set -eux; \
	# (the web root of the php image is world-writable)
	chmod 755 .; \
	mkdir -p /usr/local/share/w2; \
	mv pages /usr/local/share/w2/pages; \
	mkdir pages; \
	chown www-data:www-data pages; \
	# uploads are served from the "images" link (see INSTALL.md)
	ln -s pages/images images; \
	mv container/entrypoint.sh /usr/local/bin/w2-entrypoint; \
	rm -r container; \
	# git history of the pages, no PHP file to be rewritten at runtime
	sed -i "s/'GIT_COMMIT_ENABLED', false/'GIT_COMMIT_ENABLED', true/" config.php; \
	grep -q "'GIT_COMMIT_ENABLED', true" config.php

VOLUME /var/www/html/pages
EXPOSE 8080
USER www-data

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s CMD \
	php -r '$c = stream_context_create(["http" => ["ignore_errors" => true, "timeout" => 4]]); exit(@file_get_contents("http://127.0.0.1:8080/index.php", false, $c) === false ? 1 : 0);'

ENTRYPOINT ["/usr/local/bin/w2-entrypoint"]
CMD ["apache2-foreground"]
