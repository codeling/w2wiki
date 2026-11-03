// SPDX-License-Identifier: MIT

// Dark mode: with a dark color scheme, every view has dark backgrounds and readable text. Light mode is unchanged.
const { test, expect } = require('@playwright/test');
const { savePage, csrfToken, gif, png, upload, loginBase, contrasts, expectReadable, barContrasts, barColors, pageBrightness, iconColor } = require('./helpers');

const text = [
  '# Dark heading', '', 'Text with a [[Missing page]] and a [[Home]] link and `inline code`.', '',
  '> A quote', '', '    code block', '', '| a | b |', '|---|---|', '| 1 | 2 |',
].join('\n');

test.describe('dark color scheme', () => {
  test.use({ colorScheme: 'dark' });

  test('the document declares both schemes, so that form controls follow', async ({ page }) => {
    await page.goto('/index.php');
    await expect(page.locator('meta[name="color-scheme"]')).toHaveAttribute('content', 'light dark');
    expect(await page.evaluate(() => getComputedStyle(document.documentElement).colorScheme)).toBe('light dark');
  });

  test('the title bar and the toolbar are dark, with light text and light icons', async ({ page }) => {
    await page.goto('/index.php');
    const colors = await barColors(page);
    expect([colors[0], colors[3]]).toEqual([[85, 85, 85], [68, 68, 68]]);
    for (const [name, ratio] of Object.entries(await barContrasts(page))) expect(ratio, name).toBeGreaterThanOrEqual(4.5);
    // (the icons are painted with the color of the text of the bar)
    expect(await iconColor(page, '.toolbar svg.icon')).toEqual([238, 238, 238]);
  });

  test('view: page content, tables, code, red links, notes, the sidebar and the search box are readable', async ({ page, request }) => {
    await savePage(request, 'Dark view', text);
    await page.goto('/index.php/Dark%20view');
    expect(await pageBrightness(page)).toBeLessThan(0.3);
    await expectReadable(page, ['body', 'h1', '.main p', '.main a.noexist', '.main a:not(.noexist)', 'pre', 'code', 'blockquote', 'table td', 'input.search']);
    // the code blocks are not the pale yellow of the light scheme
    const codeBackground = await page.locator('pre').evaluate((e) => getComputedStyle(e).backgroundColor);
    expect(codeBackground).not.toBe('rgb(255, 255, 204)');
    await page.goto('/index.php/Dark%20view?linkshere=true');
    await expectReadable(page, ['.linkshere']);
  });

  test('view: the mark after external links is a mask in the color of the link, not an image', async ({ page, request }) => {
    await savePage(request, 'Dark external', 'A [link](http://example.com/).');
    await page.goto('/index.php/Dark%20external');
    const mark = await page.locator('.main a[href^="http"]').evaluate((link) => {
      const after = getComputedStyle(link, '::after');
      return { mask: after.maskImage || after.webkitMaskImage, background: after.backgroundColor, link: getComputedStyle(link).color, width: after.width };
    });
    expect(mark.mask).toContain('internet.svg');
    expect(mark.background).toBe(mark.link);
    expect(parseFloat(mark.width)).toBeGreaterThan(8);
  });

  test('edit: the note about a new page is readable', async ({ page }) => {
    await page.goto('/index.php?action=edit&page=Dark%20new%20page');
    await expect(page.locator('.note')).toBeVisible();
    await expectReadable(page, ['.note']);
  });

  test('edit: the text area, the buttons and the formatting drawer', async ({ page, request }) => {
    await savePage(request, 'Dark edit', 'text to edit');
    await page.goto('/index.php?action=edit&page=Dark%20edit');
    expect(await pageBrightness(page)).toBeLessThan(0.3);
    await expectReadable(page, ['#text', 'input[type="button"]', 'input[type="submit"]']);
    const textBackground = await page.locator('#text').evaluate((e) => getComputedStyle(e).backgroundColor);
    expect(textBackground).not.toBe('rgb(255, 255, 238)');
    await page.click('#drawer-control');
    await expect(page.locator('#drawer')).toBeVisible();
    await expectReadable(page, ['#drawer', '#drawer h5']);
    // (the close icon has the color of the text of the drawer)
    expect(await iconColor(page, '#drawer svg.icon')).toEqual([228, 228, 228]);
  });

  test('edit: the differences after a conflicting change are readable', async ({ page, request }) => {
    await savePage(request, 'Dark conflict', 'first');
    await page.goto('/index.php?action=edit&page=Dark%20conflict');
    // (somebody else saves the page, without the hash of the text they started from)
    const token = await csrfToken(request);
    await request.post('/index.php', { form: { action: 'save', page: 'Dark conflict', newText: 'second, from somebody else', gitmsg: '', csrf_token: token }, maxRedirects: 0 });
    await page.fill('#text', 'mine');
    await page.click('#save');
    await expect(page.locator('.diff')).toBeVisible();
    await expectReadable(page, ['.diff', '.diff .removed', '.diff .added', '.note']);
  });

  test('all pages: the table is dark, and the icons are light', async ({ page, request }) => {
    await savePage(request, 'Dark list', 'x');
    await page.goto('/index.php?action=all');
    expect(await pageBrightness(page)).toBeLessThan(0.3);
    await expectReadable(page, ['table td', 'table th', 'table a']);
    expect(await page.locator('table svg.icon-edit').count()).toBeGreaterThan(0);
    expect(await iconColor(page, 'table svg.icon-edit')).toEqual([240, 240, 240]);
    // (the delete icon is red, a lighter red in the dark scheme)
    expect(await iconColor(page, 'table svg.icon-delete')).toEqual([255, 107, 122]);
  });

  test('upload: the list and the dialog with the crop frame', async ({ page, request }) => {
    expect((await upload(request, 'dark-list.gif', gif(), 'image/gif')).status()).toBeLessThan(400);
    await page.goto('/index.php?action=upload');
    await expectReadable(page, ['table td', 'table th', 'pre']);
    await page.click('#uploadbutton');
    await expect(page.locator('#uploaddialog')).toBeVisible();
    await expectReadable(page, ['#uploaddialog', '#uploaddialog h5', '#uploaddialog p']);
    expect(await page.locator('#uploaddialog').evaluate((e) => getComputedStyle(e).backgroundColor)).not.toBe('rgb(238, 238, 238)');
    if (await page.locator('#cropbox').count()) {
      await page.setInputFiles('#uploadfile', { name: 'dark-crop.png', mimeType: 'image/png', buffer: png(400, 200) });
      await expect(page.locator('#cropsection')).toBeVisible();
      await expectReadable(page, ['#cropsection', '#resultsize']);
    }
  });

  test('login: the form is dark', async ({ page }) => {
    await page.goto(loginBase() + '/index.php');
    await expect(page.locator('input[name="p"]')).toBeVisible();
    expect(await pageBrightness(page)).toBeLessThan(0.3);
    await expectReadable(page, ['h1', 'form', 'input[name="p"]', 'input[type="submit"]']);
    await page.fill('input[name="p"]', 'wrong');
    await page.click('input[type="submit"]');
    await expect(page.locator('.note')).toBeVisible();
    await expectReadable(page, ['.note']);
  });
});

