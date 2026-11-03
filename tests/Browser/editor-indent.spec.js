// SPDX-License-Identifier: MIT

// Tab and Shift+Tab in the text of the editor indent and outdent lines; Escape and then Tab leaves the text
const { test, expect } = require('@playwright/test');

/** Opens the editor of a new page with the text, and selects the part from start to end */
async function editorWith(page, text, start, end) {
  await page.goto('/index.php/' + encodeURIComponent('Indented page'));
  await page.fill('#text', text);
  await page.evaluate(([s, e]) => {
    const textArea = document.getElementById('text');
    textArea.focus();
    textArea.setSelectionRange(s, e);
  }, [start, end]);
}

async function selection(page) {
  return page.evaluate(() => {
    const textArea = document.getElementById('text');
    return textArea.value.slice(textArea.selectionStart, textArea.selectionEnd);
  });
}

test('Tab at the cursor inserts four spaces and keeps the focus in the text', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await editorWith(page, '- item', 0, 0);
  await page.keyboard.press('End');
  await page.keyboard.press('Enter');
  await page.keyboard.press('Tab');
  await page.keyboard.type('- sub item');
  await expect(page.locator('#text')).toHaveValue('- item\n    - sub item');
  expect(await page.evaluate(() => document.activeElement.id)).toBe('text');
  expect(errors).toEqual([]);
});

test('Tab and Shift+Tab indent and outdent every line of the selection, which stays selected', async ({ page }) => {
  const text = 'first\nsecond\n\nthird\nnot selected';
  await editorWith(page, text, 2, text.indexOf('third') + 2);
  await page.keyboard.press('Tab');
  // (the empty line gets no spaces)
  await expect(page.locator('#text')).toHaveValue('    first\n    second\n\n    third\nnot selected');
  expect(await selection(page)).toBe('rst\n    second\n\n    th');
  await page.keyboard.press('Shift+Tab');
  await expect(page.locator('#text')).toHaveValue(text);
  expect(await selection(page)).toBe('rst\nsecond\n\nth');
});

test('a selection of whole lines stays a selection of whole lines', async ({ page }) => {
  const text = 'one\ntwo\nthree';
  await editorWith(page, text, 0, 'one\ntwo\n'.length);
  await page.keyboard.press('Tab');
  await expect(page.locator('#text')).toHaveValue('    one\n    two\nthree');
  expect(await selection(page)).toBe('    one\n    two\n');
});

test('Shift+Tab removes up to four spaces or one tab, also without a selection', async ({ page }) => {
  await editorWith(page, '\t\tcode\n      text', 0, 0);
  await page.keyboard.press('Shift+Tab');
  await expect(page.locator('#text')).toHaveValue('\tcode\n      text');
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('Shift+Tab');
  await page.keyboard.press('Shift+Tab');
  await expect(page.locator('#text')).toHaveValue('\tcode\ntext');
  // (nothing left to remove: nothing changes, and the focus stays)
  await page.keyboard.press('Shift+Tab');
  await expect(page.locator('#text')).toHaveValue('\tcode\ntext');
  expect(await page.evaluate(() => document.activeElement.id)).toBe('text');
});

test('indenting can be undone in one step', async ({ page }) => {
  const text = 'a\nb\nc';
  await editorWith(page, text, 0, text.length);
  await page.keyboard.press('Tab');
  await expect(page.locator('#text')).toHaveValue('    a\n    b\n    c');
  await page.keyboard.press('Control+z');
  await expect(page.locator('#text')).toHaveValue(text);
});

test('Escape and then Tab moves the focus out of the text', async ({ page }) => {
  await editorWith(page, 'text', 4, 4);
  await page.keyboard.press('Escape');
  await page.keyboard.press('Tab');
  await expect(page.locator('#text')).toHaveValue('text');
  expect(await page.evaluate(() => document.activeElement.id)).not.toBe('text');
  // (back in the text, Tab indents again)
  await page.click('#text');
  await page.keyboard.press('End');
  await page.keyboard.press('Tab');
  await expect(page.locator('#text')).toHaveValue('text    ');
});

test('Escape, then another key: Tab indents again', async ({ page }) => {
  await editorWith(page, 'text', 4, 4);
  await page.keyboard.press('Escape');
  await page.keyboard.type('!');
  await page.keyboard.press('Tab');
  await expect(page.locator('#text')).toHaveValue('text!    ');
});

test('an indented text is a change: leaving the page asks for confirmation', async ({ page, request }) => {
  const dialogs = [];
  page.on('dialog', async (dialog) => { dialogs.push(dialog.type()); await dialog.dismiss(); });
  await page.goto('/index.php?action=edit&page=Home');
  await page.click('#text');
  await page.keyboard.press('Control+Home');
  await page.keyboard.press('Tab');
  await page.click('a[href*="action=upload"]', { noWaitAfter: true });
  await page.waitForTimeout(500);
  expect(dialogs).toEqual(['beforeunload']);
});

test('the text names the keys for screen readers and in the formatting help', async ({ page }) => {
  await page.goto('/index.php?action=new');
  const hint = await page.evaluate(() => {
    const ids = document.getElementById('text').getAttribute('aria-describedby');
    return document.getElementById(ids).textContent;
  });
  expect(hint).toContain('Esc');
  await expect(page.locator('#texthint')).not.toBeInViewport({ ratio: 0.5 });
  await page.click('#drawer-control');
  await expect(page.locator('#drawer')).toContainText('Shift+Tab');
});
