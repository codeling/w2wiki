// SPDX-License-Identifier: MIT

// Keys of the editor: Ctrl+S (Cmd+S) saves the page
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
  expect(await drafts(page)).toEqual([]);
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
