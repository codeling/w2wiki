# W2 wiki

W2 wiki is a web-based, wiki-like notepad that you can host yourself.


## Notable features:

- Elegant text markup:
    - Uses [Markdown Syntax](https://github.com/codeling/w2wiki/blob/master/pages/MarkdownSyntax.md).
    - It supports double-brackets [[like this]] to link to another page in the wiki by title
    - It supports double-braces {{like this}} to link to an uploaded image
- Minimalistic but functional interface:
    - Fits to screen nicely when viewed on iPhone, with custom icon for adding to home screen
    - HTML5 compliant output
- Title & content search
- Filesystem storage (no database required) in plain Markdown text files.
- Rudimentary git integration to commit & push each page edit
- Localization support (currently en/ja translations available)
- Image uploading support
- Optionally password-protected
- Unlike cloud / hosted solutions, you retain control of your data
- Written in PHP for portability and hackability
- Extremely compact (only a few .php files and a .css file)


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

