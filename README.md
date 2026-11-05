# W2 wiki

W2 wiki is a web-based, wiki-like notepad that you can host yourself.


## Features:

- Elegant text markup:
    - Uses [Markdown Syntax](https://github.com/codeling/w2wiki/blob/master/pages/MarkdownSyntax.md).
    - It supports double-brackets [[like this]] to link to another page in the wiki by title
    - A line with only `![[Page name]]` embeds the content of that page (nested up to `INCLUDE_MAX_DEPTH` levels; cycles and oversized embeds are replaced by a marker)
    - Syntax highlighting of fenced code blocks which name their language (```` ```php ````): PHP, JavaScript/TypeScript, JSON, Python, shell, CSS/SCSS, HTML/XML, SQL, C/C++/Java/C#/Go/Rust/Kotlin/Swift, YAML, INI/TOML and diffs. It is done on the server, without JavaScript, in both color schemes (`CODE_HIGHLIGHTING` turns it off)
- Title & content search
- Filesystem storage (no database required) in plain Markdown text files.
- Image uploading support
- Optionally password-protected
- Dark mode: the pages follow the color scheme of the system (`prefers-color-scheme`), and the toolbar has buttons to choose light or dark instead (kept in a cookie). Needs a browser with `light-dark()` (Chrome 123, Firefox 120, Safari 17.5 or newer)
- Unlike cloud / hosted solutions, you retain control of your data
- Written in PHP for portability and hackability
- Extremely compact (only a few .php files and a .css file)
- Differences to [original w2wiki by panicsteve](https://github.com/panicsteve/w2wiki):
    - Rudimentary, optional git integration to commit (and push) each page edit, and to pull changes made elsewhere
    - Page history from git (with the git integration): the versions of a page (also from before it was renamed), an old version, what changed in it or how it differs from the current text, and restoring it in the editor
    - Support for renaming and deleting pages
    - Markdown formatting syntax help on edit page
    - Formatting toolbar above the text in the editor: bold, italic, headings (level 1 to 4), links (also to pages of the wiki), bulleted and numbered lists, quotes, code (inline, or a fenced block with the cursor where the language goes), horizontal rules, tables of a chosen size (or made of selected lines separated by tabs or commas, as copied from a spreadsheet), upload and the formatting help. Each change is one step for Ctrl+Z; the toolbar is one stop of Tab, with the arrow keys between its buttons (Alt+F10 goes there from the text). Only shown with JavaScript
    - Insert the text of Markdown or text files into the editor (button below the text, or drag & drop on the text); a new page gets the file's name
    - Live preview in the editor (button below the text): rendered by the server like the page itself (links, embeds, uploads), next to the text on wide screens and instead of it on narrow ones; the choice is remembered in the browser
    - Keyboard shortcuts (`?` shows the list): `/` search, `e` edit, `n` new page, `g` `h` home page, `g` `a` all pages, `h` history (with git); in the editor Ctrl+S or Ctrl+Enter save, Ctrl+B bold, Ctrl+I italic, Ctrl+K link, Ctrl+Alt+P preview, Alt+F10 formatting toolbar, Tab indents (Cmd and Option on a Mac). They only use the links of the page, and are left alone while typing; the pages work without them (and without JavaScript)
    - Localization support (en/de/ja/oc translations included)
    - Improved icon-based interface
    - Optional sidebar fed by a special page (shown when viewing pages, in the page list and in search results, but not when editing)
	- Merged All/Recent list of all pages
    - Links:
        - Possibility to display pages that link to the current page
        - Support for links to sub-captions (using # in links)
	    - When navigating to a non-existing page, show links to similar pages
	    - In the editor of a new page, the title is checked a moment after it was typed (or at once, if the editor was opened by a link to a missing page): it says whether a page of that name exists already (with links to it and to its editor), else shows similar pages, and the pages which link to the name or mention it without a link (with buttons to insert a link at the cursor, or to link the mention in that page)
	    - Mark links to non-existing pages (by default in red)
        - Mark external links with globe icon
	- Image uploads:
		- One dialog to upload files, in the editor (a button of the formatting toolbar above the text; drag & drop of files and paste of images open it with the files chosen, and the link is inserted at the cursor) and on the upload page (a button above the list)
		- Drag & drop: files dropped on the text are linked where they are dropped (the cursor follows them while they are dragged over it); dropped anywhere else in the editor, at the cursor, and never opened by the browser instead of the page; on the upload page, anywhere on the page. A hint covers the page while files are dragged over it. Images dragged from other pages become images in the text: those of the wiki's uploads (from the list of uploads, the preview, or a page in another tab) with the same link as after uploading, others with their address (`![alt](https://...)`)
		- Store a file under another name
		- Crop uploaded images in the dialog (drag the frame over the image), with the size of the result shown
		- Option to shrink uploaded images (based on maximum side length, after cropping)
		- Fix rotation (based on EXIF data)
		- List of uploaded images providing usage information and option to delete
		- Uploaded files are served by the wiki (`file.php`), with the same password and IP checks as the pages; the web server needs no setting for them
		- The uploads folder can be anywhere (`UPLOAD_PATH`), e.g. next to the pages folder in one git repository

## Installation & Configuration

See [Installation instructions](https://github.com/codeling/w2wiki/blob/master/INSTALL.md),
including [running W2 in a container](INSTALL.md#running-in-a-container-docker-and-podman) (Docker or Podman).


## Tests

Automated tests (PHPUnit, run against a private copy of the wiki) are described in [tests/README.md](tests/README.md); they run on GitHub Actions for pull requests.

The code for authentication (web server authentication, CSRF tokens, IP allowlist, password check, throttling
of failed logins) is shared with other apps in the library [php-webauth](https://github.com/codeling/php-webauth),
which is included as a git subtree in `webauth/`. Change it there, and update the copy with
`git subtree pull --prefix=webauth https://github.com/codeling/php-webauth.git main --squash`.


## Security Notice

In its current form, W2 wiki is not security-hardened; it's recommended to only run on an additionally secured server (e.g. in a small, private network for one user only; and secured behind a VPN and/or HTTPS with basic authentication).

**By default, there is no authentication at all** (`REQUIRE_PASSWORD` defaults to `false`, see `config.sample.php`): everybody who can reach the wiki can read and edit all pages, and upload and delete files. This is meant for a wiki on a private computer or network. Recommended setups if the wiki can be reached by others:

- Always serve it over HTTPS.
- Either set `REQUIRE_PASSWORD` to `true` and store a hash of your password (created with `password_hash`) in `W2_PASSWORD_HASH`, see the [installation instructions](https://github.com/codeling/w2wiki/blob/master/INSTALL.md). There is only this one password for everybody. Failed logins are limited (lockouts that grow with every failure, and a limit over all clients). Passwords in plain text (`W2_PASSWORD`) and unsalted SHA-1 hashes are no longer accepted; the login page offers the hash to replace them with.
- Or use the access control of your web server or a reverse proxy: an IP allowlist (`$allowedIPs`), HTTP Basic Authentication (which allows several users), or an authenticating proxy with single sign-on or a second factor. With `REQUIRE_WEB_SERVER_AUTH`, the wiki refuses every request which the web server did not authenticate, or which did not come over HTTPS, so a missing or ignored web server setting doesn't leave it open (see the [installation instructions](INSTALL.md#authentication-by-the-web-server)).
- Uploaded files are served by the wiki itself (`file.php`), so they are protected like the pages: by the password, the IP allowlist and the authentication of the web server. If you upgrade from a version which had the web server serve them, remove the `images` link or alias (see the [installation instructions](INSTALL.md#web-server-setup)).

SVG uploads are disabled by default, since SVG files can contain scripts; they can be enabled with the optional [enshrined/svg-sanitize](https://github.com/darylldoyle/svg-sanitizer) library, see the [installation instructions](https://github.com/codeling/w2wiki/blob/master/INSTALL.md).


## License

W2 is licensed under the [MIT license](https://github.com/codeling/w2wiki/blob/master/LICENSE).

Every source file (PHP, JavaScript, CSS, shell scripts, workflows and the `Containerfile`) names its license
with an [SPDX](https://spdx.dev/learn/handling-license-info/) identifier in the first lines:

```php
/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * What the file is for, in a sentence or two.
 */
```

Files which only have a comment line start with `// SPDX-License-Identifier: MIT` (`# ...` in shell scripts, YAML
and the `Containerfile`). Copyright notices of earlier authors stay in the files they were in. The bundled
`Michelf/` files are [PHP Markdown](https://michelf.ca/projects/php-markdown/) (also a Composer dependency, see INSTALL.md)
and keep their own notices and license.
`tests/Unit/FileHeaderTest.php` fails for a source file without the identifier.


## Acknowledgements

Originally written by [Steven Frank](https://github.com/panicsteve/w2wiki) and others, with modifications by
- [44uk](https://github.com/44uk/w2wiki)
- [knee-cola](https://github.com/knee-cola/w2wiki)
- [namvan](https://github.com/namvan/w2wiki)
- [nickodell](https://github.com/nickodell/w2wiki)
- [pilem](https://github.com/pilem/w2)
- [Mejans](https://github.com/Mejans) - Occitan translations
- [martin-v](https://github.com/martin-v) - folder support

W2 wiki uses [PHP Markdown](https://github.com/michelf/php-markdown) by Michel Fortin for rendering Markdown to HTML
(see [docs/MarkdownLibrary.md](docs/MarkdownLibrary.md) for a review of it and a comparison with league/commonmark).

The [Markdown syntax](https://github.com/codeling/w2wiki/blob/master/pages/MarkdownSyntax.md) description is taken from [daringfireball.net](https://daringfireball.net/projects/markdown/syntax).

Maintainer of this fork is [codeling](https://github.com/codeling/w2wiki).


## Reporting Bugs

Please report bugs in [the github issue tracker](https://github.com/codeling/w2wiki/issues) of this fork.

