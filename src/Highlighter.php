<?php
/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * Syntax highlighting of fenced code blocks.
 */

namespace W2;

if (!defined('W2APP')){ die('No direct access.'); }

/**
 * Highlights the code of fenced code blocks with a language (```php): the code is escaped, and keywords, strings,
 * comments etc. are put in <span class="hl-…"> elements (styled in index.css). It is meant as the
 * code_block_content_func of PHP Markdown, so the page view and anything else which uses toHTML() get the same
 * result, without JavaScript and without inline styles (which the Content-Security-Policy forbids).
 *
 * Each language has one regular expression whose alternatives are the tokens (named groups, see CLASSES), which is
 * applied once from left to right; the text between the tokens stays as it is. Words (group "w") are looked up in the
 * lists of the language. A construct which isn't closed (a comment, a string) ends at the end of the line or the code,
 * so every alternative either fails at once or takes what it starts, and the time grows linearly with the size.
 * The spans are never nested. Unknown languages, very large blocks and any failure of a regular expression give the
 * plain escaped code, exactly as PHP Markdown would without highlighting.
 */
final class Highlighter
{
	/** Larger code blocks (bytes) are not highlighted */
	public const MAX_SIZE = 65536;

	/** The classes of the named groups of the patterns ("w": a word of the lists, "tag": an HTML tag, see TAG) */
	private const CLASSES = [
		'c' => 'hl-c', 's' => 'hl-s', 's2' => 'hl-s', 'n' => 'hl-n', 'k' => 'hl-k', 't' => 'hl-t', 'v' => 'hl-v',
		'a' => 'hl-a', 'p' => 'hl-p', 'add' => 'hl-d-add', 'del' => 'hl-d-del',
	];

	/** Languages and their grammar (the pattern used) */
	private const LANGUAGES = [
		'php' => 'php', 'javascript' => 'js', 'typescript' => 'js', 'json' => 'json', 'python' => 'python',
		'bash' => 'bash', 'css' => 'css', 'html' => 'html', 'sql' => 'sql', 'yaml' => 'yaml', 'ini' => 'ini',
		'diff' => 'diff', 'c' => 'c', 'cpp' => 'c', 'java' => 'c', 'csharp' => 'c', 'go' => 'c', 'rust' => 'c',
		'kotlin' => 'c', 'swift' => 'c',
	];

	/** Other names of the languages (PHP Markdown only takes letters, digits, "-", "_" and ":" after the fence) */
	private const ALIASES = [
		'js' => 'javascript', 'jsx' => 'javascript', 'mjs' => 'javascript', 'node' => 'javascript',
		'ts' => 'typescript', 'tsx' => 'typescript', 'jsonc' => 'json', 'py' => 'python', 'python3' => 'python',
		'sh' => 'bash', 'shell' => 'bash', 'zsh' => 'bash', 'console' => 'bash', 'shellsession' => 'bash',
		'scss' => 'css', 'xml' => 'html', 'svg' => 'html', 'xhtml' => 'html', 'htm' => 'html',
		'mysql' => 'sql', 'postgresql' => 'sql', 'postgres' => 'sql', 'sqlite' => 'sql',
		'yml' => 'yaml', 'toml' => 'ini', 'cfg' => 'ini', 'conf' => 'ini', 'patch' => 'diff',
		'h' => 'c', 'hpp' => 'cpp', 'cc' => 'cpp', 'cxx' => 'cpp', 'cs' => 'csharp', 'golang' => 'go', 'rs' => 'rust',
		'kt' => 'kotlin', 'kts' => 'kotlin',
	];

	/** Languages whose words are compared without case (their lists are in lower case) */
	private const CASE_INSENSITIVE = ['php' => true, 'sql' => true, 'yaml' => true, 'ini' => true];

