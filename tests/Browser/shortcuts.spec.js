// SPDX-License-Identifier: MIT

// The keyboard shortcuts of every page (setupShortcuts() in wiki.js): "/" goes to the search box, "e" edits the page,
// "n" makes a new one, "g" then "h" or "a" go to the home page and to the list of all pages, "h" to the history (with
// git), "?" shows the list of them. They are left alone while text is typed, with modifiers, repeated keys and open dialogs.
const { test, expect } = require('@playwright/test');
const { savePage, gitBase, expectReadable } = require('./helpers');

/** Script errors, and the messages about the Content-Security-Policy, which must not happen */
function watchProblems(page) {
  const problems = [];
  page.on('pageerror', (error) => problems.push(error.message));
  page.on('console', (message) => { if (message.type() === 'error' || /content security policy|refused to/i.test(message.text())) problems.push(message.text()); });
  return problems;
}

/** Sends a keydown event to the element which has the focus (or the body); returns whether a script prevented its default */
function dispatchKey(page, init) {
  return page.evaluate((init) => {
    const event = new KeyboardEvent('keydown', Object.assign({ bubbles: true, cancelable: true }, init));
    (document.activeElement || document.body).dispatchEvent(event);
    return event.defaultPrevented;
  }, init);
}

/** The address of the page, after a while in which a shortcut would have left it */
async function urlAfterAWhile(page) {
  await page.waitForTimeout(400);
  return page.url();
}

/** The shortcuts which the list shows (the keys of its rows) */
function listedKeys(page) {
  return page.locator('#shortcuts tr').evaluateAll((rows) => rows.filter((row) => !row.hidden).map((row) => row.querySelector('th').textContent.replace(/\s+/g, ' ')));
}

test.beforeEach(async ({ page }) => {
  // (leaving the editor with changes asks for confirmation; the tests always leave)
  page.on('dialog', (dialog) => dialog.accept().catch(() => {}));
});

test('"/" goes to the search box, and typing there types text, also the keys of shortcuts', async ({ page }) => {
  const problems = watchProblems(page);
  await page.goto('/index.php/Home');
  await page.keyboard.press('/');
  expect(await page.evaluate(() => document.activeElement.id)).toBe('search');
  await page.keyboard.type('e/?nh');
  await expect(page.locator('#search')).toHaveValue('e/?nh');
  await expect(page.locator('#shortcuts')).toBeHidden();
  await page.keyboard.press('g');
  await page.keyboard.press('a');
  await expect(page.locator('#search')).toHaveValue('e/?nhga');
  expect(await urlAfterAWhile(page)).toMatch(/\/index\.php\/Home$/);
  await Promise.all([page.waitForURL(/action=search/), page.keyboard.press('Enter')]);
  expect(new URL(page.url()).searchParams.get('q')).toBe('e/?nhga');
  // (the text which is there is selected, so that typing replaces it)
  await page.fill('#search', 'old');
  await page.locator('h1').click();
  await page.keyboard.press('/');
  await page.keyboard.type('new');
  await expect(page.locator('#search')).toHaveValue('new');
  expect(problems).toEqual([]);
});

test('"e" edits the page, "n" makes a new one', async ({ page, request }) => {
  const problems = watchProblems(page);
  await savePage(request, 'Shortcut page', 'text of the page');
  await page.goto('/index.php/Shortcut%20page');
  await page.keyboard.press('e');
  await page.waitForURL(/action=edit&page=Shortcut\+page$/);
  await expect(page.locator('#text')).toHaveValue('text of the page');
  // (in the editor, the keys go into the text)
  await page.click('#text');
  await page.keyboard.press('End');
  await page.keyboard.type(' e/?n gh');
  await expect(page.locator('#text')).toHaveValue('text of the page e/?n gh');
  expect(await urlAfterAWhile(page)).toContain('action=edit');
  await page.goto('/index.php?action=all');
  await page.keyboard.press('n');
  await page.waitForURL(/action=new$/);
  await expect(page.locator('#text')).toBeVisible();
  expect(problems).toEqual([]);
});

