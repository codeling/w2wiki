// SPDX-License-Identifier: MIT

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

test('new page: linking a mention in another page does not ask for confirmation and keeps the typed text', async ({ page, request }) => {
  await savePage(request, 'Page with a mention', 'I like Gadgetname a lot.');
  const dialogs = [];
  page.on('dialog', async (dialog) => { dialogs.push(dialog.type()); await dialog.dismiss(); });
  await page.goto('/index.php/' + encodeURIComponent('Software/Gadgetname'));
  await page.click('#text');
  await page.keyboard.type('text typed before linking');
  await page.click('form.linkmention input[type=submit]');
  await expect(page.locator('.note').first()).toContainText('Linked Software/Gadgetname in the page Page with a mention');
  await expect(page.locator('#text')).toHaveValue('text typed before linking');
  expect(dialogs).toEqual([]);
});

test('new page: the backlink button inserts a link to the page at the cursor', async ({ page, request }) => {
  await savePage(request, 'Page linking to it', 'See [[Gadgetlink]] here.');
  await page.goto('/index.php/' + encodeURIComponent('Software/Gadgetlink'));
  await page.click('#text');
  await page.keyboard.type('start ');
  await page.click('button.backlink');
  await expect(page.locator('#text')).toHaveValue('start [[Page linking to it]]');
});

test('existing page: leaving with changes asks for confirmation', async ({ page, request }) => {
  await savePage(request, 'Page to edit', 'old text');
  await page.goto('/index.php?action=edit&page=' + encodeURIComponent('Page to edit'));
  const dialogs = await typeAndClickUpload(page, '#text', ' and more');
  expect(dialogs).toEqual(['beforeunload']);
  await expect(page.locator('#text')).toHaveValue(/and more/);
});

for (const [label, url] of [['existing page', '/index.php?action=edit&page=Home'], ['new page by name', '/index.php/' + encodeURIComponent('Formatting help page')], ['new page', '/index.php?action=new']]) {
  test(`${label}: the formatting help can be opened`, async ({ page }) => {
    await page.goto(url);
    await expect(page.locator('#drawer')).toHaveClass(/inactive/);
    await page.click('#drawer-control');
    await expect(page.locator('#drawer')).not.toHaveClass(/inactive/);
    await page.click('#drawer img.rightaligned');
    await expect(page.locator('#drawer')).toHaveClass(/inactive/);
  });
}

test('existing page: the formatting help still works', async ({ page }) => {
  await page.goto('/index.php?action=edit&page=Home');
  await expect(page.locator('#drawer')).toHaveClass(/inactive/);
  await page.click('#drawer-control');
  await expect(page.locator('#drawer')).not.toHaveClass(/inactive/);
});

for (const [label, size] of [['desktop', { width: 1280, height: 800 }], ['phone', { width: 390, height: 700 }]]) {
  test(`${label}: the text area fills the window, with a single scrollbar inside of it`, async ({ page }) => {
    await page.setViewportSize(size);
    await page.goto('/index.php?action=new');
    await page.fill('#text', Array.from({ length: 300 }, (_, i) => 'line ' + i).join('\n'));
    const box = await page.locator('#text').boundingBox();
    const save = await page.locator('#save').boundingBox();
    expect(box.x + box.width).toBeGreaterThan(size.width - 30);
    expect(save.y + save.height).toBeLessThanOrEqual(size.height);
    expect(box.y + box.height).toBeLessThanOrEqual(save.y);
    expect(save.y - (box.y + box.height)).toBeLessThan(40);
    const overflow = await page.evaluate(() => document.documentElement.scrollHeight - window.innerHeight);
    expect(overflow).toBeLessThanOrEqual(0);
    const textarea = await page.evaluate(() => { const t = document.getElementById('text'); return t.scrollHeight > t.clientHeight; });
    expect(textarea).toBe(true);
  });
}
