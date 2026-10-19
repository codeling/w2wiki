// SPDX-License-Identifier: MIT

// The search box sends a GET request: the results have an address which can be reloaded, bookmarked and shared
const { test, expect } = require('@playwright/test');

test('searching with the toolbar box gives results under an address which can be reloaded', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/index.php');
  await page.fill('#search', 'Markdown Syntax');
  await Promise.all([page.waitForURL(/action=search/), page.press('#search', 'Enter')]);

  const url = new URL(page.url());
  expect(url.pathname).toBe('/index.php');
  expect(url.searchParams.get('action')).toBe('search');
  expect(url.searchParams.get('q')).toBe('Markdown Syntax');
  await expect(page.locator('h1')).toHaveText('Search: Markdown Syntax');
  await expect(page.locator('a[href$="/MarkdownSyntax"]').first()).toBeVisible();

  // reloading shows the same results (a POST result would ask to send the form again)
  await page.reload();
  await expect(page.locator('h1')).toHaveText('Search: Markdown Syntax');

  // the address works in a new tab, without the page where the search started
  const other = await page.context().newPage();
  await other.goto(page.url());
  await expect(other.locator('h1')).toHaveText('Search: Markdown Syntax');
  await expect(other.locator('#search')).toBeVisible();
  expect(errors).toEqual([]);
});

test('search terms with special characters survive the form', async ({ page }) => {
  await page.goto('/index.php');
  const term = 'a&b=c <i>#x</i> "q" ü';
  await page.fill('#search', term);
  await Promise.all([page.waitForURL(/action=search/), page.press('#search', 'Enter')]);
  expect(new URL(page.url()).searchParams.get('q')).toBe(term);
  await expect(page.locator('h1')).toHaveText('Search: ' + term);
  await expect(page.locator('h1 i')).toHaveCount(0);
});

test('the search box is also there in the editor, and finds pages from there', async ({ page }) => {
  await page.goto('/index.php?action=edit&page=Home');
  await page.fill('#search', 'Welcome');
  // (the editor asks before leaving only if the text was changed, which it wasn't)
  await Promise.all([page.waitForURL(/action=search/), page.press('#search', 'Enter')]);
  expect(new URL(page.url()).searchParams.get('q')).toBe('Welcome');
  await expect(page.locator('h1')).toHaveText('Search: Welcome');
});