test('"g" then "h" goes to the home page, "g" then "a" to the list of all pages', async ({ page }) => {
  const problems = watchProblems(page);
  await page.goto('/index.php?action=search&q=Home');
  await page.keyboard.press('g');
  await page.keyboard.press('a');
  await page.waitForURL(/action=all$/);
  await page.keyboard.press('g');
  await page.keyboard.press('h');
  await page.waitForURL(/\/index\.php$/);
  await expect(page.locator('.titlebar .title')).toHaveText('Home');
  // (another key in between: no shortcut; "a" alone is none either)
  await page.keyboard.press('g');
  await page.keyboard.press('x');
  await page.keyboard.press('a');
  expect(await urlAfterAWhile(page)).toMatch(/\/index\.php$/);
  // (the second key only within a short time)
  await page.keyboard.press('g');
  await page.waitForTimeout(1700);
  await page.keyboard.press('a');
  expect(await urlAfterAWhile(page)).toMatch(/\/index\.php$/);
  expect(problems).toEqual([]);
});

test('"h" does nothing without the history (no git), and is not in the list', async ({ page }) => {
  await page.goto('/index.php/Home');
  expect(await dispatchKey(page, { key: 'h' })).toBe(false);
  expect(await urlAfterAWhile(page)).toMatch(/\/index\.php\/Home$/);
  await page.keyboard.press('?');
  expect(await listedKeys(page)).toEqual(['/', 'e', 'n', 'g then h', 'g then a', '?']);
});

test('the list of shortcuts: "?" opens it, Esc, its button and a click next to it close it', async ({ page }) => {
  const problems = watchProblems(page);
  await page.goto('/index.php/Home');
  const dialog = page.locator('#shortcuts');
  await expect(dialog).toBeHidden();
  await page.keyboard.press('?');
  await expect(dialog).toBeVisible();
  await expect(dialog.locator('h5')).toHaveText('Keyboard shortcuts');
  // (a modal dialog with a name, which has the focus)
  expect(await page.evaluate(() => {
    const d = document.getElementById('shortcuts');
    return [d.open, d.matches(':modal'), d.contains(document.activeElement), document.getElementById(d.getAttribute('aria-labelledby')).textContent];
  })).toEqual([true, true, true, 'Keyboard shortcuts']);
  await expect(page.getByRole('dialog', { name: 'Keyboard shortcuts' })).toBeVisible();
  // (no keys of the editor on other pages)
  await expect(dialog.locator('caption')).toHaveCount(1);
  // (while it is open, the keys are no shortcuts)
  await page.keyboard.press('e');
  await page.keyboard.press('n');
  expect(await urlAfterAWhile(page)).toMatch(/\/index\.php\/Home$/);
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();

  await page.keyboard.press('?');
  await expect(dialog).toBeVisible();
  await page.getByRole('button', { name: 'Close' }).click();
  await expect(dialog).toBeHidden();

  await page.keyboard.press('?');
  await expect(dialog).toBeVisible();
  await page.mouse.click(5, 5);
  await expect(dialog).toBeHidden();
  // (a click inside does not close it)
  await page.keyboard.press('?');
  await dialog.locator('h5').click();
  await expect(dialog).toBeVisible();
  await page.keyboard.press('Escape');

  // the list shows the shortcuts of the page it is on
  await page.goto('/index.php?action=all');
  await page.keyboard.press('?');
  expect(await listedKeys(page)).toEqual(['/', 'n', 'g then h', 'g then a', '?']);
  expect(problems).toEqual([]);
});

test('the editor: the list has its keys, and "?" in the text is text', async ({ page }) => {
  await page.goto('/index.php?action=edit&page=Home');
  await page.click('#text');
  await page.keyboard.press('Control+Home');
  await page.keyboard.type('?');
  await expect(page.locator('#shortcuts')).toBeHidden();
  await expect(page.locator('#text')).toHaveValue(/^\?/);
  await page.locator('.titlebar .title').click();
  await page.keyboard.press('?');
  await expect(page.locator('#shortcuts')).toBeVisible();
  await expect(page.locator('#shortcuts caption')).toHaveText(['On every page (not while the cursor is in a text field)', 'In the editor']);
  // (the history needs git; the editor has no icon to edit the page)
  expect(await listedKeys(page)).toEqual(['/', 'n', 'g then h', 'g then a', '?', 'Ctrl+S', 'Tab, Shift+Tab']);
});

