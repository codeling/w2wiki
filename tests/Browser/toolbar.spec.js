// SPDX-License-Identifier: MIT

// The formatting toolbar above the text of the editor (format-bar.php, setupFormatBar() in wiki.js): every button, the
// menu of the headings, the dialog for tables, undoing, the draft and the preview, the focus and the selection, the
// keyboard, narrow screens, the dark scheme, and the editor without a script
const { test, expect } = require('@playwright/test');
const { expectReadable } = require('./helpers');

/** Script errors, and the messages about the Content-Security-Policy, which must not happen */
function watchProblems(page) {
  const problems = [];
  page.on('pageerror', (error) => problems.push(error.message));
  page.on('console', (message) => { if (message.type() === 'error' || /content security policy|refused to/i.test(message.text())) problems.push(message.text()); });
  return problems;
}

/** Opens the editor of a new page with the text, and selects the part from start to end */
async function editorWith(page, text, start, end = start) {
  await page.goto('/index.php/' + encodeURIComponent('Toolbar page'));
  await page.fill('#text', text);
  await select(page, start, end);
}

async function select(page, start, end = start) {
  await page.evaluate(([s, e]) => {
    const textArea = document.getElementById('text');
    textArea.focus();
    textArea.setSelectionRange(s, e);
  }, [start, end]);
}

/** Selects the text (the first time it is in the text area) */
async function selectText(page, part) {
  const start = await page.locator('#text').evaluate((t, part) => t.value.indexOf(part), part);
  expect(start, `"${part}" is in the text`).toBeGreaterThanOrEqual(0);
  await select(page, start, start + part.length);
}

/** The text with the selection marked by [ and ] (or | for the cursor) */
async function marked(page) {
  return page.evaluate(() => {
    const t = document.getElementById('text');
    const v = t.value;
    return t.selectionStart === t.selectionEnd ? v.slice(0, t.selectionStart) + '|' + v.slice(t.selectionStart)
      : v.slice(0, t.selectionStart) + '[' + v.slice(t.selectionStart, t.selectionEnd) + ']' + v.slice(t.selectionEnd);
  });
}

const button = (page, format) => page.locator(`#formatbar button[data-format="${format}"]`);
const focused = (page) => page.evaluate(() => document.activeElement.id || document.activeElement.dataset.format || document.activeElement.dataset.level || document.activeElement.localName);

async function heading(page, level) {
  await page.click('#headingbutton');
  await expect(page.locator('#headingmenu')).toBeVisible();
  await page.click(`#headingmenu [data-level="${level}"]`);
  await expect(page.locator('#headingmenu')).toBeHidden();
}

async function insertTable(page, columns, rows) {
  await page.click('#tablebutton');
  await expect(page.locator('#tabledialog')).toBeVisible();
  if (columns !== undefined) await page.fill('#tablecolumns', String(columns));
  if (rows !== undefined) await page.fill('#tablerows', String(rows));
  await page.click('#tableinsert');
}

test.beforeEach(async ({ page }) => {
  // (leaving the editor with changes asks for confirmation; the tests always leave)
  page.on('dialog', (dialog) => dialog.accept().catch(() => {}));
});

test('the toolbar is shown above the text, in the editor of new and existing pages', async ({ page }) => {
  const problems = watchProblems(page);
  for (const url of ['/index.php?action=new', '/index.php?action=edit&page=Home', '/index.php/' + encodeURIComponent('Toolbar page')]) {
    await page.goto(url);
    await expect(page.locator('#formatbar')).toBeVisible();
    await expect(page.locator('#formatbar')).toHaveAttribute('role', 'toolbar');
    const bar = await page.locator('#formatbar').boundingBox();
    const text = await page.locator('#text').boundingBox();
    expect(bar.y + bar.height).toBeLessThanOrEqual(text.y);
    expect(text.y - (bar.y + bar.height)).toBeLessThan(15);
  }
  expect(problems).toEqual([]);
});

