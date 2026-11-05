// SPDX-License-Identifier: MIT

// Keys of the editor: Ctrl+S (Cmd+S) and Ctrl+Enter save the page, Ctrl+B, Ctrl+I and Ctrl+K make the selection bold,
// italic or a link, Ctrl+Alt+P shows the preview
const { test, expect } = require('@playwright/test');
const { savePage } = require('./helpers');

/** Remembers the type of every dialog of the page (which is dismissed: the user stays on the page) */
function dialogTypes(page) {
  const types = [];
  page.on('dialog', async (dialog) => { types.push(dialog.type()); await dialog.dismiss(); });
  return types;
}

/** The drafts the editor keeps in the browser */
async function drafts(page) {
  return page.evaluate(() => Object.keys(localStorage).filter((key) => key.startsWith('w2wiki-draft:')));
}

test('new page: Ctrl+S saves the page without asking, and the draft is removed', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  const dialogs = dialogTypes(page);
  await page.goto('/index.php/' + encodeURIComponent('Saved with the keyboard'));
  await page.click('#text');
  await page.keyboard.type('text saved with Ctrl+S');
  await page.keyboard.press('Control+s');
  await page.waitForURL(/Saved%20with%20the%20keyboard$/);
  await expect(page.locator('.main')).toContainText('text saved with Ctrl+S');
  // (the script of the page removes the draft when it has loaded, which can be after the text is shown)
  await expect.poll(() => drafts(page)).toEqual([]);
  expect(dialogs).toEqual([]);
  expect(errors).toEqual([]);
});

test('existing page: Cmd+S saves the changes', async ({ page, request }) => {
  await savePage(request, 'Page saved with Cmd', 'old text');
  const dialogs = dialogTypes(page);
  await page.goto('/index.php?action=edit&page=' + encodeURIComponent('Page saved with Cmd'));
  await page.click('#text');
  await page.keyboard.press('End');
  await page.keyboard.type(' and new text');
  await page.keyboard.press('Meta+s');
  await page.waitForURL(/Page%20saved%20with%20Cmd$/);
  await expect(page.locator('.main')).toContainText('old text and new text');
  expect(dialogs).toEqual([]);
});

test('new page: Ctrl+S in the title field saves too', async ({ page }) => {
  await page.goto('/index.php?action=new');
  await page.fill('#text', 'text of a page named in the title');
  await page.click('#title');
  await page.keyboard.type('Named in the title');
  await page.keyboard.press('Control+s');
  await page.waitForURL(/Named%20in%20the%20title$/);
  await expect(page.locator('.main')).toContainText('text of a page named in the title');
});

test('Ctrl+S does not save while the upload dialog is open', async ({ page }) => {
  await page.goto('/index.php/' + encodeURIComponent('Not saved from the dialog'));
  await page.click('#text');
  await page.keyboard.type('text');
  await page.click('#uploadbutton');
  await expect(page.locator('#uploaddialog')).toBeVisible();
  await page.keyboard.press('Control+s');
  await page.waitForTimeout(500);
  expect(page.url()).toContain('Not%20saved%20from%20the%20dialog');
  await expect(page.locator('#uploaddialog')).toBeVisible();
  await expect(page.locator('#text')).toHaveValue('text');
});

test('other pages: Ctrl+S is left to the browser', async ({ page }) => {
  await page.goto('/index.php/Home');
  const prevented = await page.evaluate(() => {
    const event = new KeyboardEvent('keydown', { key: 's', ctrlKey: true, bubbles: true, cancelable: true });
    document.body.dispatchEvent(event);
    return event.defaultPrevented;
  });
  expect(prevented).toBe(false);
});

test('Ctrl+Enter in the text saves the page, and the draft is removed', async ({ page }) => {
  const dialogs = dialogTypes(page);
  await page.goto('/index.php/' + encodeURIComponent('Saved with Enter'));
  await page.click('#text');
  await page.keyboard.type('text saved with Ctrl+Enter');
  await page.keyboard.press('Control+Enter');
  await page.waitForURL(/Saved%20with%20Enter$/);
  await expect(page.locator('.main')).toContainText('text saved with Ctrl+Enter');
  await expect.poll(() => drafts(page)).toEqual([]);
  expect(dialogs).toEqual([]);
});

test('Cmd+Enter in the title of a new page saves it; Ctrl+Enter outside the form does not', async ({ page }) => {
  await page.goto('/index.php?action=new');
  await page.fill('#text', 'text of a page saved from its title');
  await page.click('#search');
  expect(await page.evaluate(() => {
    const event = new KeyboardEvent('keydown', { key: 'Enter', ctrlKey: true, bubbles: true, cancelable: true });
    document.activeElement.dispatchEvent(event);
    return event.defaultPrevented;
  })).toBe(false);
  await page.click('#title');
  await page.keyboard.type('Saved from the title');
  await page.keyboard.press('Meta+Enter');
  await page.waitForURL(/Saved%20from%20the%20title$/);
  await expect(page.locator('.main')).toContainText('text of a page saved from its title');
});