test('keys with Ctrl, Alt or Cmd, repeated keys and keys of an input method are left to the browser', async ({ page }) => {
  await page.goto('/index.php/Home');
  for (const init of [{ key: 'e', ctrlKey: true }, { key: 'e', altKey: true }, { key: 'e', metaKey: true }, { key: '/', ctrlKey: true },
    { key: 'e', repeat: true }, { key: 'n', isComposing: true }, { key: '?', altKey: true }]) {
    expect(await dispatchKey(page, init), JSON.stringify(init)).toBe(false);
  }
  await page.keyboard.press('Alt+e');
  await page.keyboard.press('Control+n');
  expect(await urlAfterAWhile(page)).toMatch(/\/index\.php\/Home$/);
  await expect(page.locator('#shortcuts')).toBeHidden();
  // (a key which is no shortcut is not touched, Shift+E neither)
  expect(await dispatchKey(page, { key: 'x' })).toBe(false);
  expect(await dispatchKey(page, { key: 'E', shiftKey: true })).toBe(false);
  // (the shortcuts themselves are taken)
  expect(await dispatchKey(page, { key: '/' })).toBe(true);
});

test('typing in other fields (contenteditable, select) is left alone; a focused button or link is not a field', async ({ page }) => {
  await page.goto('/index.php/Home');
  await page.evaluate(() => {
    const editable = document.createElement('div');
    editable.id = 'editable';
    editable.contentEditable = 'true';
    editable.textContent = 'x';
    const select = document.createElement('select');
    select.id = 'select';
    select.append(new Option('one'), new Option('else'));
    document.querySelector('.main').append(editable, select);
  });
  await page.focus('#editable');
  await page.keyboard.press('End');
  await page.keyboard.type('e?');
  await expect(page.locator('#editable')).toHaveText('xe?');
  await page.focus('#select');
  expect(await dispatchKey(page, { key: 'e' })).toBe(false);
  expect(await urlAfterAWhile(page)).toMatch(/\/index\.php\/Home$/);
  await page.focus('.themeswitch button');
  await page.keyboard.press('e');
  await page.waitForURL(/action=edit&page=Home$/);
});

test('the pages work without a script, and the list of shortcuts is not shown', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  await page.goto('/index.php/Home');
  await expect(page.locator('#shortcuts')).toBeHidden();
  await page.keyboard.press('?');
  await page.keyboard.press('e');
  await expect(page.locator('#shortcuts')).toBeHidden();
  expect(page.url()).toMatch(/\/index\.php\/Home$/);
  await page.click('.toolbar a[data-shortcut="g a"]');
  await page.waitForURL(/action=all$/);
  await page.goto('/index.php/Home');
  await page.click('.titlebar a[data-shortcut="e"]');
  await page.waitForURL(/action=edit/);
  await expect(page.locator('#text')).toBeVisible();
  await expect(page.locator('#shortcuts')).toBeHidden();
  await context.close();
});

for (const colorScheme of ['light', 'dark']) {
  test(`the list of shortcuts is readable in the ${colorScheme} color scheme`, async ({ browser }) => {
    const context = await browser.newContext({ colorScheme });
    const page = await context.newPage();
    await page.goto('/index.php?action=edit&page=Home');
    await page.locator('.titlebar .title').click();
    await page.keyboard.press('?');
    await expect(page.locator('#shortcuts')).toBeVisible();
    await expectReadable(page, ['#shortcuts h5', '#shortcuts caption', '#shortcuts td', '#shortcuts th', '#shortcuts kbd', '#shortcuts .hint']);
    const box = await page.locator('#shortcuts').boundingBox();
    expect(box.width).toBeGreaterThan(300);
    await context.close();
  });
}

test.describe('with git', () => {
  test.use({ baseURL: gitBase() });

  test('"h" shows the history of the page, where "e" edits it and "h" does nothing', async ({ page, request }) => {
    const problems = watchProblems(page);
    const name = 'Shortcut history ' + Date.now();
    await savePage(request, name, 'text', gitBase());
    await page.goto('/index.php/' + encodeURIComponent(name));
    await page.keyboard.press('?');
    expect(await listedKeys(page)).toEqual(['/', 'e', 'n', 'g then h', 'g then a', 'h', '?']);
    await page.keyboard.press('Escape');
    await page.keyboard.press('h');
    await page.waitForURL(/action=history/);
    await expect(page.locator('.titlebar .title')).toHaveText(`History: ${name}`);
    expect(await dispatchKey(page, { key: 'h' })).toBe(false);
    expect(await urlAfterAWhile(page)).toContain('action=history');
    // (from a version, back to the list)
    await page.locator('table.history tbody tr td a').first().click();
    await page.waitForURL(/action=version/);
    await page.keyboard.press('h');
    await page.waitForURL(/action=history/);
    await page.keyboard.press('e');
    await page.waitForURL(/action=edit/);
    await expect(page.locator('#text')).toHaveValue('text');
    expect(problems).toEqual([]);
  });
});
