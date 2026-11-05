// SPDX-License-Identifier: MIT

// Text typed into the editor is kept as a local draft, so it is not lost when the browser unloads the tab.
// The next time the editor is opened the draft is offered (with its changes shown on demand), not put into the editor.
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

// (the note which offers a draft; other notes can be shown too)
const note = (page) => page.locator('.main .note:has(input[type=button])');
const button = (page, name) => note(page).locator('input[type=button][value="' + name + '"]');

test('existing page: typed text is offered after the page was reloaded, and applied on demand', async ({ page, request }) => {
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await savePage(request, 'Draft page', 'saved text');
  await page.goto(editUrl('Draft page'));
  await page.click('#text');
  await page.keyboard.press('End');
  await page.keyboard.type(' and a draft');
  await page.reload();
  // (the editor shows the saved text; the draft is only offered)
  await expect(page.locator('#text')).toHaveValue('saved text');
  await expect(note(page)).toContainText('draft from');
  await expect(note(page)).not.toContainText('was changed');
  await button(page, 'Apply draft').click();
  await expect(page.locator('#text')).toHaveValue('saved text and a draft');
  await expect(note(page)).toHaveCount(0);
  // (applied like typed: it can be undone, and leaving asks for confirmation again)
  await page.keyboard.press('Control+z');
  await expect(page.locator('#text')).toHaveValue('saved text');
  expect(errors).toEqual([]);
});

test('existing page: an offered draft which is not applied stays offered', async ({ page, request }) => {
  await savePage(request, 'Offered twice', 'saved text');
  await page.goto(editUrl('Offered twice'));
  await page.fill('#text', 'draft text');
  await page.reload();
  await expect(page.locator('#text')).toHaveValue('saved text');
  await page.goto(editUrl('Offered twice'));
  await expect(page.locator('#text')).toHaveValue('saved text');
  await expect(note(page)).toContainText('draft from');
});

test('existing page: the changes of a draft can be shown and hidden', async ({ page, request }) => {
  await savePage(request, 'Shown changes', 'first line\nsecond line\nthird line');
  await page.goto(editUrl('Shown changes'));
  await page.fill('#text', 'first line\nsecond <b>line</b>\nthird line');
  await page.reload();
  await expect(note(page).locator('.diff')).toHaveCount(0);
  await button(page, 'Show changes').click();
  await expect(note(page).locator('.diff .removed')).toHaveText('- second line');
  await expect(note(page).locator('.diff .added')).toHaveText('+ second <b>line</b>');
  await expect(note(page).locator('.diff .same')).toHaveCount(2);
  // (showing them changes nothing)
  await expect(page.locator('#text')).toHaveValue('first line\nsecond line\nthird line');
  await button(page, 'Hide changes').click();
  await expect(note(page).locator('.diff')).toHaveCount(0);
  await expect(button(page, 'Show changes')).toHaveCount(1);
});

test('existing page: a discarded draft is gone', async ({ page, request }) => {
  await savePage(request, 'Discarded draft', 'saved text');
  await page.goto(editUrl('Discarded draft'));
  await page.fill('#text', 'draft text');
  await page.reload();
  await button(page, 'Discard draft').click();
  await expect(page.locator('#text')).toHaveValue('saved text');
  await expect(note(page)).toHaveCount(0);
  await page.reload();
  await expect(page.locator('#text')).toHaveValue('saved text');
  await expect(note(page)).toHaveCount(0);
});

test('existing page: leaving with Cancel (confirmed) does not keep the draft', async ({ page, request }) => {
  await savePage(request, 'Abandoned draft', 'saved text');
  await page.goto('/index.php?page=' + encodeURIComponent('Abandoned draft'));
  await page.goto(editUrl('Abandoned draft'));
  await page.fill('#text', 'text which is given up');
  await page.click('#cancel');
  await page.waitForURL((url) => !url.search.includes('action=edit'));
  expect(await page.evaluate(() => localStorage.length)).toBe(0);
  await page.goto(editUrl('Abandoned draft'));
  await expect(page.locator('#text')).toHaveValue('saved text');
  await expect(note(page)).toHaveCount(0);
});

test('existing page: Cancel which is not confirmed keeps the draft', async ({ page, request }) => {
  await savePage(request, 'Kept draft', 'saved text');
  await page.goto('/index.php?page=' + encodeURIComponent('Kept draft'));
  await page.goto(editUrl('Kept draft'));
  await page.fill('#text', 'text which is kept');
  page.removeAllListeners('dialog');
  page.once('dialog', (dialog) => dialog.dismiss());
  await page.click('#cancel');
  await page.waitForTimeout(300);
  await expect(page.locator('#text')).toHaveValue('text which is kept');
  page.on('dialog', (dialog) => dialog.accept());
  await page.click('#text');
  await page.keyboard.press('End');
  await page.keyboard.type('!');
  await page.reload();
  await button(page, 'Apply draft').click();
  await expect(page.locator('#text')).toHaveValue('text which is kept!');
});

