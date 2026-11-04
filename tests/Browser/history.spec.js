// SPDX-License-Identifier: MIT

// The history of a page (in the wiki which commits every change to git, see gitBase()): from the page to the list of
// versions, to what changed in a version, and restoring it with the editor
const { test, expect } = require('@playwright/test');
const { savePage, gitBase, expectReadable, activeContent } = require('./helpers');

test.describe('page history', () => {
  test.use({ baseURL: gitBase() });

  test('history, changes of a version, and restoring it', async ({ page, request }) => {
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()); });
    const name = 'History ' + Date.now();
    await savePage(request, name, 'first line\nold line', gitBase());
    const token = /name="csrf_token" value="([^"]+)"/.exec(await (await request.get(`${gitBase()}/index.php?action=new`)).text())[1];
    await request.post(`${gitBase()}/index.php`, {
      form: { action: 'save', isNew: '', page: name, newText: 'first line\nnew line', gitmsg: 'changed a line', csrf_token: token },
      maxRedirects: 0,
    });

    await page.goto('/index.php/' + encodeURIComponent(name));
    await page.click('.titlebar a:has(svg.icon-history)');
    await expect(page.locator('.titlebar .title')).toHaveText(`History: ${name}`);
    const rows = page.locator('table.history tbody tr');
    await expect(rows).toHaveCount(2);
    await expect(rows.nth(0)).toContainText(`${name}: changed a line`);
    await expect(rows.nth(1)).toContainText(`${name} created`);

    // what the newest version changed
    await rows.nth(0).locator('a', { hasText: 'Changes' }).click();
    await expect(page.locator('.oldversion')).toBeVisible();
    await expect(page.locator('.diff .removed')).toHaveText('- old line');
    await expect(page.locator('.diff .added')).toHaveText('+ new line');

    // the first version, and restoring it
    await page.goto('/index.php?action=history&page=' + encodeURIComponent(name));
    await rows.nth(1).locator('td a').first().click();
    await expect(page.locator('.oldversion')).toContainText('This is the version of the page');
    await expect(page.locator('.main > p')).toContainText('old line');
    expect(await activeContent(page)).toEqual([]);
    await page.click('.versionlinks a:has-text("Restore this version")');
    await expect(page.locator('#text')).toHaveValue('first line\nold line');
    await expect(page.locator('#gitmsg')).toHaveValue(/^Restored version [0-9a-f]{7,} of /);
    await page.click('#save');
    await page.waitForURL((url) => !url.search.includes('action='));
    await expect(page.locator('.main')).toContainText('old line');

    await page.goto('/index.php?action=history&page=' + encodeURIComponent(name));
    await expect(rows).toHaveCount(3);
    await expect(rows.nth(0)).toContainText('Restored version');
    expect(errors).toEqual([]);
  });

  test.describe('dark color scheme', () => {
    test.use({ colorScheme: 'dark' });

    test('the list, the banner of an old version and the differences are readable', async ({ page, request }) => {
      const name = 'Dark history ' + Date.now();
      await savePage(request, name, 'one\ntwo', gitBase());
      await page.goto('/index.php?action=history&page=' + encodeURIComponent(name));
      await expectReadable(page, ['table.history td', 'table.history td a', 'table.history code', '.historynav']);
      await page.locator('table.history td a').first().click();
      await expectReadable(page, ['.oldversion', '.oldversion a', '.oldversion code', '.versionlinks .current']);
      await page.click('.versionlinks a:has-text("Changes in this version")');
      await expectReadable(page, ['.diff', '.diff .added']);
    });
  });
});