	/** The words of the languages by class: keywords, types and built-ins, constants, special variables */
	private const WORDS = [
		'php' => [
			'k' => 'abstract and as break case catch class clone const continue declare default do echo else elseif empty enddeclare endfor endforeach endif endswitch endwhile enum extends final finally fn for foreach function global goto if implements include include_once instanceof insteadof interface isset list match namespace new or print private protected public readonly require require_once return static switch throw trait try unset use var while xor yield',
			't' => 'array bool callable float int iterable mixed never object parent self string void',
			'n' => 'true false null',
		],
		'javascript' => [
			'k' => 'async await break case catch class const continue debugger default delete do else export extends finally for from function if import in instanceof let new of return static super switch this throw try typeof var void while with yield',
			't' => 'Array BigInt Boolean Date Error Function JSON Map Math Number Object Promise Proxy Reflect RegExp Set String Symbol WeakMap WeakSet console document globalThis window',
			'n' => 'true false null undefined NaN Infinity',
		],
		'typescript' => [
			'k' => 'abstract as async await break case catch class const continue debugger declare default delete do else enum export extends finally for from function if implements import in infer instanceof interface is keyof let module namespace new of override private protected public readonly return satisfies static super switch this throw try type typeof var void while with yield',
			't' => 'any bigint boolean never number object string symbol unknown Array Boolean Date Error Function JSON Map Math Number Object Promise Record Partial RegExp Set String Symbol console document globalThis window',
			'n' => 'true false null undefined NaN Infinity',
		],
		'json' => ['n' => 'true false null'],
		'python' => [
			'k' => 'and as assert async await break case class continue def del elif else except finally for from global if import in is lambda match nonlocal not or pass raise return try while with yield',
			't' => 'abs all any bool bytes dict enumerate Exception filter float input int isinstance iter KeyError len list map max min next object open print range repr set sorted str sum super tuple type TypeError ValueError zip',
			'n' => 'True False None',
			'v' => 'self cls',
		],
		'bash' => [
			'k' => 'if then else elif fi case esac for select while until do done in function time return break continue exit',
			't' => 'alias cd declare echo eval exec export false local printf pwd read readonly set shift source test trap true ulimit umask unset wait',
		],
		'sql' => [
			'k' => 'add all alter and as asc begin between by case check column commit constraint create cross database declare default delete desc distinct drop else end exists foreign from full function grant group having if in index inner insert intersect into is join key left like limit natural not on or order outer primary procedure recursive references replace returning revoke right rollback schema select set table then transaction trigger truncate union unique update using values view when where with',
			't' => 'avg bigint blob bool boolean cast char coalesce count date datetime decimal double float int integer json jsonb length lower max min now nullif numeric real serial smallint substring sum text time timestamp tinyint upper uuid varchar',
			'n' => 'null true false',
		],
		'yaml' => ['n' => 'true false yes no on off null'],
		'ini' => ['n' => 'true false yes no on off'],
		'c' => [
			'k' => 'auto break case const continue default do else enum extern for goto if inline register restrict return signed sizeof static struct switch typedef union unsigned volatile while',
			't' => 'bool char double FILE float int int8_t int16_t int32_t int64_t long short size_t uint8_t uint16_t uint32_t uint64_t void',
			'n' => 'NULL true false',
		],
		'cpp' => [
			'k' => 'alignas alignof and asm auto break case catch class concept const consteval constexpr constinit const_cast continue co_await co_return co_yield decltype default delete do dynamic_cast else enum explicit export extern final for friend goto if inline mutable namespace new noexcept not operator or override private protected public register reinterpret_cast requires return signed sizeof static static_assert static_cast struct switch template this thread_local throw try typedef typeid typename union unsigned using virtual volatile while xor',
			't' => 'bool char char8_t char16_t char32_t double float int long short size_t std string vector void wchar_t',
			'n' => 'nullptr NULL true false',
		],
		'java' => [
			'k' => 'abstract assert break case catch class const continue default do else enum extends final finally for goto if implements import instanceof interface native new package permits private protected public record return sealed static strictfp super switch synchronized this throw throws transient try var volatile while yield',
			't' => 'boolean byte char double float int long short void Boolean Double Exception Integer List Long Map Object Set String',
			'n' => 'true false null',
		],
		'csharp' => [
			'k' => 'abstract as async await base break case catch checked class const continue default delegate do else enum event explicit extern finally fixed for foreach get goto if implicit in init interface internal is lock namespace new operator out override params private protected public readonly record ref return sealed set sizeof stackalloc static struct switch this throw try typeof unchecked unsafe using var virtual volatile when where while yield',
			't' => 'bool byte char decimal double dynamic float int long object sbyte short string uint ulong ushort void Console Dictionary List String Task',
			'n' => 'true false null',
		],
		'go' => [
			'k' => 'break case chan const continue default defer else fallthrough for func go goto if import interface map package range return select struct switch type var',
			't' => 'any append bool byte cap close complex64 complex128 copy delete error float32 float64 int int8 int16 int32 int64 len make new panic print println recover rune string uint uint8 uint16 uint32 uint64 uintptr',
			'n' => 'true false nil iota',
		],
		'rust' => [
			'k' => 'as async await break const continue crate dyn else enum extern fn for if impl in let loop match mod move mut pub ref return self Self static struct super trait type unsafe use where while',
			't' => 'bool Box char Err f32 f64 i8 i16 i32 i64 i128 isize Ok Option Result Some str String u8 u16 u32 u64 u128 usize Vec',
			'n' => 'true false None',
		],
		'kotlin' => [
			'k' => 'abstract annotation as break by catch class companion constructor continue data do else enum final finally for fun get if import in init inline interface internal is lateinit object open override package private protected public return sealed set super suspend this throw try typealias val var vararg when where while',
			't' => 'Any Array Boolean Byte Char Double Float Int List Long Map Nothing Set Short String Unit',
			'n' => 'true false null',
		],
		'swift' => [
			'k' => 'any as associatedtype async await break case catch class continue default defer deinit do else enum extension fallthrough fileprivate final for func guard if import in init inout internal is lazy let mutating open operator override private protocol public repeat rethrows return self Self some static struct subscript super switch throw throws try typealias var weak where while',
			't' => 'Array Bool Character Dictionary Double Float Int Int8 Int16 Int32 Int64 Optional Set String UInt Void',
			'n' => 'true false nil',
		],
	];