test('bold and italic: the same as Ctrl+B and Ctrl+I, also removing them again; the text keeps the focus', async ({ page }) => {
  const problems = watchProblems(page);
  await editorWith(page, 'some words here', 5, 10);
  await button(page, 'bold').click();
  expect(await marked(page)).toBe('some **[words]** here');
  expect(await focused(page)).toBe('text');
  await button(page, 'italic').click();
  expect(await marked(page)).toBe('some ***[words]*** here');
  await button(page, 'bold').click();
  expect(await marked(page)).toBe('some *[words]* here');
  await button(page, 'italic').click();
  expect(await marked(page)).toBe('some [words] here');
  // (without a selection: the markers, with the cursor between them, to type the text)
  await editorWith(page, 'x ', 2);
  await button(page, 'bold').click();
  await page.keyboard.type('strong');
  await expect(page.locator('#text')).toHaveValue('x **strong**');
  expect(problems).toEqual([]);
});

test('every change is one step to undo with Ctrl+Z', async ({ page }) => {
  const original = 'first line\nsecond line';
  for (const format of ['bold', 'italic', 'link', 'wikilink', 'bullets', 'numbers', 'quote', 'code', 'rule']) {
    await editorWith(page, original, 0, original.length);
    await button(page, format).click();
    expect(await page.locator('#text').inputValue(), format).not.toBe(original);
    await page.keyboard.press('Control+z');
    await expect(page.locator('#text'), format).toHaveValue(original);
  }
  await editorWith(page, original, 0);
  await heading(page, 2);
  await expect(page.locator('#text')).toHaveValue('## first line\nsecond line');
  await page.keyboard.press('Control+z');
  await expect(page.locator('#text')).toHaveValue(original);
  await editorWith(page, original, original.length);
  await insertTable(page, 2, 2);
  await expect(page.locator('#text')).toHaveValue(/\| Column 1 \| Column 2 \|/);
  await page.keyboard.press('Control+z');
  await expect(page.locator('#text')).toHaveValue(original);
});

test('headings: the menu sets the level of the lines, replaces another one, and removes it when chosen again', async ({ page }) => {
  await editorWith(page, 'Title\ntext', 2);
  await heading(page, 1);
  expect(await marked(page)).toBe('# Ti|tle\ntext');
  await heading(page, 3);
  expect(await marked(page)).toBe('### Ti|tle\ntext');
  // (the level of the line is checked in the menu)
  await page.click('#headingbutton');
  await expect(page.locator('#headingmenu [data-level="3"]')).toHaveAttribute('aria-checked', 'true');
  await expect(page.locator('#headingmenu [data-level="1"]')).toHaveAttribute('aria-checked', 'false');
  await page.click(`#headingmenu [data-level="3"]`);
  expect(await marked(page)).toBe('Ti|tle\ntext');
  expect(await focused(page)).toBe('text');
  // (several lines, the empty one between them stays as it is; a heading without a space after # is one too)
  await editorWith(page, 'one\n\n#two\nthree', 0, 15);
  await heading(page, 2);
  expect(await marked(page)).toBe('[## one\n\n## two\n## three]');
  await heading(page, 2);
  expect(await marked(page)).toBe('[one\n\ntwo\nthree]');
  // (an empty line: the heading starts there)
  await editorWith(page, 'text\n\n', 6);
  await heading(page, 4);
  await page.keyboard.type('New');
  await expect(page.locator('#text')).toHaveValue('text\n\n#### New');
});

