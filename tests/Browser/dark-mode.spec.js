// SPDX-License-Identifier: MIT

// Dark mode: with a dark color scheme, every view has dark backgrounds and readable text. Light mode is unchanged.
const { test, expect } = require('@playwright/test');
const { savePage, csrfToken, gif, png, upload, loginBase } = require('./helpers');

/**
 * Contrast ratios (WCAG) of the text of the elements found by the selectors, against the background behind them
 * (the first opaque background of the element or one of its ancestors), as a list of "selector: ratio".
 */
async function contrasts(page, selectors) {
  return page.evaluate((selectors) => {
    const parse = (value) => {
      const m = /rgba?\(([\d.]+)[, ]+([\d.]+)[, ]+([\d.]+)(?:[,/ ]+([\d.]+%?))?\)/.exec(value);
      if (!m) return null;
      let alpha = m[4] === undefined ? 1 : parseFloat(m[4]) / (m[4].endsWith('%') ? 100 : 1);
      return [Number(m[1]), Number(m[2]), Number(m[3]), alpha];
    };
    const luminance = ([r, g, b]) => {
      const [R, G, B] = [r, g, b].map((c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; });
      return 0.2126 * R + 0.7152 * G + 0.0722 * B;
    };
    const backdrop = (element) => {
      for (let e = element; e; e = e.parentElement) {
        const c = parse(getComputedStyle(e).backgroundColor);
        if (c && c[3] >= 0.99) return c;
      }
      return [255, 255, 255, 1]; // (the canvas: white, only if nothing sets a color)
    };
    const result = {};
    for (const selector of selectors) {
      const element = document.querySelector(selector);
      if (!element) { result[selector] = null; continue; }
      const fg = parse(getComputedStyle(element).color);
      const bg = backdrop(element);
      const [a, b] = [luminance(fg), luminance(bg)];
      result[selector] = Math.round(((Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)) * 10) / 10;
    }
    return result;
  }, selectors);
}

/** Every listed element exists and its text can be read: contrast 4.5 : 1, or 3 : 1 for the large text of headings */
async function expectReadable(page, selectors) {
  const ratios = await contrasts(page, selectors);
  for (const selector of selectors) {
    expect(ratios[selector], `${selector} is shown`).not.toBeNull();
    expect(ratios[selector], `contrast of ${selector}`).toBeGreaterThanOrEqual(4.5);
  }
}

/** The brightness (0 to 1) of the background of the page */
async function pageBrightness(page) {
  return page.evaluate(() => {
    const m = /rgba?\((\d+), (\d+), (\d+)/.exec(getComputedStyle(document.body).backgroundColor);
    return (Number(m[1]) + Number(m[2]) + Number(m[3])) / (3 * 255);
  });
}

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
    // (the close icon is dark, and inverted to show on the dark drawer)
    expect(await page.locator('#drawer img.icon').evaluate((e) => getComputedStyle(e).filter)).not.toBe('none');
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

  test('all pages: the table is dark, and the icons for a light background are inverted', async ({ page, request }) => {
    await savePage(request, 'Dark list', 'x');
    await page.goto('/index.php?action=all');
    expect(await pageBrightness(page)).toBeLessThan(0.3);
    await expectReadable(page, ['table td', 'table th', 'table a']);
    const icons = page.locator('table img.icon[src*="edit-dark.svg"]');
    expect(await icons.count()).toBeGreaterThan(0);
    expect(await icons.first().evaluate((e) => getComputedStyle(e).filter)).toContain('invert');
    // (the red delete icon shows on both, so it is kept as it is)
    expect(await page.locator('table img.icon[src*="delete-dark.svg"]').first().evaluate((e) => getComputedStyle(e).filter)).toBe('none');
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

  test('the colors and icons are the ones of the light scheme', async ({ page, request }) => {
    await savePage(request, 'Light view', text);
    await page.goto('/index.php/Light%20view');
    const style = (selector, property) => page.locator(selector).first().evaluate((e, p) => getComputedStyle(e)[p], property);
    expect(await style('body', 'backgroundColor')).toBe('rgb(238, 238, 238)');
    expect(await style('body', 'color')).toBe('rgb(0, 0, 0)');
    expect(await style('pre', 'backgroundColor')).toBe('rgb(255, 255, 204)');
    expect(await style('blockquote', 'backgroundColor')).toBe('rgb(240, 248, 240)');
    expect(await style('.main a.noexist', 'color')).toBe('rgb(187, 0, 0)');
    await page.goto('/index.php?action=all');
    expect(await style('table img.icon[src*="edit-dark.svg"]', 'filter')).toBe('none');
    await page.goto('/index.php?action=edit&page=Light%20view');
    expect(await style('#text', 'backgroundColor')).toBe('rgb(255, 255, 238)');
  });
});
