# Installation

1. Create a directory for W2 somewhere in your web server's document
   root path. It doesn't matter where. W2 requires PHP 8.1 or higher.
   
2. Upload the files from this repository to this directory.

3. Make sure that the "pages" directory and its uploads folder ("images" by
   default, see `UPLOAD_FOLDER`) are writable by your web server process.
   
4. W2 works without any configuration. To change settings, copy
   `config.sample.php` to `config.php` and edit the copy; the sample lists all
   options with their defaults (see below for more information). `config.php`
   needs to contain only the settings you change.


You should now be ready to access your W2 installation.


# Web server setup

W2 is a plain PHP application, and works with Apache (with `mod_php` or
PHP-FPM) out of the box. Other web servers need a few things set up by hand.
(The rules which protect the `pages` folder and the uploads are described in
"Web server configuration" under "Security" below.)

**Page URLs (PATH_INFO).** W2 links to pages like `/index.php/Page`, so the web
server has to pass the part after `index.php` to PHP as `PATH_INFO`. If it
doesn't, you get "not found" pages or wrong links. For nginx with PHP-FPM:

```
location ~ \.php(/|$) {
    fastcgi_split_path_info ^(.+?\.php)(/.*)$;
    fastcgi_pass unix:/run/php/php-fpm.sock;  # adapt to your setup
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_param PATH_INFO $fastcgi_path_info;
}
```

If you can't get `PATH_INFO` to work (some CGI setups), set `VIEW` in
`config.php` so that pages are addressed by a query string instead:

```
define('VIEW', '?action=view&page=');
```

W2 then links to `/index.php?action=view&page=Page`; the page name is appended
to `VIEW` as is, without a slash.

**Uploaded images.** Uploads are stored in `pages/images` (the folder named by
`UPLOAD_FOLDER` in `config.php`, a single folder name), which must not be served
directly, but pages refer to them as `<W2 URL>/images/<name>` (the URL path is
`UPLOAD_URL`, which is the folder name unless you set it otherwise). So the web
server has to serve `pages/images` under the URL `images` inside the W2 folder:
either create a link (on Linux/macOS: `ln -s pages/images images` in the W2
folder) or, for nginx, an alias such as
`location ^~ /images/ { alias /path/to/w2/pages/images/; }` (see the rules
below, which belong in the same location). If you rename the folder, rename the
link/location accordingly (or keep the old URL by setting `UPLOAD_URL`). The
uploads folder is not part of the wiki: Markdown files in it are not pages, and
pages cannot be created there. Existing links in pages are not changed when you
change the setting.

**Subfolders and base URL.** W2 can be installed in a subfolder of the web
root. It derives its base URL (`BASE_URI`, used for the style sheet, icons,
`wiki.js` and images) from the folder of the script (`SCRIPT_NAME`), and `SELF`
is the URL of `index.php`. If the web server or a reverse proxy changes the
path so that these are wrong, set them in `config.php`, e.g.
`define('BASE_URI', '/wiki');` and `define('SELF', '/wiki/index.php');`
(no trailing slash for `BASE_URI`). The nginx rules below have to use the same
prefix.

**Static files.** The style sheet, scripts and icons (`w2-icons`) are served
directly by the web server, and referenced with a version parameter
(`wiki.js?v=...`). The nginx rules below send long cache headers for them;
the same is possible in Apache with `mod_expires`/`mod_headers`.


# Running in a container (Docker and Podman)

The `Containerfile` builds an image with Apache, PHP 8.5, Imagick (with HEIC
support) and git. Use `docker` or `podman`, whichever you have:

```
docker build -f Containerfile -t w2wiki .      # podman finds the Containerfile by itself
docker run -d --name w2 -p 127.0.0.1:8080:8080 -v w2-pages:/var/www/html/pages w2wiki
```

W2 is then at <http://localhost:8080>. `compose.yaml` does the same with
`docker compose up -d` or `podman compose up -d`. (Images built by the GitHub
workflow are published as `ghcr.io/codeling/w2wiki`.)