test('the menu of the headings: keyboard, Esc, a click next to it', async ({ page }) => {
  await editorWith(page, 'Title', 0);
  await page.keyboard.press('Alt+F10');
  await page.keyboard.press('ArrowRight');
  await page.keyboard.press('ArrowRight');
  expect(await focused(page)).toBe('headingbutton');
  await page.keyboard.press('ArrowDown');
  await expect(page.locator('#headingbutton')).toHaveAttribute('aria-expanded', 'true');
  expect(await focused(page)).toBe('1');
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('ArrowDown');
  expect(await focused(page)).toBe('3');
  await page.keyboard.press('ArrowUp');
  await page.keyboard.press('Enter');
  await expect(page.locator('#headingmenu')).toBeHidden();
  await expect(page.locator('#text')).toHaveValue('## Title');
  expect(await focused(page)).toBe('text');
  // (Esc closes it and goes back to its button; Enter on the button opens it at the level of the line)
  await page.keyboard.press('Alt+F10');
  expect(await focused(page)).toBe('headingbutton');
  await page.keyboard.press('Enter');
  expect(await focused(page)).toBe('2');
  await page.keyboard.press('Escape');
  await expect(page.locator('#headingmenu')).toBeHidden();
  expect(await focused(page)).toBe('headingbutton');
  await expect(page.locator('#text')).toHaveValue('## Title');
  // (a click next to the menu closes it)
  await page.click('#headingbutton');
  await expect(page.locator('#headingmenu')).toBeVisible();
  await page.locator('.titlebar .title').click();
  await expect(page.locator('#headingmenu')).toBeHidden();
  await expect(page.locator('#headingbutton')).toHaveAttribute('aria-expanded', 'false');
});

test('lists: bulleted and numbered lists of the lines, and back', async ({ page }) => {
  await editorWith(page, 'apples\npears\n\nplums\nafter', 0, 19);
  await button(page, 'bullets').click();
  expect(await marked(page)).toBe('[- apples\n- pears\n\n- plums]\nafter');
  await button(page, 'numbers').click();
  expect(await marked(page)).toBe('[1. apples\n2. pears\n\n3. plums]\nafter');
  await button(page, 'numbers').click();
  expect(await marked(page)).toBe('[apples\npears\n\nplums]\nafter');
  // (whole lines selected with the line break after them: the next line is not part of it; indentation stays)
  await editorWith(page, '* one\n  * two\nnext', 0, 14);
  await button(page, 'numbers').click();
  expect(await marked(page)).toBe('[1. one\n  2. two\n]next');
  // (the cursor in an empty line: the item starts there)
  await editorWith(page, '', 0);
  await button(page, 'bullets').click();
  await page.keyboard.type('item');
  await expect(page.locator('#text')).toHaveValue('- item');
  await editorWith(page, 'one item', 3);
  await button(page, 'numbers').click();
  expect(await marked(page)).toBe('1. one| item');
});

test('quote: the lines become one quote, and back', async ({ page }) => {
  await editorWith(page, 'said\n\nmore\n\nafter', 0, 10);
  await button(page, 'quote').click();
  expect(await marked(page)).toBe('[> said\n>\n> more]\n\nafter');
  await button(page, 'quote').click();
  expect(await marked(page)).toBe('[said\n\nmore]\n\nafter');
});

test('code: inline in a line, a fenced block for lines or an empty line, and back', async ({ page }) => {
  await editorWith(page, 'run ls -l now', 4, 9);
  await button(page, 'code').click();
  expect(await marked(page)).toBe('run `[ls -l]` now');
  await button(page, 'code').click();
  expect(await marked(page)).toBe('run [ls -l] now');
  // (with a backtick in it: two, with spaces)
  await editorWith(page, 'a`b', 0, 3);
  await button(page, 'code').click();
  expect(await marked(page)).toBe('`` [a`b] ``');
  // (several lines: a block, with the cursor after ``` to type the language)
  await editorWith(page, 'text\necho 1\necho 2\nmore', 5, 18);
  await button(page, 'code').click();
  expect(await marked(page)).toBe('text\n\n```|\necho 1\necho 2\n```\n\nmore');
  await page.keyboard.type('bash');
  await expect(page.locator('#text')).toHaveValue('text\n\n```bash\necho 1\necho 2\n```\n\nmore');
  // (the block with its fences selected: the fences go)
  await selectText(page, '```bash\necho 1\necho 2\n```');
  await button(page, 'code').click();
  expect(await marked(page)).toBe('text\n\n[echo 1\necho 2]\n\nmore');
  // (an empty line: an empty block)
  await editorWith(page, 'text\n', 5);
  await button(page, 'code').click();
  expect(await marked(page)).toBe('text\n\n```|\n```\n');
  await page.keyboard.type('php');
  await page.keyboard.press('Enter');
  await page.keyboard.type('echo 1;');
  await expect(page.locator('#text')).toHaveValue('text\n\n```php\necho 1;\n```\n');
});

