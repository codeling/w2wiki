# W2 wiki

W2 wiki is a web-based, wiki-like notepad that you can host yourself.


## Features:

- Elegant text markup:
    - Uses [Markdown Syntax](https://github.com/codeling/w2wiki/blob/master/pages/MarkdownSyntax.md).
    - It supports double-brackets [[like this]] to link to another page in the wiki by title
    - It supports double-braces {{like this}} to link to an uploaded image
- Title & content search
- Filesystem storage (no database required) in plain Markdown text files.
- Image uploading support
- Optionally password-protected
- Unlike cloud / hosted solutions, you retain control of your data
- Written in PHP for portability and hackability
- Extremely compact (only a few .php files and a .css file)
- Differences to [original w2wiki by panicsteve](https://github.com/panicsteve/w2wiki):
    - Rudimentary, optional git integration to commit (and push) each page edit
    - Support for renaming and deleting pages
    - Markdown formatting syntax help on edit page
    - Localization support (en/de/ja translations included)
    - Improved icon-based interface
    - Optional, always visible sidebar fed by a special page
	- Merged All/Recent list of all pages
    - Links:
        - Possibility to display pages that link to the current page
        - Support for links to sub-captions (using # in links)
	    - When navigating to a non-existing page, show links to similar pages
	    - Mark links to non-existing pages (by default in red)
        - Mark external links with globe icon
	- Image uploads:
		- Option to shrink uploaded images (based on maximum side length)
		- Fix rotation (based on EXIF data)
		- List of uploaded images providing usage information and option to delete


## Installation & Configuration

See [Installation instructions](https://github.com/codeling/w2wiki/blob/master/INSTALL.md).


## Security Notice

In its current form, W2 wiki is not security-hardened; it's recommended to only run on an additionally secured server (e.g. in a small, private network for one user only; and secured behind a VPN and/or HTTPS with basic authentication).


## License

W2 is licensed under the [MIT license](https://github.com/codeling/w2wiki/blob/master/LICENSE).


## Acknowledgements

Originally written by [Steven Frank](https://github.com/panicsteve/w2wiki) and others, with modifications by
- [44uk](https://github.com/44uk/w2wiki)
- [knee-cola](https://github.com/knee-cola/w2wiki)
- [namvan](https://github.com/namvan/w2wiki)
- [nickodell](https://github.com/nickodell/w2wiki)
- [pilem](https://github.com/pilem/w2)

W2 wiki uses [PHP Markdown](https://github.com/michelf/php-markdown) by Michel Fortin for rendering Markdown to HTML.

The [Markdown syntax](https://github.com/codeling/w2wiki/blob/master/pages/MarkdownSyntax.md) description is taken from [daringfireball.net](https://daringfireball.net/projects/markdown/syntax).

Maintainer of this fork is [codeling](https://github.com/codeling/w2wiki).


## Reporting Bugs

Please report bugs in [the github issue tracker](https://github.com/codeling/w2wiki/issues) of this fork.

