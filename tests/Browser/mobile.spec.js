// SPDX-License-Identifier: MIT

// Phones (in the wiki with git, see gitBase(), which has the history of pages): no view is wider than the screen, the lists
// leave out the columns which say little there (author and version of the history, the size of pages and files, how to
// refer to a file), the list of all pages shows the name above the date and the icons, the icons are large enough to tap,
// and the text of form fields is large enough that iOS doesn't zoom into it.
const { test, expect } = require('@playwright/test');
const { savePage, upload, png, gitBase } = require('./helpers');

const name = 'Phone ' + Date.now();
const views = {
  page: () => '/index.php/' + encodeURIComponent(name),
  all: () => '/index.php?action=all',
  history: () => '/index.php?action=history&page=' + encodeURIComponent(name),
  editor: () => '/index.php?action=edit&page=' + encodeURIComponent(name),
  new: () => '/index.php?action=new',
  upload: () => '/index.php?action=upload&page=Home',
  search: () => '/index.php?action=search&q=Phone',
  rename: () => '/index.php?action=rename&page=' + encodeURIComponent(name),
};

test.describe('narrow screens', () => {
  test.use({ baseURL: gitBase(), hasTouch: true, isMobile: true, viewport: { width: 390, height: 800 } });

  test.beforeAll(async ({ request }) => {
    await savePage(request, name + ' with a long name, so that it has to wrap on a phone', 'text', gitBase());
    await savePage(request, name, '# A page\n\n| Column one | Column two | Column three |\n|---|---|---|\n| averyveryverylongwordwithoutspaces | b | c |\n\n```\na long line of code which is much wider than the screen of a phone, of course\n```\n', gitBase());
    expect((await upload(request, 'phone-photo-with-a-long-name.png', png(40, 20), 'image/png', gitBase())).status()).toBe(303);
  });

  for (const width of [320, 390]) {
    test(`no view is wider than ${width} pixels`, async ({ page }) => {
      await page.setViewportSize({ width, height: 800 });
      for (const [view, url] of Object.entries(views)) {
        await page.goto(url());
        const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
        expect(scrollWidth, view).toBeLessThanOrEqual(width);
      }
    });
  }

  test('the history leaves out the author and the version', async ({ page }) => {
    await page.goto(views.history());
    const row = page.locator('table.history tbody tr').first();
    await expect(row.locator('td').first()).toBeVisible();
    await expect(page.locator('table.history th', { hasText: 'Author' })).toBeHidden();
    await expect(page.locator('table.history th', { hasText: 'Version' })).toBeHidden();
    await expect(row.locator('code')).toBeHidden();
    // (on wide screens, they are there)
    await page.setViewportSize({ width: 1024, height: 800 });
    await expect(page.locator('table.history th', { hasText: 'Author' })).toBeVisible();
    await expect(row.locator('code')).toBeVisible();
  });

  test('the list of all pages shows the name above the date and the icons, without the size', async ({ page }) => {
    await page.goto(views.all());
    await expect(page.locator('table.pagelist th', { hasText: 'Size' })).toBeHidden();
    const row = page.locator('table.pagelist tbody tr', { has: page.locator(`a:text-is("${name}")`) });
    const nameBox = await row.locator('td.pagename').boundingBox();
    const dateBox = await row.locator('td.modified').boundingBox();
    const iconsBox = await row.locator('td.pageActions').boundingBox();
    expect(dateBox.y).toBeGreaterThanOrEqual(nameBox.y + nameBox.height - 1);
    expect(iconsBox.y).toBeGreaterThanOrEqual(nameBox.y + nameBox.height - 1);
    expect(iconsBox.x).toBeGreaterThan(dateBox.x + dateBox.width);
    // (the five icons in one line)
    const tops = await row.locator('td.pageActions a').evaluateAll((links) => links.map((link) => link.getBoundingClientRect().top));
    expect(tops.length).toBe(5);
    expect(new Set(tops).size).toBe(1);

    await page.goto(views.upload());
    await expect(page.locator('th', { hasText: 'Usage' })).toBeHidden();
    await expect(page.locator('th', { hasText: 'Size' })).toBeHidden();
    await expect(page.locator('th', { hasText: 'Modified' })).toBeVisible();
  });

  test('icons are large enough to tap, and form fields are not zoomed into', async ({ page }) => {
    for (const [url, selector] of [[views.page(), '.titlebar .pageactions a'], [views.page(), '.toolbar > a'], [views.all(), 'td.pageActions a'],
      [views.upload(), 'td.pageActions a'], [views.page(), '.themeswitch button']]) {
      await page.goto(url);
      const sizes = await page.locator(selector).evaluateAll((elements) => elements.map((element) => {
        const box = element.getBoundingClientRect();
        return [box.width, box.height];
      }));
      expect(sizes.length, selector).toBeGreaterThan(0);
      for (const [width, height] of sizes) {
        expect(Math.min(width, height), selector).toBeGreaterThanOrEqual(34);
      }
    }
    // (the page's icons stay together, after the title)
    await page.goto(views.page());
    const tops = await page.locator('.titlebar .pageactions a').evaluateAll((links) => links.map((link) => link.getBoundingClientRect().top));
    expect(new Set(tops).size).toBe(1);

    for (const [url, selector] of [[views.page(), '#search'], [views.editor(), '#text'], [views.editor(), '#gitmsg'], [views.new(), '#title'], [views.rename(), '#newName']]) {
      await page.goto(url);
      const fontSize = await page.locator(selector).evaluate((element) => parseFloat(getComputedStyle(element).fontSize));
      expect(fontSize, selector).toBeGreaterThanOrEqual(16);
    }
  });
});

test.describe('wide screens with a mouse', () => {
  test.use({ baseURL: gitBase(), viewport: { width: 1280, height: 800 } });

  test('the lists have all their columns, and the icons their size', async ({ page }) => {
    await page.goto(views.all());
    await expect(page.locator('table.pagelist th', { hasText: 'Size' })).toBeVisible();
    await expect(page.locator('table.pagelist th', { hasText: 'Action' })).toBeVisible();
    expect(await page.locator('table.pagelist tbody tr').first().evaluate((row) => getComputedStyle(row).display)).toBe('table-row');
    const icon = await page.locator('.toolbar svg.icon').first().boundingBox();
    expect(icon.width).toBeLessThan(22);
    await page.goto(views.upload());
    await expect(page.locator('th', { hasText: 'Usage' })).toBeVisible();
  });
});