test('rule: a horizontal rule as a block of its own after the line', async ({ page }) => {
  await editorWith(page, 'above\nbelow', 2);
  await button(page, 'rule').click();
  expect(await marked(page)).toBe('above\n\n---\n|\nbelow');
  await editorWith(page, 'text', 4);
  await button(page, 'rule').click();
  expect(await marked(page)).toBe('text\n\n---\n|');
  // (at the start of a line: before it)
  await editorWith(page, 'first\n\nsecond', 7);
  await button(page, 'rule').click();
  expect(await marked(page)).toBe('first\n\n---\n\n|second');
});

test('links: the link button is Ctrl+K, the other one makes a link to a page', async ({ page }) => {
  await editorWith(page, 'see the docs here', 8, 12);
  await button(page, 'link').click();
  expect(await marked(page)).toBe('see the [docs]([url]) here');
  await editorWith(page, 'see Home here', 4, 8);
  await button(page, 'wikilink').click();
  expect(await marked(page)).toBe('see [[[Home]]] here');
  await button(page, 'wikilink').click();
  expect(await marked(page)).toBe('see [Home] here');
  await editorWith(page, 'see ', 4);
  await button(page, 'wikilink').click();
  await page.keyboard.type('Home');
  await expect(page.locator('#text')).toHaveValue('see [[Home]]');
});

test('table: the dialog asks for the size, and the table is inserted as a block with the first header selected', async ({ page }) => {
  const problems = watchProblems(page);
  await editorWith(page, 'before\nafter', 3);
  await page.click('#tablebutton');
  await expect(page.locator('#tabledialog')).toBeVisible();
  // (the default: 3 columns, 3 rows; the number of columns is selected, to type another one)
  await expect(page.locator('#tablecolumns')).toHaveValue('3');
  await expect(page.locator('#tablerows')).toHaveValue('3');
  expect(await focused(page)).toBe('tablecolumns');
  await page.keyboard.press('Enter');
  await expect(page.locator('#tabledialog')).toBeHidden();
  const table = [
    '| Column 1 | Column 2 | Column 3 |',
    '| -------- | -------- | -------- |',
    '|          |          |          |',
    '|          |          |          |',
    '|          |          |          |',
  ].join('\n');
  expect(await marked(page)).toBe('before\n\n| [Column 1]' + table.slice(10) + '\n\nafter');
  expect(await focused(page)).toBe('text');
  await page.keyboard.type('Name');
  await expect(page.locator('#text')).toHaveValue(/^before\n\n\| Name \| Column 2 \|/);

  // (other sizes; the dialog keeps the last one)
  await editorWith(page, '', 0);
  await insertTable(page, 1, 1);
  await expect(page.locator('#text')).toHaveValue('| Column 1 |\n| -------- |\n|          |\n');
  await page.click('#tablebutton');
  await expect(page.locator('#tablecolumns')).toHaveValue('1');
  await page.keyboard.press('Escape');
  await editorWith(page, 'end', 3);
  await insertTable(page, 20, 100);
  const lines = (await page.locator('#text').inputValue()).split('\n');
  expect(lines.length).toBe(2 + 2 + 100 + 1);
  expect(lines[2].split('|').length - 2).toBe(20);
  expect(lines[2]).toContain('| Column 20 |');
  expect(lines[3]).toMatch(/^\| -{8} \|.* \| -{9} \|$/);
  expect(problems).toEqual([]);
});