- **Pages and uploads** are in the volume `/var/www/html/pages`. When it is empty (no
  `.md` files), the start script fills it with the default pages and makes it a git
  repository (`GIT_COMMIT_ENABLED` is on in the image, each edit is committed; the
  author is `W2_GIT_NAME` / `W2_GIT_EMAIL`). It also (re)installs the `.htaccess` files
  which protect the pages and uploads. To use an existing folder, mount it instead of the
  volume. Back up the volume, e.g. `docker run --rm -v w2-pages:/p -v "$PWD":/b alpine tar -C /p -czf /b/pages.tgz .`
- **No root**: the container runs as `www-data` (user 33) and Apache listens on port 8080. A
  named volume belongs to that user automatically. A mounted folder has to be writable for
  it: `chown 33:33 folder` with Docker, or with rootless Podman
  `-v ./pages:/var/www/html/pages:Z --userns=keep-id:uid=33,gid=33` (`:Z` is for SELinux).
  The start script says so if it can't write there. `W2_UMASK=002` makes new files
  group-writable, for a folder shared with other users.
- **Hardening**: `--read-only --tmpfs /tmp --cap-drop ALL --security-opt no-new-privileges`
  works (only the pages and `/tmp` are written to); `compose.yaml` has these.
- **Settings**: mount your own `config.php` (`-v ./config.php:/var/www/html/config.php:ro`),
  e.g. for `REQUIRE_PASSWORD` or `W2_PASSWORD_HASH`. Start from `config.sample.php` of this repository:
  the image sets `GIT_COMMIT_ENABLED` to true (set it in your file too), and `PAGES_PATH` has to stay as it is.
  PHP settings (upload limits are 20 MB) can be overridden with a file in `/usr/local/etc/php/conf.d/`.
  Pushing to and pulling from a remote repository (`GIT_PUSH_ENABLED`, `GIT_PULL_ENABLED`) needs credentials which the image doesn't have.
- **HTTPS**: the container speaks plain HTTP. Publish it only on localhost or a private network
  and put a reverse proxy with HTTPS (and, as recommended under "Security", authentication) in front.
  If W2 is served below a path, set `BASE_URI` and `SELF` in `config.php`.
- **Updates**: rebuild (or pull) regularly to get updates of the base image, and start a new
  container with the same volume.
- **Podman**: images in the OCI format don't have the `HEALTHCHECK` of the Containerfile;
  use `podman build --format docker` if you want it.

# Configuration