	// The patterns, one per grammar. Flags: x (spaces and # comments are ignored), s (. includes line breaks),
	// m (^ is the start of a line), u (UTF-8, so no token ends inside a character).

	/** A number: hexadecimal, binary, decimal with fraction and exponent, and a suffix (10u32, 1.5f, 12px, 50%) */
	private const NUMBER = '(?<!\w)(?:0[xX][\da-fA-F_]++|0[bB][01_]++|(?:\d[\d_]*+(?:\.\d[\d_]*+)?|\.\d[\d_]*+)(?:[eE][+-]?\d++)?)[\w%]*+';

	/** "…" and '…' with backslash escapes, up to the end of the line */
	private const QUOTED = '"(?:[^"\\\\\n]++|\\\\.?)*+"?|\'(?:[^\'\\\\\n]++|\\\\.?)*+\'?';

	/** // and /* comments */
	private const SLASH_COMMENTS = '//[^\n]*+|/\*.*?(?:\*/|\z)';

	private const PHP = '~
		(?<p><\?(?:php|=)?|\?>)
		|(?<c>(?://|\#(?!\[))[^\n]*+|/\*.*?(?:\*/|\z))
		|(?<s>"(?:[^"\\\\]++|\\\\.?)*+"?|\'(?:[^\'\\\\]++|\\\\.?)*+\'?
			|<<<[ \t]*+(["\']?)([A-Za-z_]\w*+)\g{-2}\n.*?(?:^[ \t]*+\g{-1}\b|\z))
		|(?<v>\$++[\p{L}_][\p{L}\p{N}_]*+)
		|(?<p2>\#\[)
		|(?<n>' . self::NUMBER . ')
		|(?<w>[\p{L}_][\p{L}\p{N}_]*+)
		~xsmu';

	private const JS = '~
		(?<c>' . self::SLASH_COMMENTS . ')
		|(?<s>' . self::QUOTED . '|`(?:[^`\\\\]++|\\\\.?)*+`?)
		|(?<n>' . self::NUMBER . ')
		|(?<w>[\p{L}_$][\p{L}\p{N}_$]*+)
		~xsmu';

	private const JSON = '~
		(?<c>' . self::SLASH_COMMENTS . ')
		|(?<a>"(?:[^"\\\\\n]++|\\\\.?)*+")(?=\s*+:)
		|(?<s>' . self::QUOTED . ')
		|(?<n>-?' . self::NUMBER . ')
		|(?<w>[A-Za-z_]\w*+)
		~xsmu';

	private const PYTHON = '~
		(?<c>\#[^\n]*+)
		|(?<s>(?i:[rbuf]{0,2})(?:""".*?(?:"""|\z)|\'\'\'.*?(?:\'\'\'|\z)|' . self::QUOTED . '))
		|(?<p>@[\p{L}_][\p{L}\p{N}_.]*+)
		|(?<n>' . self::NUMBER . ')
		|(?<w>[\p{L}_][\p{L}\p{N}_]*+)
		~xsmu';

	private const BASH = '~
		(?<p>^\$(?=[ \t]))
		|(?<c>(?<![^\s;|&(])\#[^\n]*+)
		|(?<s>"(?:[^"\\\\]++|\\\\.?)*+"?|\'[^\']*+\'?)
		|(?<v>\$(?:\{[^}\n]*+\}?|[A-Za-z_]\w*+|[0-9\#?$!@*-])|[A-Za-z_]\w*+(?==))
		|(?<![\w/.-])(?<w>[A-Za-z_][\w-]*+)
		~xsmu';

	private const CSS = '~
		(?<c>/\*.*?(?:\*/|\z)|(?<!\S)//[^\n]*+)
		|(?<s>' . self::QUOTED . '|(?<![\w-])url\([^)\n]*+\)?)
		|(?<k>@[\w-]++|!(?i:important))
		|(?<v>--[\w-]++|\$[\w-]++)
		|(?<n>\#[\da-fA-F]{3,8}(?![\w-])|(?<![\w-])-?' . self::NUMBER . ')
		|(?<![\w-])(?<a>-?[A-Za-z][\w-]*+)(?=[ \t]*+:(?:\s|(?![\w:/-])))
		|[\w-]++
		~xsmu';

	private const HTML = '~
		(?<c><!--.*?(?:-->|\z))
		|(?<s><!\[CDATA\[.*?(?:\]\]>|\z))
		|(?<p><![A-Za-z][^>]*+>?|<\?.*?(?:\?>|\z))
		|(?<tag></?[A-Za-z][\w:.-]*+(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>?)
		|(?<n>&(?:[A-Za-z][A-Za-z\d]*+|\#\d++|\#[xX][\da-fA-F]++);)
		~xsmu';

	/** The parts of an HTML tag (a token "tag" of HTML) */
	private const TAG = '~
		\A</?(?<k>[\w:.-]++)
		|(?<s>"[^"]*+"?|\'[^\']*+\'?)
		|=[ \t]*+(?<s2>[^\s"\'=<>`]++)
		|(?<a>[^\s"\'>/=]++)
		~xsmu';

	private const SQL = '~
		(?<c>--[^\n]*+|/\*.*?(?:\*/|\z))
		|(?<s>\'(?:[^\']++|\'\')*+\'?)
		|"[^"\n]*+"?|`[^`\n]*+`?
		|(?<v>(?<!:)[:@$][A-Za-z_]\w*+|\$\d++|\?)
		|(?<n>' . self::NUMBER . ')
		|(?<w>[A-Za-z_]\w*+)
		~xsmu';

	private const YAML = '~
		(?<c>(?<!\S)\#[^\n]*+)
		|(?<p>^(?:---|\.\.\.)(?=\s|\z))
		|^[ \t]*+(?:-[ \t]++)*+(?<a>[^\s\#\'"?:,\[\]{}|>&*!%@`-][^:\n]*?|"[^"\n]*+"|\'[^\'\n]*+\')(?=[ \t]*+:(?:\s|\z))
		|(?<s>' . self::QUOTED . ')
		|(?<v>(?<![^\s\[{,])[&*][\w-]++)
		|(?<t>(?<!\S)!!?[\w/-]*+)
		|(?<![^\s\[{,])(?:(?<n>-?' . self::NUMBER . ')|(?<w>[A-Za-z_][\w-]*+))(?=[ \t]*+(?:[,\]}\n]|\#|\z))
		~xsmu';

	private const INI = '~
		(?<c>(?<!\S)[;\#][^\n]*+)
		|^[ \t]*+(?<k>\[[^\]\n]*+\]*+)
		|^[ \t]*+(?<a>[^\s=:;\#\[][^=:\n]*?)(?=[ \t]*+[=:])
		|(?<s>""".*?(?:"""|\z)|\'\'\'.*?(?:\'\'\'|\z)|' . self::QUOTED . ')
		|(?<n>-?' . self::NUMBER . ')
		|(?<w>[A-Za-z_]\w*+)
		~xsmu';

	private const DIFF = '~
		(?<p>^(?:(?:\+\+\+|---)[ \t][^\n]*+|@@[^\n]*+|(?:diff|index|similarity|rename|new|deleted|old)\b[^\n]*+))
		|(?<add>^\+[^\n]*+)
		|(?<del>^-[^\n]*+)
		|(?<c>^\\\\[^\n]*+)
		~xsmu';

	/** C and the languages with a similar syntax: comments, strings of all of them, preprocessor, attributes, annotations */
	private const C = '~
		(?<c>' . self::SLASH_COMMENTS . ')
		|(?<s>R"([^()\\\\\s]{0,16})\(.*?(?:\)\g{-1}"|\z)
			|b?r(\#*+)".*?(?:"\g{-1}|\z)
			|\$?@"(?:[^"]++|"")*+"?
			|\$?""".*?(?:"""|\z)
			|\$?@?"(?:[^"\\\\\n]++|\\\\.?)*+"?
			|`[^`]*+`?
			|\'(?:\\\\[^\n][^\'\n]{0,9}|[^\'\\\\\n])\')
		|(?<p>(?<!\S)\#[ \t]*+[A-Za-z]++|\#!?\[[^\]\n]*+\]?|@[A-Za-z_][\w.]*+)
		|(?<n>' . self::NUMBER . ')
		|(?<w>[\p{L}_][\p{L}\p{N}_]*+)
		~xsmu';

	/** @var array<string, array<string, string>> word => class, by language */
	private static array $classOfWord = [];

	/**
	 * The code as HTML (escaped like PHP Markdown does), highlighted if the language is known. The signature is
	 * that of code_block_content_func of PHP Markdown: $language is the name after the fence (empty for indented code
	 * blocks and fences without a name).
	 */
	public static function highlight(string $code, string $language = ''): string
	{
		$name = self::language($language);
		if ($name === null || strlen($code) > self::MAX_SIZE) {
			return self::escape($code);
		}
		return self::tokens(self::LANGUAGES[$name], $code, $name) ?? self::escape($code);
	}

	/** The language of a name or alias (case doesn't matter, "language-" may be in front), null if unknown */
	public static function language(string $name): ?string
	{
		$name = strtolower(ltrim($name, '.'));
		if (str_starts_with($name, 'language-')) {
			$name = substr($name, strlen('language-'));
		}
		$name = self::ALIASES[$name] ?? $name;
		return isset(self::LANGUAGES[$name]) ? $name : null;
	}

	private static function escape(string $text): string
	{
		return htmlspecialchars($text, ENT_NOQUOTES);
	}

	/** The code with the tokens of a grammar highlighted, null if a regular expression failed */
	private static function tokens(string $grammar, string $code, string $language): ?string
	{
		$html = '';
		$position = 0;
		$failed = false;
		// (preg_replace_callback() goes through the matches one by one: preg_match_all() would hold all of them, with
		// all groups, which needs much more memory; the result of the replacement is not used)
		$callback = static function (array $match) use ($code, $language, &$html, &$position, &$failed): string
		{
			// the first named group which matched is the token; the rest of the match is plain text
			foreach ($match as $group => [$text, $offset]) {
				if (is_int($group) || $text === null || $text === '') {
					continue;
				}
				$token = self::token($group, $text, $language);
				$failed = $failed || $token === null;
				$html .= self::escape(substr($code, $position, $offset - $position)) . $token;
				$position = $offset + strlen($text);
				break;
			}
			return '';
		};
		$flags = PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL;
		// (the patterns are constants, chosen by name: a pattern in a variable wouldn't pass SourceGuardTest)
		$result = match ($grammar) {
			'php' => preg_replace_callback(self::PHP, $callback, $code, flags: $flags),
			'js' => preg_replace_callback(self::JS, $callback, $code, flags: $flags),
			'json' => preg_replace_callback(self::JSON, $callback, $code, flags: $flags),
			'python' => preg_replace_callback(self::PYTHON, $callback, $code, flags: $flags),
			'bash' => preg_replace_callback(self::BASH, $callback, $code, flags: $flags),
			'css' => preg_replace_callback(self::CSS, $callback, $code, flags: $flags),
			'html' => preg_replace_callback(self::HTML, $callback, $code, flags: $flags),
			'tag' => preg_replace_callback(self::TAG, $callback, $code, flags: $flags),
			'sql' => preg_replace_callback(self::SQL, $callback, $code, flags: $flags),
			'yaml' => preg_replace_callback(self::YAML, $callback, $code, flags: $flags),
			'ini' => preg_replace_callback(self::INI, $callback, $code, flags: $flags),
			'diff' => preg_replace_callback(self::DIFF, $callback, $code, flags: $flags),
			'c' => preg_replace_callback(self::C, $callback, $code, flags: $flags),
		};
		if ($result === null || $failed) {
			return null;
		}
		return $html . self::escape(substr($code, $position));
	}

	/** A token as HTML, null if it couldn't be highlighted */
	private static function token(string $group, string $text, string $language): ?string
	{
		if ($group === 'tag') {
			return self::tokens('tag', $text, $language);
		}
		$class = $group === 'w' ? self::classOfWord($text, $language) : (self::CLASSES[rtrim($group, '2')] ?? null);
		return $class === null ? self::escape($text) : '<span class="' . $class . '">' . self::escape($text) . '</span>';
	}

	private static function classOfWord(string $word, string $language): ?string
	{
		if (!isset(self::$classOfWord[$language])) {
			self::$classOfWord[$language] = [];
			foreach (self::WORDS[$language] ?? [] as $group => $words) {
				foreach (explode(' ', $words) as $listed) {
					self::$classOfWord[$language][$listed] ??= self::CLASSES[$group];
				}
			}
		}
		return self::$classOfWord[$language][isset(self::CASE_INSENSITIVE[$language]) ? strtolower($word) : $word] ?? null;
	}
}
