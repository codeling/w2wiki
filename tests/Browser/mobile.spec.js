// SPDX-License-Identifier: MIT

// Phones (in the wiki with git, see gitBase(), which has the history of pages): no view is wider than the screen, the lists
// leave out the columns which say little there (author and version of the history, the size of pages and files, how to
// refer to a file), the list of all pages shows the name above the date and the icons, the title bar is one line, the
// icons are large enough to tap, the text of form fields is large enough that iOS doesn't zoom into it, and the bars
// make room for the text of the editor while it is typed.
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

    for (const [url, selector] of [[views.page(), '#search'], [views.editor(), '#text'], [views.editor(), '#gitmsg'], [views.new(), '#title'], [views.rename(), '#newName']]) {
      await page.goto(url);
      const fontSize = await page.locator(selector).evaluate((element) => parseFloat(getComputedStyle(element).fontSize));
      expect(fontSize, selector).toBeGreaterThanOrEqual(16);
    }
  });

  for (const width of [320, 390]) {
    test(`the title bar is one line at ${width} pixels: the title above the date, the icons next to them`, async ({ page }) => {
      await page.setViewportSize({ width, height: 800 });
      for (const view of ['page', 'history', 'editor', 'all']) {
        await page.goto(views[view]());
        const bar = await page.locator('.titlebar').boundingBox();
        const title = await page.locator('.titlebar .title').boundingBox();
        expect(bar.height, view).toBeLessThanOrEqual(42);
        expect(title.x + title.width, view).toBeLessThanOrEqual(width);
        expect(await page.locator('.toolbar').evaluate((toolbar) => toolbar.getBoundingClientRect().bottom), view).toBeLessThanOrEqual(80);
        const icons = page.locator('.titlebar .pageactions a');
        if (await icons.count() > 0) {
          const boxes = await icons.evaluateAll((links) => links.map((link) => link.getBoundingClientRect()).map((box) => [box.top, box.right]));
          expect(new Set(boxes.map(([top]) => top)).size, view).toBe(1);
          expect(Math.max(...boxes.map(([, right]) => right)), view).toBeLessThanOrEqual(width);
          expect(boxes[0][1], view).toBeGreaterThan(title.x + title.width);
        }
      }
      // (a long title is cut off, the date below it is shown)
      await page.goto('/index.php/' + encodeURIComponent(name + ' with a long name, so that it has to wrap on a phone'));
      const title = page.locator('.titlebar .title');
      expect(await title.evaluate((element) => element.scrollWidth > element.clientWidth)).toBe(true);
      const titleBox = await title.boundingBox();
      const dateBox = await page.locator('.titlebar .titledate').boundingBox();
      expect(dateBox.y).toBeGreaterThanOrEqual(titleBox.y + titleBox.height - 1);
    });
  }

  test('while the text of the editor is typed, the title bar and the toolbar make room for it', async ({ page }) => {
    // (the space which the on-screen keyboard leaves)
    await page.setViewportSize({ width: 390, height: 420 });
    await page.goto(views.editor());
    await expect(page.locator('.titlebar')).toBeVisible();
    const before = (await page.locator('#text').boundingBox()).height;
    await page.locator('#text').tap();
    await expect(page.locator('.titlebar')).toBeHidden();
    await expect(page.locator('.toolbar')).toBeHidden();
    expect((await page.locator('#text').boundingBox()).height).toBeGreaterThan(before + 60);
    // (the buttons of the formatting toolbar leave the focus in the text)
    await page.locator('#formatbar button[data-format="bold"]').tap();
    await expect(page.locator('#text')).toBeFocused();
    await expect(page.locator('.titlebar')).toBeHidden();
    // (elsewhere, they are back; Save is where it was, and saves)
    const save = await page.locator('#save').boundingBox();
    await page.locator('#gitmsg').tap();
    await expect(page.locator('.titlebar')).toBeVisible();
    expect((await page.locator('#save').boundingBox()).y).toBe(save.y);
    await page.locator('#text').tap();
    await Promise.all([page.waitForURL(/\/index\.php\/Phone/), page.locator('#save').tap()]);
    await expect(page.locator('.titlebar')).toBeVisible();
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
    // (the bars stay while the text of the editor is typed)
    await page.goto(views.editor());
    await page.locator('#text').click();
    await expect(page.locator('.titlebar')).toBeVisible();
    await expect(page.locator('.toolbar')).toBeVisible();
  });
});