The file config.sample.php lists the many options to customize your W2 setup, with
their default values and explanations. To change some, copy it to `config.php`
(`cp config.sample.php config.php`) and edit the copy. You can delete the options
you don't change from your `config.php`: every option it doesn't define has its default value
(from `defaults.php`, which you shouldn't edit), so a `config.php` from an older version
keeps working when new options are added. Without a `config.php`, all options have their
defaults (a message in the web server's error log tells so, once per session).
`config.php` is not part of the repository, so updating W2 doesn't overwrite it.

**Upgrading from a version where `config.php` was part of the repository:** if you
update with `git pull`, git refuses to continue (or removes your file, if you didn't
change it) because the file is no longer tracked. Copy your `config.php` somewhere
safe first, update, and put it back; it keeps working as it is.

A few examples:

The following line in config.php may be changed if you do not want the 
default page to be named 'Home':
```
define('DEFAULT_PAGE', 'Home');
```

The following line in config.php may be changed if you'd like to use a
different CSS stylesheet:
```
define('CSS_FILE', 'index.css');
```

The size of the edit textarea is controlled by:
```
define('EDIT_ROWS', 18);
```

## Security

**Note:** In it's current state, W2 wiki is not intended to be run as a public
wiki in places where malicious actors can access it. It is neither extensively
tested, nor is it security-hardened, and might therefore compromise the
security of your server. We therefore recommend to only make it available in
a small local network where each user is known, or behind VPN, or at least
secured via HTTPS and behind a Basic Authentication configured in your
webserver. The latter even allows for multiple different users, i.e. a more
fine-grained access control than what W2 wiki itself currently provides. The
features described below might be removed in the near future in this fork!

### Web server configuration

The `pages` folder must not be served directly by the web server (otherwise the
password and IP restrictions could be bypassed), and nothing in the uploads
folder (`pages/images` by default, served statically via an `images` link in the
W2 root folder; adapt the names below if you changed `UPLOAD_FOLDER`/`UPLOAD_URL`) may ever be executed. For Apache, the included `.htaccess` files take
care of this; they require `AllowOverride All` (or at least `AuthConfig`,
`FileInfo` and `Options`) and `mod_headers`. Without it the headers for uploads
and static files are silently left out, and the stock Apache of Debian/Ubuntu and
the `php:apache` image have `AllowOverride None` and don't load `mod_headers`. For nginx, add rules like the following (adapt the
W2 location prefix if W2 is not installed in the web root):

```
location ~ /\.(?!well-known/) { deny all; }
location ^~ /vendor/ { deny all; }
location ^~ /pages/ { deny all; }
location ^~ /tests/ { deny all; }
location ^~ /locales/ { deny all; }
location ^~ /Michelf/ { deny all; }
location ^~ /src/ { deny all; }
location ^~ /config. { deny all; }
location ^~ /defaults.php { deny all; }
location ^~ /functions.php { deny all; }
location ^~ /auth.php { deny all; }
location ^~ /auth_functions.php { deny all; }
location ^~ /webauth/ { deny all; }
location ^~ /composer. { deny all; }
location ^~ /phpunit.xml { deny all; }
location ^~ /LICENSE { deny all; }
location ^~ /README.md { deny all; }
location ^~ /INSTALL.md { deny all; }
location ^~ /CLAUDE.md { deny all; }
location ~* \.(js|css|svg|png)$ {
    add_header Cache-Control "max-age=31536000, immutable";
    add_header X-Content-Type-Options nosniff;
}
location ^~ /images/ {
    location ~* \.(php[0-9]?|pht|phtml|phar)$ { deny all; }
    add_header X-Content-Type-Options nosniff;
    add_header Cache-Control "max-age=31536000, immutable";
    location ~* \.svg$ {
        add_header X-Content-Type-Options nosniff;
        add_header Cache-Control "max-age=31536000, immutable";
        add_header Content-Security-Policy "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox";
    }
}
```

The scripts of the wiki send security headers with every response (Content-Security-Policy, which only allows
scripts and styles from the wiki itself and forbids framing, `X-Content-Type-Options`, `X-Frame-Options`,
`Referrer-Policy`, `Permissions-Policy`, and `Strict-Transport-Security` for requests over HTTPS). Don't add
headers of the same names in the web server, they would apply to the same responses twice. The rules above
additionally deny access to files which are not needed by visitors (the included scripts other than `index.php`
and `api.php`, the `tests`, `locales`, `Michelf` and `src` folders, `composer.json`, the documentation and so on).

### Upload size limits

Uploads are limited by PHP: `upload_max_filesize` (the largest single file) and
`post_max_size` (the largest request, which must be larger than the file). W2
shows a message naming the limit that was hit. Web servers have limits of their
own, which apply first and are not detected by W2: `client_max_body_size` in
nginx (default 1 MB) and `LimitRequestBody` in Apache. Raise them together, e.g.
`client_max_body_size 20m;` for `upload_max_filesize = 16M` and `post_max_size = 20M`.

### Session lifetime

The security token of the forms (e.g. the editor's) is kept in the PHP session, which W2
keeps for `W2_SESSION_LIFETIME` (30 days by default). With the PHP packages of Debian and
Ubuntu, that setting doesn't take effect: a cron job deletes session files older than
`session.gc_maxlifetime` from the `php.ini` files, by default after 24 minutes. Raise it
there to keep long edits from expiring, e.g. `session.gc_maxlifetime = 86400` in `/etc/php/<version>/fpm/php.ini` (or
`apache2/php.ini`). If a session expires anyway, saving shows the editor again with the
text, and the next save works.

### Image processing

Shrinking uploaded images, applying their EXIF rotation and converting formats
(`IMAGE_EXTS_TO_CONVERT`, by default HEIC/HEIF to JPEG) need the PHP extension
`imagick`. Without it, or with `IMAGE_PROCESSING_ENABLED` set to `false`, W2
stores images as they are and the upload form has no shrink option; images
which have to be converted (HEIC/HEIF by default) are refused with a message.

### SVG uploads

SVG files can contain scripts, which would run in the context of the wiki when
such a file is opened directly, so they can't be uploaded by default. To allow
them, install the [enshrined/svg-sanitize](https://github.com/darylldoyle/svg-sanitizer)
library, which removes scripts, event handlers, `javascript:` links and remote
references from uploaded SVG files, and enable the feature:

- Run `composer install --no-dev` in the W2 folder (requires the PHP extensions
  `dom` and `libxml`); this installs the library, a regular dependency of W2.
- Set `SVG_UPLOADS_ENABLED` to `true` in `config.php`.

Files that are not valid SVG files, or larger than 1 MB, are rejected. As long
as the library is not installed, SVG uploads stay disabled even if
`SVG_UPLOADS_ENABLED` is set. Note that the library is licensed under the
GPL-2.0-or-later and therefore not bundled with W2. SVG files uploaded
earlier (or placed in the uploads folder otherwise) are not modified; the
web server rules above still protect against scripts in them.

### Uploaded files

Uploaded files are served directly by the web server (that is what the `images` link or alias is for), so
neither the password nor the list of allowed IP addresses of W2 applies to them: everybody who knows
or guesses the URL of an uploaded file can fetch it, even if the pages are protected. File names are not
secret (they appear in the page sources and the list of uploads), so if uploads must be private,
let the web server ask for credentials for them too. For example with Basic Authentication
(create the file with `htpasswd -c /path/to/.htpasswd user`), in nginx:

```
location ^~ /images/ {
    auth_basic "W2";
    auth_basic_user_file /path/to/.htpasswd;
    # ... and the rules shown above for this location
}
```

and in Apache in `pages/images/.htaccess`, in place of the line `Require all granted` (several `Require` lines would be combined with "or"):

```
AuthType Basic
AuthName "W2"
AuthUserFile /path/to/.htpasswd
Require valid-user
```

### Authentication by the web server

Instead of (or in addition to) the password of W2, the web server can ask for
credentials: HTTP Basic authentication allows several users, each with their
own password, and an authenticating reverse proxy (single sign-on, a second
factor) can be used if the web server in front of PHP passes the user on as
`REMOTE_USER`. Then set in `config.php`:
```
define('REQUIRE_WEB_SERVER_AUTH', true);
```
W2 then refuses (with status 403) every request which the web server did not
authenticate, and, unless `WEB_SERVER_AUTH_REQUIRE_HTTPS` is set to `false`,
every request which did not come in over HTTPS. So a missing or ignored web
server setting (`AllowOverride None`, a copied configuration without the
authentication lines, ...) doesn't leave the wiki open, and passwords are never
sent in cleartext. Only `REMOTE_USER` counts: request headers such as
`Authorization` or `Remote-User` don't, since any client can send them.

For Apache, add to the `.htaccess` file in the W2 folder (or the virtual host;
create the password file outside of the web root with
`htpasswd -B -c /path/to/.htpasswd user`):
```
AuthType Basic
AuthName "W2"
AuthUserFile /path/to/.htpasswd
<RequireAll>
    Require expr "%{HTTPS} == 'on' || %{ENV:HTTPS} == 'on'"
    Require valid-user
</RequireAll>
```
For nginx, in the location of W2 (`auth_basic` sets `$remote_user`, which has to
be passed on to PHP):
```
auth_basic "W2";
auth_basic_user_file /path/to/.htpasswd;
# in the location for PHP:
fastcgi_param REMOTE_USER $remote_user;
```
Protect the uploads in the same way, see "Uploaded files" above. Behind a proxy
which terminates TLS, W2 sees plain HTTP: let the web server set `HTTPS` for the
requests of that proxy (in Apache e.g. `SetEnvIfExpr "%{REMOTE_ADDR} -ipmatch '10.0.0.5' && %{HTTP:X-Forwarded-Proto} == 'https'" HTTPS=on`,
in nginx `fastcgi_param HTTPS on;`), or set `WEB_SERVER_AUTH_REQUIRE_HTTPS` to
`false` if only that proxy can reach W2. Basic authentication has no limit for
wrong passwords: use fail2ban, `mod_evasive` or `limit_req` in nginx. More
examples are in `webauth/samples/` and `webauth/README.md`.

### Password protection

W2 has the ability to prompt for a password before allowing access to the
site.  Two lines in config.php control this:
```
define('REQUIRE\_PASSWORD', false);
define('W2\_PASSWORD\_HASH', '');
```

Set `REQUIRE_PASSWORD` to true and set `W2_PASSWORD_HASH` to a hash of the
password you'd like to use, created with PHP's `password_hash` function, e.g. via:
```
php -r 'echo password_hash("your_password", PASSWORD_DEFAULT), "\n";'
```
Use single quotes for the value in `config.php`, since the hash contains `$`
characters. Logging in is refused as long as no hash is set.

**Note:** This is a very rudimentary way of authorizing access: there is one
password for everybody, and no user accounts.

**Settings of earlier versions.** Earlier versions also accepted the password in
plain text (`W2_PASSWORD`) or an unsalted SHA-1 hash (e.g. from `sha1sum`) as
`W2_PASSWORD_HASH`. Both are quick to guess for anybody who gets to see
`config.php`, so they no longer allow logging in. To switch, enter your password
on the login page as before: instead of logging you in, the wiki shows a
`password_hash` hash of it (and the error log says why). Put that hash into
`W2_PASSWORD_HASH` (replacing the SHA-1 hash), remove `W2_PASSWORD`, and log in again.

Uploaded files are served statically by the web server (see "Uploaded images"
above) and are therefore **not** protected by the password. If that matters,
use authentication of the web server instead (below).

For several users, a login with a second factor (e.g. an identity provider with
passkeys, such as Pocket ID), or protecting the uploaded files, let the web
server or a reverse proxy handle the login: HTTP Basic Authentication over
HTTPS, or an authenticating proxy such as oauth2-proxy in front of the wiki.
W2 then doesn't need `REQUIRE_PASSWORD`.

### Failed logins

Failed logins are limited so that passwords can't be guessed by trying them
one after the other (or in parallel, or with a new session for every attempt):

- After 5 failed logins (`LOGIN_MAX_FAILURES`) from the same client address,
  that client is locked out for a minute (`LOGIN_LOCKOUT_SECONDS`). Each
  further failure doubles the lockout, up to an hour
  (`LOGIN_LOCKOUT_MAX_SECONDS`). While a client is locked out, the wiki
  answers with status 429 and a `Retry-After` header, even for the correct
  password. A successful login resets the count.
- Across all clients, 100 failed logins per hour are accepted
  (`LOGIN_MAX_FAILURES_PER_HOUR`), which limits attackers with many
  addresses. Once that is reached, nobody can log in for the rest of the
  hour; set it to 0 if you prefer that over this.
- IPv6 addresses count as one client per /64 network.
- The records are small files in the system's temporary folder, or in
  `LOGIN_THROTTLE_FOLDER` if you set it. That folder must be writable by the
  web server and must not be served by it (a place outside the web root;
  not the pages folder, which may be committed to git). If it can't be used,
  logging in is refused (and the error log says why), because the protection
  would be gone otherwise. `LOGIN_MAX_FAILURES = 0` turns the limit off.
- Behind a reverse proxy, all requests come from the proxy's address, so
  one client's failures would lock out everybody. List the proxy's address(es)
  in `$trustedProxies` in `config.php`; the client address is then taken
  from the `X-Forwarded-For` header, which is ignored for requests from any
  other address. Don't list proxies you don't have: any client could send that
  header.
- Every login is written to the web server's error log with the client
  address (`W2: successful login from ...`, `W2: failed login attempt from ...`,
  and lockouts), never with the password. A successful login you don't
  expect means that somebody else knows the password: change it.

The login form also contains a CSRF token, like all other forms.

## Git Integration

**Note:** The following assumes you are running W2 wiki on a Linux server, and
that you have shell access on your server. If you do not have such access,
this integration will not work.

**Prerequisite:** git needs to be installed on the server, and needs to be
callable by PHP: the PHP function `proc_open` must not be disabled (`disable_functions` in php.ini).
To install git for example on an Ubuntu server, you would execute
`$ sudo apt install git` on a shell

The following assumes that the webserver is running under the `www-data`
user (Ubuntu). If you are running your server under a different distribution,
please consult its documentation or community regarding the proper username.

To enable changes in pages to be committed to a local git repository, you need to:

- Take note of the folder where the pages are stored (`PAGES_PATH` in config.php).
- Navigate to this `PAGES_PATH` folder.
- Create a git repository: `$ sudo -u www-data git init`.
- Add all files there: `$ sudo -u www-data git add -A`.
- Make sure username/email are setup (add `--global` after `config` in below
  commands to make these settings available for all git repositories on this
  machine):
    - email: `$ sudo -u www-data git config user.email "w2.wiki@example.com"`
	- user: `$ sudo -u www-data git config user.name "W2 git user"`
- Do an initial commit: `$ sudo -u www-data git commit -m "Initial commit`.
- Set `GIT_COMMIT_ENABLED` to `true` in `config.php`.

To also enable pushing changes to a remote repository, you need to (in addition
to the steps above):

- Create a remote repository somewhere.
- Add this repository as remote in the repository in your `PAGES_PATH`:
  `$ sudo -u www-data git remote add origin [YOUR_REMOTE_REPO_URL]`
  (replace `[YOUR_REMOTE_REPO_URL]` with the publicly accessible URL of the remote).
- Make sure a remote tracking branch is set; you can achieve this in two ways;
    Both set the branch `main` on the remote `origin` as tracking branch;
	The first option will create the branch if it doesn't exist, and will
	upload all local content so far to that branch:
    - Either push to the remote tracking branch like this:
      `$ sudo -u www-data git push -u origin main`
    - or set a remote branch as tracking branch:
      `$ sudo -u www-data git branch -u origin main`
- Set `GIT_PUSH_ENABLED` to `true` in `config.php`.

To also get changes which others have pushed to the remote repository into the
wiki automatically (in addition to the steps above, with a remote tracking branch
set):

- Set `GIT_PULL_ENABLED` to `true` in `config.php` (needs `GIT_COMMIT_ENABLED`).
  The web server user needs to be able to fetch from the remote without a
  password prompt, like for pushing.
- The wiki pulls (`git pull --no-rebase`, giving up after 20 seconds) when a
  page is viewed or the editor is opened, but not more often than allowed by
  two intervals, which share the time of the last attempt:
    - `GIT_PULL_VIEW_INTERVAL`: viewing pages, default one hour. Use a long time
      (up to a day), as most views don't need the latest changes.
    - `GIT_PULL_EDIT_INTERVAL`: opening the editor or creating a page, default and
      maximum five minutes, so that the editor starts with the latest text.
  `0` pulls on every view or every start of an edit.
- If a pull is possible without conflicts, the changes are merged, and
  pushed if `GIT_PUSH_ENABLED` is on (this also pushes commits which a rejected
  push left behind).
- If the changes cannot be merged, the pull is cancelled (the pages never
  contain conflict markers), and the wiki shows a message to resolve the
  conflicts in the repository of `PAGES_PATH` (`$ sudo -u www-data git pull`,
  fix the files, commit). It shows the message again at the next attempt, until
  this is done. Other problems (like an unreachable remote) are shown as a
  generic error and logged in the web server's error log.
- If somebody edits a page in the wiki while the same page is changed
  elsewhere, the editor has no way of knowing; the changes are merged
  at the next pull (or reported as a conflict).
- The time of the last attempt is kept in a file in the system's temporary folder.

