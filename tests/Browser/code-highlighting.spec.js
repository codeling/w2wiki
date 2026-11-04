// SPDX-License-Identifier: MIT

// Highlighted code blocks (spans with classes made on the server, src/Highlighter.php): readable in both color
// schemes, the system's and the one chosen with the button, with no violation of the content security policy
const { test, expect } = require('@playwright/test');
const { savePage, expectReadable } = require('./helpers');

const text = [
  '```php', '<?php', '// a comment', '$name = "text"; // <b>not bold</b>', 'function f(int $a): bool { return 1 + true; }', '#[Attr]', '```', '',
  '```html', '<a href="x">link</a>', '```', '',
  '```diff', '-old line', '+new line', '```',
].join('\n');
const tokens = ['.hl-k', '.hl-s', '.hl-c', '.hl-n', '.hl-t', '.hl-v', '.hl-a', '.hl-p', '.hl-d-add', '.hl-d-del'];

/** Colors of the token classes, by selector */
const colors = (page) => page.evaluate((selectors) => Object.fromEntries(selectors.map((s) => [s, getComputedStyle(document.querySelector(s)).color])), tokens);

[['light', null], ['dark', null], ['light', 'dark'], ['dark', 'light']].forEach(([scheme, cookie], index) => {
  test.describe(`${scheme} system${cookie ? `, ${cookie} chosen` : ''}`, () => {
    test.use({ colorScheme: scheme });

    test('highlighted code is readable, in the code font, and breaks no rule of the content security policy', async ({ page, context, request, baseURL }) => {
      const problems = [];
      page.on('console', (message) => { if (/content security policy|refused to/i.test(message.text())) problems.push(message.text()); });
      page.on('pageerror', (error) => problems.push(error.message));
      if (cookie) await context.addCookies([{ name: 'w2_theme', value: cookie, url: baseURL }]);
      // (a page per test: saving a new page with the name of an existing one is a conflict)
      await savePage(request, `Highlighted code ${index}`, text);
      await page.goto(`/index.php/Highlighted%20code%20${index}`);
      await expectReadable(page, tokens);
      // (the spans are text in the code, not in the font that the style sheet gives every element)
      const fonts = await page.locator('pre code span').evaluateAll((spans) => [...new Set(spans.map((s) => getComputedStyle(s).fontFamily + ' ' + getComputedStyle(s).fontSize))]);
      expect(fonts).toEqual([await page.locator('pre code').first().evaluate((c) => getComputedStyle(c).fontFamily + ' ' + getComputedStyle(c).fontSize)]);
      // (the text is the code, escaped: no element made of it)
      await expect(page.locator('pre code b')).toHaveCount(0);
      await expect(page.locator('pre code').first()).toContainText('// <b>not bold</b>');
      const used = await colors(page);
      expect(new Set(Object.values(used).slice(0, 8)).size, 'every class has its own color').toBe(8);
      expect(problems).toEqual([]);
    });
  });
});

test('the chosen dark scheme gives the same colors as a dark system', async ({ browser, request, baseURL }) => {
  await savePage(request, 'Highlighted code in both', text);
  const results = [];
  for (const [scheme, cookie] of [['dark', null], ['light', 'dark']]) {
    const context = await browser.newContext({ colorScheme: scheme, baseURL });
    if (cookie) await context.addCookies([{ name: 'w2_theme', value: cookie, url: baseURL }]);
    const page = await context.newPage();
    await page.goto('/index.php/Highlighted%20code%20in%20both');
    results.push(await colors(page));
    await context.close();
  }
  expect(results[1]).toEqual(results[0]);
});