test('existing page: Cancel without having touched an offered draft leaves it offered', async ({ page, request }) => {
  await savePage(request, 'Untouched draft', 'saved text');
  await page.goto(editUrl('Untouched draft'));
  await page.fill('#text', 'old draft');
  await page.reload();
  await page.goto('/index.php?page=' + encodeURIComponent('Untouched draft'));
  await page.goto(editUrl('Untouched draft'));
  await expect(note(page)).toContainText('draft from');
  await page.click('#cancel');
  await page.waitForURL((url) => !url.search.includes('action=edit'));
  await page.goto(editUrl('Untouched draft'));
  await expect(note(page)).toContainText('draft from');
});

test('new page: title and text are offered', async ({ page }) => {
  await page.goto('/index.php?action=new');
  await page.fill('#title', 'Title of a draft');
  await page.fill('#text', 'text of a draft');
  await page.goto('/index.php?action=new');
  await expect(page.locator('#title')).toHaveValue('');
  await expect(page.locator('#text')).toHaveValue('');
  await button(page, 'Show changes').click();
  await expect(note(page).locator('.diff .added')).toHaveText(['+ Title of a draft', '+ text of a draft']);
  await button(page, 'Apply draft').click();
  await expect(page.locator('#title')).toHaveValue('Title of a draft');
  await expect(page.locator('#text')).toHaveValue('text of a draft');
});

test('new page by name: the draft belongs to that name only', async ({ page }) => {
  await page.goto('/index.php/' + encodeURIComponent('First new page'));
  await page.fill('#text', 'text for the first page');
  await page.goto('/index.php/' + encodeURIComponent('Second new page'));
  await expect(page.locator('#text')).toHaveValue('');
  await expect(note(page)).toHaveCount(0);
  await page.goto('/index.php/' + encodeURIComponent('First new page'));
  await expect(page.locator('#text')).toHaveValue('');
  await button(page, 'Apply draft').click();
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
  await expect(note(page)).toHaveCount(0);
});

test('a draft of a page which was changed in the meantime comes with a warning', async ({ page, request }) => {
  await savePage(request, 'Changed meanwhile', 'first version');
  await page.goto(editUrl('Changed meanwhile'));
  await page.fill('#text', 'my draft');
  await changePage(request, 'Changed meanwhile', 'changed elsewhere');
  await page.reload();
  await expect(page.locator('#text')).toHaveValue('changed elsewhere');
  await expect(note(page)).toContainText('was changed');
  await button(page, 'Show changes').click();
  await expect(note(page).locator('.diff .removed')).toHaveText('- changed elsewhere');
  await expect(note(page).locator('.diff .added')).toHaveText('+ my draft');
});

test('a save which the server refuses keeps the draft, and the editor shows the text again', async ({ page, context }) => {
  await page.goto('/index.php/' + encodeURIComponent('Refused save'));
  await page.fill('#title', 'Refused save');
  await page.fill('#text', 'a long edit');
  // (like an expired session: the token in the form is no longer the one of the session)
  await context.clearCookies();
  await page.click('#save');
  await expect(page.locator('.main .note')).toContainText('was not saved');
  await expect(page.locator('#text')).toHaveValue('a long edit');
  await expect(page.locator('#title')).toHaveValue('Refused save');
  expect(await page.evaluate(() => localStorage.length)).toBe(1);
  // (saving again works, with the token of the new session)
  await page.click('#save');
  await page.waitForURL(/index\.php\/Refused%20save$/);
  expect(await page.evaluate(() => localStorage.length)).toBe(0);
});

test('a save which fails on the way keeps the draft', async ({ page, request }) => {
  await savePage(request, 'Failed save', 'saved text');
  await page.goto(editUrl('Failed save'));
  await page.fill('#text', 'unsaved text');
  await page.route('**/index.php', (route) => route.request().method() === 'POST' ? route.abort() : route.continue());
  await page.click('#save').catch(() => {});
  await page.unroute('**/index.php');
  await page.goto(editUrl('Failed save'));
  await expect(page.locator('#text')).toHaveValue('saved text');
  await expect(note(page)).toContainText('draft from');
  await button(page, 'Apply draft').click();
  await expect(page.locator('#text')).toHaveValue('unsaved text');
});
