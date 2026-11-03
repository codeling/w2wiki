// SPDX-License-Identifier: MIT

// The buttons in the toolbar to choose the color scheme (the system's, light, dark), and the icons, which are
// inline SVGs showing the shape in a file (<use>)
const { test, expect } = require('@playwright/test');
const { savePage, expectReadable, pageBrightness, iconColor, barColors, loginBase } = require('./helpers');

const choose = (page, mode) => page.locator(`.themeswitch button[value="${mode}"]`).click();
const pressed = (page) => page.locator('.themeswitch button').evaluateAll((buttons) => Object.fromEntries(buttons.map((b) => [b.value, b.getAttribute('aria-pressed')])));

test.describe('icons', () => {
  test('are drawn from their files, with no violation of the content security policy', async ({ page, request }) => {
    const problems = [];
    page.on('console', (message) => { if (/content security policy|refused to/i.test(message.text())) problems.push(message.text()); });
    page.on('pageerror', (error) => problems.push(error.message));
    await savePage(request, 'Icons page', 'text');
    for (const url of ['/index.php/Icons%20page', '/index.php?action=edit&page=Icons%20page', '/index.php?action=all', '/index.php?action=upload']) {
      await page.goto(url);
      // (not the ones of the drawer of the editor, which is closed: they have no size)
      const icons = await page.locator('svg.icon use').evaluateAll((uses) => uses.filter((use) => !use.closest('.inactive')).map((use) => [use.getAttribute('href'), use.getBBox().width]));
      expect(icons.length, url).toBeGreaterThan(3);
      for (const [href, width] of icons) expect(width, `${href} is drawn on ${url}`).toBeGreaterThan(5);
    }
    await page.goto('/index.php?action=edit&page=Icons%20page');
    await page.click('#drawer-control');
    expect(await page.locator('#drawer svg.icon use').evaluate((use) => use.getBBox().width)).toBeGreaterThan(5);
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

  test('light is chosen, kept for the next pages, and undone with the button of the system', async ({ page }) => {
    await page.goto('/index.php');
    expect(await pressed(page)).toEqual({ auto: 'true', light: 'false', dark: 'false' });
    expect(await pageBrightness(page)).toBeLessThan(0.3);

    await choose(page, 'light');
    // (on this page without a script, the form loads the page again)
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    expect(await pressed(page)).toEqual({ auto: 'false', light: 'true', dark: 'false' });
    expect(await pageBrightness(page)).toBeGreaterThan(0.8);
    expect(await page.evaluate(() => getComputedStyle(document.documentElement).colorScheme)).toBe('light');
    expect(await iconColor(page, '.toolbar svg.icon')).toEqual([34, 34, 34]);
    expect((await barColors(page))[0][0]).toBeGreaterThan(0xc0);

    // (the next pages, and the next visit, have the choice)
    await page.goto('/index.php?action=all');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    expect(await pageBrightness(page)).toBeGreaterThan(0.8);
    await expectReadable(page, ['table td', 'table th', 'table a']);

    await choose(page, 'auto');
    await expect(page.locator('html')).not.toHaveAttribute('data-theme', /.*/);
    expect(await pageBrightness(page)).toBeLessThan(0.3);
    await page.reload();
    await expect(page.locator('html')).not.toHaveAttribute('data-theme', /.*/);
    expect(await pageBrightness(page)).toBeLessThan(0.3);
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

  test('dark is chosen, and everything is readable', async ({ page, request }) => {
    await savePage(request, 'Switch view', '# Heading\n\ntext with [[Missing page]] and `code`\n\n> quote\n');
    await page.goto('/index.php/Switch%20view');
    expect(await pageBrightness(page)).toBeGreaterThan(0.8);
    await choose(page, 'dark');
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

test('in the editor the choice is applied without loading the page again, so the text stays', async ({ page, request }) => {
  await savePage(request, 'Switch edit', 'saved text');
  await page.goto('/index.php?action=edit&page=Switch%20edit');
  await page.fill('#text', 'text which is not saved yet');
  await page.evaluate(() => { window.sameDocument = true; });
  await choose(page, 'dark');
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  expect(await pressed(page)).toEqual({ auto: 'false', light: 'false', dark: 'true' });
  expect(await page.evaluate(() => window.sameDocument)).toBe(true);
  await expect(page.locator('#text')).toHaveValue('text which is not saved yet');
  // (it was saved, too)
  expect((await page.context().cookies()).find((cookie) => cookie.name === 'w2_theme')?.value).toBe('dark');
  await choose(page, 'light');
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
  await expect(page.locator('#text')).toHaveValue('text which is not saved yet');
});

test.describe('without scripts', () => {
  test.use({ javaScriptEnabled: false, colorScheme: 'dark' });

  test('the buttons work as a form: the page is loaded again with the choice', async ({ page }) => {
    await page.goto('/index.php?action=all');
    await choose(page, 'light');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    expect(await pageBrightness(page)).toBeGreaterThan(0.8);
    expect(await pressed(page)).toEqual({ auto: 'false', light: 'true', dark: 'false' });
  });
});
