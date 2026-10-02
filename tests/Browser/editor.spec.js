// Leaving the editor with changes which were not saved must not lose them silently
const { test, expect } = require('@playwright/test');
const { savePage } = require('./helpers');

/** Types into a field (like a user does) and clicks the Upload icon of the toolbar; the user answers "stay on this page" */
async function typeAndClickUpload(page, selector, text) {
  const dialogs = [];
  page.on('dialog', async (dialog) => { dialogs.push(dialog.type()); await dialog.dismiss(); });
  if (selector) {
    await page.click(selector);
    await page.keyboard.type(text);
  }
  await page.click('a[href*="action=upload"]', { noWaitAfter: true });
  await page.waitForTimeout(500);
  return dialogs;
}

test('new page: leaving with typed text asks for confirmation, and keeps the text if the user stays', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/index.php/' + encodeURIComponent('New page with text'));
  const dialogs = await typeAndClickUpload(page, '#text', 'text which was not saved yet');
  expect(dialogs).toEqual(['beforeunload']);
  expect(page.url()).toContain('New%20page%20with%20text');
  await expect(page.locator('#text')).toHaveValue('text which was not saved yet');
  expect(errors).toEqual([]);
});

test('new page: leaving after the user confirmed goes to the upload page', async ({ page }) => {
  await page.goto('/index.php/' + encodeURIComponent('New page left'));
  await page.click('#text');
  await page.keyboard.type('text');
  page.on('dialog', (dialog) => dialog.accept());
  await page.click('a[href*="action=upload"]');
  await page.waitForURL(/action=upload/);
});

test('new page: a typed title is not lost silently either', async ({ page }) => {
  await page.goto('/index.php?action=new');
  const dialogs = await typeAndClickUpload(page, '#title', 'Some title');
  expect(dialogs).toEqual(['beforeunload']);
});

test('new page: nothing typed, nothing to ask', async ({ page }) => {
  await page.goto('/index.php/' + encodeURIComponent('Untouched new page'));
  const dialogs = await typeAndClickUpload(page, null, '');
  expect(dialogs).toEqual([]);
  await page.waitForURL(/action=upload/);
});

test('new page: saving does not ask for confirmation', async ({ page }) => {
  const dialogs = [];
  page.on('dialog', async (dialog) => { dialogs.push(dialog.type()); await dialog.dismiss(); });
  await page.goto('/index.php/' + encodeURIComponent('Saved new page'));
  await page.click('#text');
  await page.keyboard.type('some text');
  await page.click('#save');
  await page.waitForURL(/Saved%20new%20page/);
  await expect(page.locator('.main')).toContainText('some text');
  expect(dialogs).toEqual([]);
});

test('existing page: leaving with changes asks for confirmation', async ({ page, request }) => {
  await savePage(request, 'Page to edit', 'old text');
  await page.goto('/index.php?action=edit&page=' + encodeURIComponent('Page to edit'));
  const dialogs = await typeAndClickUpload(page, '#text', ' and more');
  expect(dialogs).toEqual(['beforeunload']);
  await expect(page.locator('#text')).toHaveValue(/and more/);
});

test('existing page: the formatting help still works', async ({ page }) => {
  await page.goto('/index.php?action=edit&page=Home');
  await expect(page.locator('#drawer')).toHaveClass(/inactive/);
  await page.click('#drawer-control');
  await expect(page.locator('#drawer')).not.toHaveClass(/inactive/);
});