/** Opens the editor of a new page with the text, and selects the part from start to end */
async function editorWith(page, text, start, end = start) {
  await page.goto('/index.php/' + encodeURIComponent('Formatted page'));
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

/** The text with the selection marked by [ and ] (or | for the cursor) */
async function marked(page) {
  return page.evaluate(() => {
    const t = document.getElementById('text');
    const v = t.value;
    return t.selectionStart === t.selectionEnd ? v.slice(0, t.selectionStart) + '|' + v.slice(t.selectionStart)
      : v.slice(0, t.selectionStart) + '[' + v.slice(t.selectionStart, t.selectionEnd) + ']' + v.slice(t.selectionEnd);
  });
}

test('Ctrl+B makes the selection bold and again not bold; Ctrl+Z undoes each step', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await editorWith(page, 'some words here', 5, 10);
  await page.keyboard.press('Control+b');
  expect(await marked(page)).toBe('some **[words]** here');
  await page.keyboard.press('Control+b');
  expect(await marked(page)).toBe('some [words] here');
  await page.keyboard.press('Control+z');
  await expect(page.locator('#text')).toHaveValue('some **words** here');
  await page.keyboard.press('Control+z');
  await expect(page.locator('#text')).toHaveValue('some words here');
  // (the markers selected with the text: removed too)
  await editorWith(page, 'a **bold** word', 2, 10);
  await page.keyboard.press('Control+b');
  expect(await marked(page)).toBe('a [bold] word');
  expect(errors).toEqual([]);
});

test('Ctrl+B without a selection inserts the markers with the cursor between them; spaces stay outside', async ({ page }) => {
  await editorWith(page, 'text ', 5);
  await page.keyboard.press('Control+b');
  expect(await marked(page)).toBe('text **|**');
  await page.keyboard.type('bold');
  await expect(page.locator('#text')).toHaveValue('text **bold**');
  // (pressed again right away: the markers go again)
  await editorWith(page, 'x', 1);
  await page.keyboard.press('Control+b');
  await page.keyboard.press('Control+b');
  expect(await marked(page)).toBe('x|');
  // (a word selected with the space after it, as a double click does on some systems)
  await editorWith(page, 'one two three', 4, 8);
  await page.keyboard.press('Control+b');
  expect(await marked(page)).toBe('one **[two]** three');
});

test('Ctrl+I makes the selection italic, also inside bold text; Cmd works too', async ({ page }) => {
  await editorWith(page, 'some words', 5, 10);
  await page.keyboard.press('Control+i');
  expect(await marked(page)).toBe('some *[words]*');
  await page.keyboard.press('Meta+i');
  expect(await marked(page)).toBe('some [words]');
  await page.keyboard.press('Meta+b');
  await page.keyboard.press('Control+i');
  expect(await marked(page)).toBe('some ***[words]***');
  // (bold off keeps it italic, italic off keeps it bold)
  await page.keyboard.press('Control+b');
  expect(await marked(page)).toBe('some *[words]*');
  await page.keyboard.press('Control+b');
  await page.keyboard.press('Control+i');
  expect(await marked(page)).toBe('some **[words]**');
  // (Ctrl+I in bold text which isn't italic: italic, not a star less)
  await editorWith(page, '**bold**', 2, 6);
  await page.keyboard.press('Control+i');
  expect(await marked(page)).toBe('***[bold]***');
});

test('Ctrl+K makes a link: the address is selected to type it; without a selection a link to a page', async ({ page }) => {
  await editorWith(page, 'see the docs here', 8, 12);
  await page.keyboard.press('Control+k');
  expect(await marked(page)).toBe('see the [docs]([url]) here');
  await page.keyboard.type('https://example.com/');
  await expect(page.locator('#text')).toHaveValue('see the [docs](https://example.com/) here');
  await page.keyboard.press('Control+z');
  await page.keyboard.press('Control+z');
  await expect(page.locator('#text')).toHaveValue('see the docs here');

  await editorWith(page, 'link: ', 6);
  await page.keyboard.press('Control+k');
  expect(await marked(page)).toBe('link: [[|]]');
  await page.keyboard.type('Home');
  await expect(page.locator('#text')).toHaveValue('link: [[Home]]');

  // (a selected address: the text of the link goes before it)
  await editorWith(page, 'at https://example.com/a?b=c now', 3, 28);
  await page.keyboard.press('Control+k');
  expect(await marked(page)).toBe('at [|](https://example.com/a?b=c) now');
});