test('table: sizes outside the limits keep the dialog open; Cancel and Esc insert nothing', async ({ page }) => {
  await editorWith(page, 'text', 2);
  await page.click('#tablebutton');
  for (const [columns, rows] of [[21, 3], [0, 3], [3, 101], [3, 0], ['', 3]]) {
    await page.fill('#tablecolumns', String(columns));
    await page.fill('#tablerows', String(rows));
    await page.click('#tableinsert');
    await expect(page.locator('#tabledialog'), `${columns} × ${rows}`).toBeVisible();
    await expect(page.locator('#tabledialog input:invalid')).toHaveCount(1);
  }
  await page.click('#tablecancel');
  await expect(page.locator('#tabledialog')).toBeHidden();
  await expect(page.locator('#text')).toHaveValue('text');
  expect(await marked(page)).toBe('te|xt');
  expect(await focused(page)).toBe('text');
  await page.click('#tablebutton');
  await page.fill('#tablecolumns', '5');
  await page.keyboard.press('Escape');
  await expect(page.locator('#tabledialog')).toBeHidden();
  await expect(page.locator('#text')).toHaveValue('text');
  expect(await focused(page)).toBe('text');
  // (while the dialog is open, the keys of the shortcuts are not used)
  await page.click('#tablebutton');
  await page.keyboard.press('?');
  await expect(page.locator('#shortcuts')).toBeHidden();
  await page.click('#tablecancel');
});

test('table: lines separated by tabs (from a spreadsheet) or commas become a table', async ({ page }) => {
  await editorWith(page, 'Name\tAge\nAnna\t31\nBob|B\t4\nafter', 0, 25);
  await page.click('#tablebutton');
  await expect(page.locator('#tabledialog')).toBeHidden();
  await expect(page.locator('#text')).toHaveValue('| Name   | Age |\n| ------ | --- |\n| Anna   | 31  |\n| Bob\\|B | 4   |\n\nafter');
  await editorWith(page, 'a, b\n1, 2', 0, 9);
  await page.click('#tablebutton');
  await expect(page.locator('#text')).toHaveValue('| a   | b   |\n| --- | --- |\n| 1   | 2   |\n');
  // (a selection of other text: the dialog, and the table after it)
  await editorWith(page, 'just text', 0, 9);
  await insertTable(page, 1, 1);
  await expect(page.locator('#text')).toHaveValue('just text\n\n| Column 1 |\n| -------- |\n|          |\n');
});

test('the changes count as typing: the draft is kept and the preview shows them', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.goto('/index.php/' + encodeURIComponent('Toolbar draft'));
  await page.click('#previewbutton');
  await page.fill('#text', 'Title\n\nwords');
  await select(page, 0);
  await heading(page, 2);
  await expect(page.locator('#preview h2')).toHaveText('Title');
  await selectText(page, 'words');
  await button(page, 'italic').click();
  await expect(page.locator('#preview em')).toHaveText('words');
  await select(page, (await page.locator('#text').inputValue()).length);
  await insertTable(page, 2, 1);
  await expect(page.locator('#preview th')).toHaveText(['Column 1', 'Column 2']);
  await page.waitForTimeout(100);
  const draft = await page.evaluate(() => JSON.parse(localStorage.getItem(Object.keys(localStorage).find((key) => key.startsWith('w2wiki-draft:')))));
  expect(draft.text).toContain('## Title\n\n*words*\n\n| Column 1 | Column 2 |');
  // (leaving the page asks first)
  const dialogs = [];
  page.removeAllListeners('dialog');
  page.on('dialog', async (dialog) => { dialogs.push(dialog.type()); await dialog.dismiss(); });
  await page.click('a[href*="action=all"]', { noWaitAfter: true });
  await page.waitForTimeout(500);
  expect(dialogs).toEqual(['beforeunload']);
});

