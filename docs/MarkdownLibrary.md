# The Markdown library: review and alternatives

W2 renders pages with [PHP Markdown](https://github.com/michelf/php-markdown) (`michelf/php-markdown`, the
`MarkdownExtra` parser, with `no_markup` and W2's `filterURL`). In October 2026 it was reviewed for security problems and
compared with [league/commonmark](https://github.com/thephpleague/commonmark), the most widely used maintained
alternative. **Result: W2 stays with PHP Markdown.** The one serious problem found is fixed in W2 itself (see below), and a
switch would change the rendering of existing pages, add about 940 KB of dependencies and make rendering 3–4 times slower.

## PHP Markdown

### Maintenance

The last release, 2.0.0, is from September 2022. Since then there were five commits (the last in June 2025): two fixes of
PHP warnings and id/class attributes for tables; none is security related. The library is small (108 KB of PHP in three
files) and has no dependencies.

### Security review

- **Cross-site scripting: no problem found.** About 40 attempts were tried against the parser configured as in `toHTML()`: raw tags
  (`<script>`, `<img onerror>`, `<svg onload>`), `javascript:`, `vbscript:` and `data:` links and images in all
  encodings (entities, mixed case, tabs and spaces inside the scheme, reference links, `<…>` autolinks), quotes breaking
  out of link titles, alt texts, abbreviations and footnotes, and Markdown Extra attributes like `{#id .class onclick=…}`.
  None produced an element, event handler or URL which runs a script: `no_markup` escapes all HTML, `filterURL` catches
  the URL tricks, and quotes in attributes are escaped. The Content-Security-Policy (no `'unsafe-inline'` scripts) is a
  second line of defence.
- **Denial of service with nested blockquotes (fixed by [#82](https://github.com/codeling/w2wiki/pull/82)).** Each level
  of blockquote nesting re-processes and re-indents everything inside it, so the memory needed grows with the square of
  the depth: 500 `>` characters need 91 MB, 1000 (a 1 KB page) more than 512 MB, which ends the rendering with a fatal
  error, also on every page which embeds the page. The problem is not fixed upstream. `W2\MarkdownParser` limits the
  nesting to 20 levels (deeper `>` markers are shown as text); the same limit is proposed for PHP Markdown itself as the
  option `max_blockquote_depth` ([codeling/php-markdown](https://github.com/codeling/php-markdown)).
- **Silent empty output for some very large inputs.** When a regular expression of the parser fails (the PCRE
  backtracking limit, or a pattern too large to compile, as with 20,000 abbreviation definitions), the page is rendered
  empty without an error; for example 200,000 times `*a ` (600 KB). This is a correctness problem, not a security one.
- Deeply nested lists (by indentation) also need a lot of memory, but only for large inputs (400 levels are 322 KB of
  text and need 268 MB); not addressed.

## league/commonmark

[thephpleague/commonmark](https://github.com/thephpleague/commonmark) (league/commonmark 2.10.4, October 2026) is actively
maintained and implements the CommonMark and GitHub Flavored Markdown specifications. Compared with the extensions
GitHub Flavored Markdown, Footnote, DescriptionList and Attributes, with `html_input` `escape` and `allow_unsafe_links`
`false`:

### Formatting it adds

| Markdown | PHP Markdown (W2 now) | CommonMark |
|---|---|---|
| `~~gone~~` | text | strikethrough |
| `- [ ] todo`, `- [x] done` | text | checkboxes |
| `see https://example.com` | text | link |
| a list directly after a paragraph line (no blank line) | part of the paragraph | list |
| `\` at the end of a line | text | line break |
| `![a](x.png){width=50}` (any attribute) | attribute dropped | attribute set; `on…` handlers and `javascript:` are dropped, `style` is kept (but blocked by W2's Content-Security-Policy) |

### Formatting it removes or changes

| Markdown | PHP Markdown (W2 now) | CommonMark |
|---|---|---|
| `#Title` (no space after `#`) | heading | text |
| abbreviations, see below | `<abbr>` | not supported (no extension) |
| list nested under a numbered item with 2 spaces (`1. a` / `  - b`) | nested list | separate list (needs 3 spaces, aligned with the item text) |
| code block inside a list item | 8 spaces | indentation counted from the item text |
| ```` ``` php ```` | `class="php"` | `class="language-php"` (W2's CSS and JavaScript don't use it) |

The same in both: tables, footnotes, definition lists, `{#id .class}`, setext headings, hard breaks with two spaces,
underscores inside words, `[[wiki links]]` (left as text for W2's own processing), indented code, `<http://…>` autolinks.

**Abbreviations** are a Markdown Extra feature which W2 supports, although `MarkdownSyntax.md` does not describe it. A
line `*[HTML]: Hyper Text Markup Language` anywhere in the page (it is not shown) makes every occurrence of the word `HTML`
an `<abbr title="Hyper Text Markup Language">HTML</abbr>` (shown with the full text as tooltip). Matching is
case-sensitive and by whole words (`XHTML` and `html` are not changed), not inside code; an abbreviation can have several
words (`*[W3C WG]: W3C Working Group`) and the definition can be empty (`*[TLA]:` gives `<abbr>` without title).

### Other implications for W2

- **Existing pages:** most render the same; pages using the constructs in the second table would need editing, and so
  would `pages/MarkdownSyntax.md` and the syntax help next to the editor.
- **Composer would be required.** league/commonmark needs seven other packages (league/config, nette/utils,
  nette/schema, dflydev/dot-access-data, psr/event-dispatcher, two symfony packages). A copy like `Michelf/` for
  installations without Composer is not practical, and the container image would need a `composer install` step.
- **URL filter:** its `allow_unsafe_links` blocks `javascript:`, `vbscript:`, `file:` and `data:` (except images), which
  is less strict than W2's allowlist (http, https, mailto, ftp, ftps, tel); `filterURL` would have to be applied with an
  event listener.
- **Nested blockquotes are no problem** (`max_nesting_level`): 1000 `>` take 0.3 ms.
- **It has a slow input of its own:** 200,000 times `*a ` (600 KB) takes 2 seconds and 385 MB.

### Performance and size

Measured with PHP 8.3 on the command line, without opcache; times per rendering:

| | PHP Markdown 2.0.0 | league/commonmark 2.10.4 |
|---|---|---|
| W2's default pages (27 KB) | 2.5 ms | 10 ms |
| a 200 KB page | 22 ms | 72 ms |
| loading the library (once per request) | 4 ms, 11 files | 14 ms, 198 files |
| PHP code | 108 KB, 3 files | 942 KB, 387 files (league/commonmark 616 KB, nette/utils 215 KB, nette/schema 69 KB, the rest 42 KB) |

With opcache (as usual on servers) most of the loading time goes away; the rendering stays 3–4 times slower.

## Other libraries

- [Parsedown](https://github.com/erusev/parsedown) with [Parsedown Extra](https://github.com/erusev/parsedown-extra):
  1.8.0 and 0.9.0 were released in February 2026 after a long pause (1.7.4 was from 2019; the 2.0 rewrite has been in
  beta since 2022). Parsedown Extra aims at the Markdown Extra syntax. Not tested for this review.
- [cebe/markdown](https://github.com/cebe/markdown): no release since 2018.

## When to look again

If PHP Markdown gets a security problem which is not fixed upstream and cannot be fixed in `W2\MarkdownParser`, or if
features like task lists or strikethrough become important. `composer audit` reports known security problems of the
Composer package.
