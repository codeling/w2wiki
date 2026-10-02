// Text typed into the editor is kept as a local draft, so it is not lost when the browser unloads the tab
const { test, expect } = require('@playwright/test');
const { csrfToken, savePage } = require('./helpers');

/** Changes an existing page, like another user (or another device) would */
async function changePage(request, name, text) {
  const token = await csrfToken(request);
  const response = await request.post('/index.php', {
    form: { action: 'save', isNew: '', page: name, newText: text, gitmsg: '', csrf_token: token },
    maxRedirects: 0,
  });
  expect(response.status(), `changing ${name}`).toBe(303);
}

const editUrl = (name) => '/index.php?action=edit&page=' + encodeURIComponent(name);

test.beforeEach(async ({ page }) => {
  // (leaving the editor with changes asks for confirmation; the tests always leave)
  page.on('dialog', (dialog) => dialog.accept());
});

test('existing page: typed text is restored after the page was reloaded', async ({ page, request }) => {
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await savePage(request, 'Draft page', 'saved text');
  await page.goto(editUrl('Draft page'));
  await page.click('#text');
  await page.keyboard.press('End');
  await page.keyboard.type(' and a draft');
  await page.reload();
  await expect(page.locator('#text')).toHaveValue('saved text and a draft');
  await expect(page.locator('.main .note')).toContainText('Restored unsaved draft');
  await expect(page.locator('.main .note')).not.toContainText('was changed');
  expect(errors).toEqual([]);
});

test('existing page: a discarded draft brings back the saved text', async ({ page, request }) => {
  await savePage(request, 'Discarded draft', 'saved text');
  await page.goto(editUrl('Discarded draft'));
  await page.fill('#text', 'draft text');
  await page.reload();
  await page.click('.main .note input[type=button]');
  await expect(page.locator('#text')).toHaveValue('saved text');
  await expect(page.locator('.main .note')).toHaveCount(0);
  await page.reload();
  await expect(page.locator('#text')).toHaveValue('saved text');
});

test('new page: title and text are restored', async ({ page }) => {
  await page.goto('/index.php?action=new');
  await page.fill('#title', 'Title of a draft');
  await page.fill('#text', 'text of a draft');
  await page.goto('/index.php?action=new');
  await expect(page.locator('#title')).toHaveValue('Title of a draft');
  await expect(page.locator('#text')).toHaveValue('text of a draft');
});

test('new page by name: the draft belongs to that name only', async ({ page }) => {
  await page.goto('/index.php/' + encodeURIComponent('First new page'));
  await page.fill('#text', 'text for the first page');
  await page.goto('/index.php/' + encodeURIComponent('Second new page'));
  await expect(page.locator('#text')).toHaveValue('');
  await page.goto('/index.php/' + encodeURIComponent('First new page'));
  await expect(page.locator('#text')).toHaveValue('text for the first page');
});

test('saving removes the draft', async ({ page }) => {
  await page.goto('/index.php/' + encodeURIComponent('Saved draft page'));
  await page.fill('#text', 'saved draft');
  await page.click('#save');
  await page.waitForURL(/Saved%20draft%20page/);
  expect(await page.evaluate(() => localStorage.length)).toBe(0);
  await page.goto(editUrl('Saved draft page'));
  await expect(page.locator('#text')).toHaveValue('saved draft');
  await expect(page.locator('.main .note')).toHaveCount(0);
});

test('a draft of a page which was changed in the meantime comes with a warning', async ({ page, request }) => {
  await savePage(request, 'Changed meanwhile', 'first version');
  await page.goto(editUrl('Changed meanwhile'));
  await page.fill('#text', 'my draft');
  await changePage(request, 'Changed meanwhile', 'changed elsewhere');
  await page.reload();
  await expect(page.locator('#text')).toHaveValue('my draft');
  await expect(page.locator('.main .note')).toContainText('was changed');
});
