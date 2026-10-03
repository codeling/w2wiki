FROM php:7-apache

RUN docker-php-ext-install exif

RUN set -eux; \
	\
	apt-get update; \
	apt-get install -y --no-install-recommends git; \
	rm -rf /var/lib/apt/lists/*; \
	apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false

COPY --chown=www-data:www-data . /var/www/html

RUN set -eux; \
	\
	sed -i "s/BASE_PATH \\. //" /var/www/html/config.php ;\
	sed -i "s/'GIT_COMMIT_ENABLED', false/'GIT_COMMIT_ENABLED', true/" /var/www/html/config.php ;\
	echo "" > /etc/apache2/mods-enabled/alias.conf

VOLUME /pages
EXPOSE 80

CMD /var/www/html/container-start.sh
