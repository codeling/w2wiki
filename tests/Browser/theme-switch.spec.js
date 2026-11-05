// SPDX-License-Identifier: MIT

// The button in the toolbar to change the color scheme (the system's, light, dark, and again), and the icons, which
// are inline SVGs showing the shape in a file (<use>)
const { test, expect } = require('@playwright/test');
const { savePage, expectReadable, pageBrightness, iconColor, barColors, loginBase } = require('./helpers');

const toggle = (page) => page.locator('.themeswitch button').click();
/** The icon of the button which is shown (the one for the scheme in use), as the name of its scheme: auto, light or dark */
const shownIcon = (page) => page.locator('.themeswitch svg.icon').evaluateAll(
  (icons) => icons.filter((icon) => getComputedStyle(icon).display !== 'none').map((icon) => icon.getAttribute('class').replace(/^.*icon-theme-/, '')));
const nextMode = (page) => page.locator('.themeswitch button').getAttribute('value');

test.describe('icons', () => {
  test('are drawn from their files, with no violation of the content security policy', async ({ page, request }) => {
    const problems = [];
    page.on('console', (message) => { if (/content security policy|refused to/i.test(message.text())) problems.push(message.text()); });
    page.on('pageerror', (error) => problems.push(error.message));
    await savePage(request, 'Icons page', 'text');
    for (const url of ['/index.php/Icons%20page', '/index.php?action=edit&page=Icons%20page', '/index.php?action=all', '/index.php?action=upload']) {
      await page.goto(url);
      // (not the ones which are not shown: in the drawer of the editor, which is closed, in the list of keyboard shortcuts, which is a closed dialog, and the two of the theme button which don't fit the scheme: they have no size)
      const icons = await page.locator('svg.icon use').evaluateAll((uses) => uses.filter((use) => !use.closest('.inactive, dialog:not([open])') && getComputedStyle(use.ownerSVGElement).display !== 'none').map((use) => [use.getAttribute('href'), use.getBBox().width]));
      expect(icons.length, url).toBeGreaterThan(3);
      for (const [href, width] of icons) expect(width, `${href} is drawn on ${url}`).toBeGreaterThan(5);
    }
    await page.goto('/index.php?action=edit&page=Icons%20page');
    await page.click('#drawer-control');
    expect(await page.locator('#drawer svg.icon use').evaluate((use) => use.getBBox().width)).toBeGreaterThan(5);
    await page.locator('h1, .titlebar .title').first().click();
    await page.keyboard.press('?');
    expect(await page.locator('#shortcuts svg.icon use').evaluate((use) => use.getBBox().width)).toBeGreaterThan(5);
    expect(problems).toEqual([]);
  });

  test('have a name for screen readers and a tooltip', async ({ page }) => {
    await page.goto('/index.php');
    const home = page.locator('.toolbar a[href="/index.php"] svg.icon');
    await expect(home).toHaveAttribute('role', 'img');
    await expect(home).toHaveAttribute('aria-label', 'Home');
    expect(await home.locator('title').textContent()).toBe('Home');
  });
});

test.describe('with a dark system', () => {
  test.use({ colorScheme: 'dark' });

  test('the button goes through light and dark, back to the system, and its choice is kept', async ({ page }) => {
    await page.goto('/index.php');
    expect(await shownIcon(page)).toEqual(['auto']);
    expect(await nextMode(page)).toBe('light');
    expect(await pageBrightness(page)).toBeLessThan(0.3);

    await toggle(page);
    // (the script applies the choice without loading the page again; the next pages have it from the cookie)
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    expect(await shownIcon(page)).toEqual(['light']);
    expect(await nextMode(page)).toBe('dark');
    expect(await pageBrightness(page)).toBeGreaterThan(0.8);
    expect(await page.evaluate(() => getComputedStyle(document.documentElement).colorScheme)).toBe('light');
    expect(await iconColor(page, '.toolbar svg.icon')).toEqual([34, 34, 34]);
    expect((await barColors(page))[0][0]).toBeGreaterThan(0xc0);

    // (the next pages, and the next visit, have the choice)
    await page.goto('/index.php?action=all');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    expect(await shownIcon(page)).toEqual(['light']);
    expect(await pageBrightness(page)).toBeGreaterThan(0.8);
    await expectReadable(page, ['table td', 'table th', 'table a']);

    await toggle(page);
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    expect(await shownIcon(page)).toEqual(['dark']);
    expect(await nextMode(page)).toBe('auto');
    expect(await pageBrightness(page)).toBeLessThan(0.3);

    await toggle(page);
    await expect(page.locator('html')).not.toHaveAttribute('data-theme', /.*/);
    expect(await shownIcon(page)).toEqual(['auto']);
    expect(await pageBrightness(page)).toBeLessThan(0.3);
    await page.reload();
    await expect(page.locator('html')).not.toHaveAttribute('data-theme', /.*/);
    expect(await shownIcon(page)).toEqual(['auto']);
  });

  test('the icon of the button names the scheme in use', async ({ page }) => {
    await page.goto('/index.php');
    const icon = page.locator('.themeswitch svg.icon:visible');
    await expect(icon).toHaveCount(1);
    await expect(icon).toHaveAttribute('aria-label', 'Color scheme: System');
    await toggle(page);
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    await expect(page.locator('.themeswitch svg.icon:visible')).toHaveAttribute('aria-label', 'Color scheme: Light');
  });

  test('the login page follows the choice too', async ({ page, context }) => {
    await context.addCookies([{ name: 'w2_theme', value: 'light', url: loginBase() }]);
    await page.goto(loginBase() + '/index.php');
    await expect(page.locator('input[name="p"]')).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    expect(await pageBrightness(page)).toBeGreaterThan(0.8);
    await expectReadable(page, ['h1', 'form', 'input[name="p"]', 'input[type="submit"]']);
  });

  test('a cookie with another value is ignored', async ({ page, context, baseURL }) => {
    await context.addCookies([{ name: 'w2_theme', value: 'blue', url: baseURL }]);
    await page.goto('/index.php');
    await expect(page.locator('html')).not.toHaveAttribute('data-theme', /.*/);
  });
});