test.describe('light color scheme', () => {
  test.use({ colorScheme: 'light' });

  test('the title bar and the toolbar are light, with dark text and dark icons', async ({ page }) => {
    await page.goto('/index.php');
    for (const [name, ratio] of Object.entries(await barContrasts(page))) expect(ratio, name).toBeGreaterThanOrEqual(4.5);
    for (const [red] of await barColors(page)) expect(red, 'the bars are light').toBeGreaterThan(0xc0);
    expect(await iconColor(page, '.toolbar svg.icon')).toEqual([34, 34, 34]);
    expect(await page.locator('.titlebar').evaluate((e) => getComputedStyle(e).backgroundImage)).toContain('linear-gradient');
  });

  test('the colors of the page and the icons are the ones of the light scheme', async ({ page, request }) => {
    await savePage(request, 'Light view', text);
    await page.goto('/index.php/Light%20view');
    const style = (selector, property) => page.locator(selector).first().evaluate((e, p) => getComputedStyle(e)[p], property);
    expect(await style('body', 'backgroundColor')).toBe('rgb(238, 238, 238)');
    expect(await style('body', 'color')).toBe('rgb(0, 0, 0)');
    expect(await style('pre', 'backgroundColor')).toBe('rgb(255, 255, 204)');
    expect(await style('blockquote', 'backgroundColor')).toBe('rgb(240, 248, 240)');
    expect(await style('.main a.noexist', 'color')).toBe('rgb(187, 0, 0)');
    await page.goto('/index.php?action=all');
    expect(await iconColor(page, 'table svg.icon-edit')).toEqual([15, 15, 15]);
    expect(await iconColor(page, 'table svg.icon-delete')).toEqual([218, 68, 83]);
    await page.goto('/index.php?action=edit&page=Light%20view');
    expect(await style('#text', 'backgroundColor')).toBe('rgb(255, 255, 238)');
  });
});