test('the keys of the formatting count as typing: the draft is kept and the preview shows it', async ({ page }) => {
  page.on('dialog', (dialog) => dialog.accept().catch(() => {}));
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.goto('/index.php/' + encodeURIComponent('Formatted draft'));
  await page.click('#previewbutton');
  await page.fill('#text', 'word');
  await select(page, 0, 4);
  await page.keyboard.press('Control+b');
  await expect(page.locator('#preview strong')).toHaveText('word');
  await page.waitForTimeout(100);
  const draft = await page.evaluate(() => JSON.parse(localStorage.getItem(Object.keys(localStorage).find((key) => key.startsWith('w2wiki-draft:')))));
  expect(draft.text).toBe('**word**');
});

test('Ctrl+Shift+B, Ctrl+Alt+B and Esc are not taken; on other pages Ctrl+B is left to the browser', async ({ page }) => {
  await editorWith(page, 'word', 0, 4);
  for (const init of [{ key: 'B', ctrlKey: true, shiftKey: true }, { key: 'b', ctrlKey: true, altKey: true }, { key: 'Escape' }, { key: 'b', ctrlKey: true, isComposing: true }]) {
    const prevented = await page.evaluate((init) => {
      const event = new KeyboardEvent('keydown', Object.assign({ bubbles: true, cancelable: true }, init));
      document.getElementById('text').dispatchEvent(event);
      return event.defaultPrevented;
    }, init);
    expect(prevented, JSON.stringify(init)).toBe(false);
  }
  await expect(page.locator('#text')).toHaveValue('word');
  // (Esc does not cancel: the editor stays, with the text)
  await page.keyboard.press('Escape');
  await page.waitForTimeout(300);
  expect(page.url()).toContain('Formatted%20page');
  await expect(page.locator('#text')).toHaveValue('word');

  await page.goto('/index.php/Home');
  const prevented = await page.evaluate(() => {
    const event = new KeyboardEvent('keydown', { key: 'b', ctrlKey: true, bubbles: true, cancelable: true });
    document.body.dispatchEvent(event);
    return event.defaultPrevented;
  });
  expect(prevented).toBe(false);
});

test('Ctrl+Alt+P shows and hides the preview, and the text keeps the focus', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.goto('/index.php/' + encodeURIComponent('Previewed with keys'));
  await page.click('#text');
  await page.keyboard.type('# Title');
  await page.keyboard.press('Control+Alt+p');
  await expect(page.locator('#preview')).toBeVisible();
  await expect(page.locator('#previewbutton')).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('#preview h1')).toHaveText('Title');
  expect(await page.evaluate(() => document.activeElement.id)).toBe('text');
  await page.keyboard.press('Meta+Alt+p');
  await expect(page.locator('#preview')).toBeHidden();
  await expect(page.locator('#previewbutton')).toHaveAttribute('aria-pressed', 'false');
  await expect(page.locator('#text')).toHaveValue('# Title');
  // (outside of the text too)
  await page.locator('.titlebar .title').click();
  await page.keyboard.press('Control+Alt+p');
  await expect(page.locator('#preview')).toBeVisible();
  // (narrow screen: the preview takes the place of the text, which gets the focus back when it is shown again)
  await page.setViewportSize({ width: 390, height: 700 });
  await page.click('#previewbutton');
  await page.click('#text');
  await page.keyboard.press('Control+Alt+p');
  await expect(page.locator('#text')).toBeHidden();
  await page.keyboard.press('Control+Alt+p');
  await expect(page.locator('#text')).toBeVisible();
  expect(await page.evaluate(() => document.activeElement.id)).toBe('text');
});

test('bold, italic and links of selections at the edges (toggleEmphasis(), linkSelection())', async ({ page }) => {
  await page.goto('/index.php?action=new');
  const results = await page.evaluate(() => {
    const show = (text, change) => text.slice(0, change.start) + change.replacement + text.slice(change.end);
    return [
      show('***', toggleEmphasis('***', 0, 3, '*')),
      show('a  b', toggleEmphasis('a  b', 1, 3, '**')),
      show('**a**', toggleEmphasis('**a**', 0, 5, '*')),
      show('*a*', toggleEmphasis('*a*', 0, 3, '*')),
      show('***a***', toggleEmphasis('***a***', 0, 7, '*')),
      show('x', linkSelection('x', 0, 1)),
      show('mailto:a@b.c', linkSelection('mailto:a@b.c', 0, 12)),
    ];
  });
  expect(results).toEqual(['*****', 'a  ****b', '***a***', 'a', '**a**', '[x](url)', '[](mailto:a@b.c)']);
});