test('keyboard: one stop of Tab, arrow keys between the buttons, Esc back to the text, Alt+F10 from the text', async ({ page }) => {
  await page.goto('/index.php?action=new');
  await page.fill('#text', 'word');
  await page.click('#title');
  await page.keyboard.press('Tab');
  expect(await focused(page)).toBe('bold');
  await page.keyboard.press('ArrowRight');
  expect(await focused(page)).toBe('italic');
  await page.keyboard.press('ArrowLeft');
  await page.keyboard.press('ArrowLeft');
  expect(await focused(page)).toBe('drawer-control');
  await page.keyboard.press('Home');
  expect(await focused(page)).toBe('bold');
  await page.keyboard.press('End');
  expect(await focused(page)).toBe('drawer-control');
  const tabStops = await page.locator('#formatbar button[tabindex="0"]').count();
  expect(tabStops).toBe(1);
  // (Tab goes on to the text)
  await page.keyboard.press('Tab');
  expect(await focused(page)).toBe('text');
  // (Shift+Tab comes back to the button which had the focus; Esc first, as Shift+Tab outdents in the text)
  await page.keyboard.press('Escape');
  await page.keyboard.press('Shift+Tab');
  expect(await focused(page)).toBe('drawer-control');
  await page.keyboard.press('Escape');
  expect(await focused(page)).toBe('text');
  // (Enter on a button formats the selection, and the text gets the focus)
  await select(page, 0, 4);
  await page.keyboard.press('Alt+F10');
  expect(await focused(page)).toBe('drawer-control');
  await page.keyboard.press('Home');
  await page.keyboard.press('Enter');
  expect(await marked(page)).toBe('**[word]**');
  expect(await focused(page)).toBe('text');
  await page.keyboard.press('Alt+F10');
  await page.keyboard.press('ArrowRight');
  await page.keyboard.press(' ');
  expect(await marked(page)).toBe('***[word]***');
});

test('help and upload: the last buttons show the Markdown help and the dialog to upload files', async ({ page }) => {
  const problems = watchProblems(page);
  await page.goto('/index.php?action=new');
  await expect(page.locator('#drawer')).toBeHidden();
  await page.click('#drawer-control');
  await expect(page.locator('#drawer')).toBeVisible();
  await expect(page.locator('#drawer-control')).toHaveAttribute('aria-expanded', 'true');
  await expect(page.locator('#drawer')).toContainText('The buttons above the text');
  await page.click('#drawer svg.rightaligned');
  await expect(page.locator('#drawer')).toBeHidden();
  await expect(page.locator('#drawer-control')).toHaveAttribute('aria-expanded', 'false');
  // (the help button is at the end, apart from the formatting buttons)
  const help = await page.locator('#drawer-control').boundingBox();
  const table = await page.locator('#tablebutton').boundingBox();
  expect(help.x - (table.x + table.width)).toBeGreaterThan(100);
  await page.click('#uploadbutton');
  await expect(page.locator('#uploaddialog')).toBeVisible();
  await page.click('#uploadcancel');
  // (the toolbar of the page has neither of them any more)
  await expect(page.locator('.toolbar #drawer-control, .toolbar #uploadbutton, .toolbar .icongroup')).toHaveCount(0);
  expect(problems).toEqual([]);
});

for (const [label, size] of [['phone', { width: 360, height: 640 }], ['small phone', { width: 320, height: 560 }]]) {
  test(`${label}: the toolbar wraps within the window, and the text still fills the rest`, async ({ page }) => {
    await page.setViewportSize(size);
    await page.goto('/index.php?action=new');
    await page.fill('#text', Array.from({ length: 300 }, (_, i) => 'line ' + i).join('\n'));
    for (const item of await page.locator('#formatbar > button, #formatbar .formatmenu').all()) {
      const box = await item.boundingBox();
      expect(box.x + box.width).toBeLessThanOrEqual(size.width);
    }
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
    expect(await page.evaluate(() => document.documentElement.scrollHeight - window.innerHeight)).toBeLessThanOrEqual(0);
    const save = await page.locator('#save').boundingBox();
    expect(save.y + save.height).toBeLessThanOrEqual(size.height);
    // (the buttons can still be used)
    await select(page, 0, 6);
    await button(page, 'bold').click();
    expect((await page.locator('#text').inputValue()).startsWith('**line 0**')).toBe(true);
  });
}