test.describe('with a light system', () => {
  test.use({ colorScheme: 'light' });

  test('dark is reached with two presses, and everything is readable', async ({ page, request }) => {
    await savePage(request, 'Switch view', '# Heading\n\ntext with [[Missing page]] and `code`\n\n> quote\n');
    await page.goto('/index.php/Switch%20view');
    expect(await pageBrightness(page)).toBeGreaterThan(0.8);
    expect(await shownIcon(page)).toEqual(['auto']);
    await toggle(page);
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    await toggle(page);
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    expect(await pageBrightness(page)).toBeLessThan(0.3);
    expect(await page.evaluate(() => getComputedStyle(document.documentElement).colorScheme)).toBe('dark');
    await expectReadable(page, ['body', 'h1', '.main p', '.main a.noexist', 'code', 'blockquote', 'input.search']);
    expect(await iconColor(page, '.toolbar svg.icon')).toEqual([238, 238, 238]);
    // (form controls follow: the search box and the buttons are dark too)
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    expect(await pageBrightness(page)).toBeLessThan(0.3);
  });
});

test('the choice is posted to the script of the wiki (the form has a field called "action", which form.action would be)', async ({ page, baseURL }) => {
  const posts = [];
  page.on('request', (request) => { if (request.method() === 'POST') posts.push(request.url()); });
  await page.goto('/index.php/Home');
  await toggle(page);
  await expect(page.locator('html')).toHaveAttribute('data-theme', /light|dark/);
  expect(posts).toEqual([baseURL + '/index.php']);
});

test('in the editor the change is applied without loading the page again, so the text stays', async ({ page, request }) => {
  await savePage(request, 'Switch edit', 'saved text');
  await page.goto('/index.php?action=edit&page=Switch%20edit');
  await page.fill('#text', 'text which is not saved yet');
  await page.evaluate(() => { window.sameDocument = true; });
  await toggle(page);
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
  expect(await shownIcon(page)).toEqual(['light']);
  expect(await nextMode(page)).toBe('dark');
  expect(await page.evaluate(() => window.sameDocument)).toBe(true);
  await expect(page.locator('#text')).toHaveValue('text which is not saved yet');
  // (it was saved, too)
  expect((await page.context().cookies()).find((cookie) => cookie.name === 'w2_theme')?.value).toBe('light');
  await toggle(page);
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  expect(await shownIcon(page)).toEqual(['dark']);
  await toggle(page);
  await expect(page.locator('html')).not.toHaveAttribute('data-theme', /.*/);
  expect(await shownIcon(page)).toEqual(['auto']);
  expect(await nextMode(page)).toBe('light');
  await expect(page.locator('#text')).toHaveValue('text which is not saved yet');
  expect((await page.context().cookies()).find((cookie) => cookie.name === 'w2_theme')).toBeUndefined();
});

test.describe('without scripts', () => {
  test.use({ javaScriptEnabled: false, colorScheme: 'dark' });

  test('the button works as a form: the page is loaded again with the change', async ({ page }) => {
    await page.goto('/index.php?action=all');
    await toggle(page);
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    expect(await pageBrightness(page)).toBeGreaterThan(0.8);
    expect(await shownIcon(page)).toEqual(['light']);
    expect(await nextMode(page)).toBe('dark');
  });
});
