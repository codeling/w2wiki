// Attempts to run scripts through page content, page names, search and uploaded file names
const { execFileSync } = require('child_process');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { watchDialogs, interact, activeContent, savePage, upload, gif } = require('./helpers');

// the same texts as the PHP tests use (tests/Support/MaliciousMarkdown.php)
const markdown = JSON.parse(execFileSync('php', [path.join(__dirname, '..', 'bin', 'payloads.php')], { encoding: 'utf8' }));

test.describe('stored in pages', () => {
  Object.entries(markdown).forEach(([name, text], index) => {
    test(name, async ({ page, request }) => {
      const dialogs = watchDialogs(page);
      const pageName = `Stored ${index}`;
      await savePage(request, pageName, text);
      await page.goto(`/index.php/${encodeURIComponent(pageName)}`);
      await interact(page);
      expect(dialogs).toEqual([]);
      expect(await activeContent(page)).toEqual([]);
    });
  });
});

test.describe('reflected', () => {
  const payload = '<img src=x onerror=alert(1)><svg onload=alert(2)>"><script>alert(3)</script>';
  const urls = {
    'search': `/index.php?action=search&q=${encodeURIComponent(payload)}`,
    'search by post': null,
    'page name in path': `/index.php/${encodeURIComponent(payload)}`,
    'page name in query': `/index.php?action=view&page=${encodeURIComponent(payload)}`,
    'edit': `/index.php?action=edit&page=${encodeURIComponent(payload)}`,
    'rename': `/index.php?action=rename&page=${encodeURIComponent(payload)}`,
    'delete': `/index.php?action=delete&page=${encodeURIComponent(payload)}`,
    'upload': `/index.php?action=upload&page=${encodeURIComponent(payload)}`,
    'image rename': `/index.php?action=imgRename&imgName=${encodeURIComponent(payload)}&prevpage=${encodeURIComponent(payload)}`,
    'unknown action': `/index.php?action=${encodeURIComponent(payload)}`,
  };
  for (const [name, url] of Object.entries(urls)) {
    if (url === null) continue;
    test(name, async ({ page }) => {
      const dialogs = watchDialogs(page);
      await page.goto(url);
      await interact(page);
      expect(dialogs).toEqual([]);
      const own = ['toggleDrawer(); return false;', 'history.go(-1);'];
      // (the wiki's own handlers on the editor and forms are fine, injected ones are not)
      const handlers = await page.evaluate(() => [...document.querySelectorAll('*')].flatMap((e) => [...e.attributes].filter((a) => a.name.startsWith('on')).map((a) => a.value)));
      for (const handler of handlers) expect(own).toContain(handler);
    });
  }

  test('search form', async ({ page }) => {
    const dialogs = watchDialogs(page);
    await page.goto('/index.php');
    await page.fill('#search', payload);
    await page.press('#search', 'Enter');
    await page.waitForLoadState('load');
    await interact(page);
    expect(dialogs).toEqual([]);
    await expect(page.locator('h1')).toContainText('<img src=x onerror=alert(1)>');
  });
});

test('page text in the editor is shown as text', async ({ page, request }) => {
  const dialogs = watchDialogs(page);
  const text = '</textarea><img src=x onerror=alert(1)><script>alert(2)</script>';
  await savePage(request, 'Editor payload', text);
  await page.goto('/index.php?action=edit&page=Editor%20payload');
  await interact(page);
  expect(dialogs).toEqual([]);
  await expect(page.locator('#text')).toHaveValue(text);
});

test('uploaded file names are shown as text', async ({ page, request }) => {
  const dialogs = watchDialogs(page);
  const response = await upload(request, '<img src=x onerror=alert(1)>.gif', gif(), 'image/gif');
  expect(response.status()).toBe(303);
  await page.goto('/index.php?action=upload');
  await interact(page);
  expect(dialogs).toEqual([]);
  await expect(page.locator('.uploadFileName').first()).toContainText('<img_src=x_onerror=alert(1)>.gif');
  await page.goto(response.headers()['location']);
  await interact(page);
  expect(dialogs).toEqual([]);
});

test('forms cannot be used by other sites (no token, no change)', async ({ page, request }) => {
  await page.goto('/index.php');
  const response = await request.post('/index.php', { form: { action: 'save', page: 'Home', newText: 'pwned', isNew: '' }, maxRedirects: 0 });
  expect(response.status()).toBe(403);
  await page.goto('/index.php');
  await expect(page.locator('.main')).not.toContainText('pwned');
});

test('normal pages work in a browser', async ({ page, request }) => {
  const dialogs = watchDialogs(page);
  await savePage(request, 'Browser page', '# Heading\n\nSome *emphasis* and [[Home]] and ![x](/images/none.gif)');
  await page.goto('/index.php/Browser%20page');
  await expect(page.locator('h1')).toContainText('Heading');
  await expect(page.locator('em')).toHaveText('emphasis');
  await page.click('.main a[href="/index.php/Home"]');
  await expect(page.locator('h1')).toContainText('Welcome to W2');
  expect(dialogs).toEqual([]);
});