test('narrow screen: the toolbar goes with the text when the preview takes its place; side by side it stays', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 700 });
  await page.goto('/index.php?action=new');
  await page.click('#previewbutton');
  await expect(page.locator('#text')).toBeHidden();
  await expect(page.locator('#formatbar')).toBeHidden();
  await page.click('#previewbutton');
  await expect(page.locator('#formatbar')).toBeVisible();
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.click('#previewbutton');
  await expect(page.locator('#preview')).toBeVisible();
  await expect(page.locator('#formatbar')).toBeVisible();
  const bar = await page.locator('#formatbar').boundingBox();
  const preview = await page.locator('#preview').boundingBox();
  expect(bar.y + bar.height).toBeLessThanOrEqual(preview.y);
});

test.describe('dark color scheme', () => {
  test.use({ colorScheme: 'dark' });

  test('the buttons, the menu and the dialog are readable', async ({ page }) => {
    const problems = watchProblems(page);
    await page.goto('/index.php?action=new');
    await expectReadable(page, ['#formatbar button[data-format="wikilink"]', '#formatbar button[data-format="bold"] svg.icon']);
    await page.click('#headingbutton');
    await expectReadable(page, ['#headingmenu [data-level="1"]', '#headingmenu [data-level="4"]']);
    await page.keyboard.press('Escape');
    await expect(page.locator('#headingmenu')).toBeHidden();
    await page.click('#tablebutton');
    await expectReadable(page, ['#tabledialog h5', '#tabledialog label', '#tablecancel', '#tableinsert']);
    const background = await page.locator('#tabledialog').evaluate((e) => getComputedStyle(e).backgroundColor);
    expect(background).not.toBe('rgb(255, 255, 255)');
    expect(problems).toEqual([]);
  });
});

test.describe('without scripts', () => {
  test.use({ javaScriptEnabled: false });

  test('the editor has no toolbar (its buttons need the script), and the text can still be saved', async ({ page }) => {
    await page.goto('/index.php/' + encodeURIComponent('Saved without toolbar'));
    await expect(page.locator('#formatbar')).toBeHidden();
    await expect(page.locator('#tabledialog')).toBeHidden();
    await expect(page.locator('#text')).toBeVisible();
    await page.fill('#text', 'plain text');
    await page.click('#save');
    await page.waitForURL(/Saved%20without%20toolbar$/);
    await expect(page.locator('.main')).toContainText('plain text');
  });
});

test('the functions of the changes at the edges (setHeading(), toggleList(), toggleQuote(), toggleCode(), asBlock())', async ({ page }) => {
  await page.goto('/index.php?action=new');
  const results = await page.evaluate(() => {
    const show = (text, change) => change === null ? null : text.slice(0, change.start) + change.replacement + text.slice(change.end);
    return [
      show('\nb', setHeading('\nb', 0, 0, 1)),
      show('###### six', setHeading('###### six', 0, 0, 6)),
      show('a\n\nb', toggleList('a\n\nb', 0, 4, false)),
      show('1) one\n2) two', toggleList('1) one\n2) two', 0, 13, true)),
      show('- a', toggleList('- a', 0, 0, true)),
      show('', toggleQuote('', 0, 0)),
      show('> q', toggleQuote('> q', 0, 3)),
      show('~~~\nx\n~~~', toggleCode('~~~\nx\n~~~', 0, 9)),
      show('``', toggleCode('``', 1, 1)),
      show('a\n\n', asBlock('a\n\n', 3, 3, 'B')),
      show('a\n', asBlock('a\n', 2, 2, 'B')),
      tableLines([['a'], ['b', 'c']]),
      tableOfSelection('one\ttwo', 0, 7),
    ];
  });
  expect(results).toEqual(['# \nb', 'six', '- a\n\n- b', 'one\ntwo', '1. a', '> ', 'q', 'x', '', 'a\n\nB\n', 'a\n\nB\n',
    '| a   |     |\n| --- | --- |\n| b   | c   |', null]);
});
