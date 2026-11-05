// SPDX-License-Identifier: MIT

// The preview in the editor: the text rendered by the server like the page, next to the text or instead of it
const { execFileSync } = require('child_process');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { watchDialogs, savePage } = require('./helpers');

// the same texts as the PHP tests use (tests/Support/MaliciousMarkdown.php)
const markdown = JSON.parse(execFileSync('php', [path.join(__dirname, '..', 'bin', 'payloads.php')], { encoding: 'utf8' }));

const wide = { width: 1280, height: 800 };
const narrow = { width: 390, height: 700 };
// (the same function for page.route() and page.unroute(), which only removes the route of the same matcher)
const previewRequests = (url) => url.href.includes('/api.php?task=preview');

async function openPreview(page) {
  await page.click('#previewbutton');
  await expect(page.locator('#preview')).toBeVisible();
  await expect(page.locator('#previewbutton')).toHaveAttribute('aria-pressed', 'true');
}

/** Script errors, and the messages about the Content-Security-Policy, which must not happen */
function watchProblems(page) {
  const problems = [];
  page.on('pageerror', (error) => problems.push(error.message));
  page.on('console', (message) => { if (/content security policy|refused to/i.test(message.text())) problems.push(message.text()); });
  return problems;
}

test.beforeEach(async ({ page }) => {
  // (leaving the editor with changes asks for confirmation; the tests always leave)
  page.on('dialog', (dialog) => dialog.accept().catch(() => {}));
});

test('the preview is hidden at first, and shows the typed text like the page', async ({ page }) => {
  const problems = watchProblems(page);
  await page.setViewportSize(wide);
  await page.goto('/index.php?action=new');
  await expect(page.locator('#preview')).toBeHidden();
  await expect(page.locator('#previewbutton')).toBeVisible();
  await expect(page.locator('#previewbutton')).toHaveAttribute('aria-pressed', 'false');
  await openPreview(page);
  await page.click('#text');
  await page.keyboard.type('# Hello\n\nSee [[Home]] and [[Nowhere]], *emphasized*.');
  await expect(page.locator('#preview h1')).toHaveText('Hello');
  await expect(page.locator('#preview em')).toHaveText('emphasized');
  await expect(page.locator('#preview a[href="/index.php/Home"]')).toHaveText('Home');
  await expect(page.locator('#preview a.noexist')).toHaveText('Nowhere');
  await expect(page.locator('#text')).toHaveValue('# Hello\n\nSee [[Home]] and [[Nowhere]], *emphasized*.');
  // (styled like the page: missing pages in red, the text in the colors of the page)
  const colors = await page.evaluate(() => ({
    missing: getComputedStyle(document.querySelector('#preview a.noexist')).color,
    text: getComputedStyle(document.querySelector('#preview .previewcontent p')).color,
  }));
  expect(colors).toEqual({ missing: 'rgb(187, 0, 0)', text: 'rgb(0, 0, 0)' });
  expect(problems).toEqual([]);
});

test('existing page: the preview shows the saved text when it is opened', async ({ page, request }) => {
  await savePage(request, 'Preview existing', '## Saved heading\n\nSaved text');
  await page.goto('/index.php?action=edit&page=' + encodeURIComponent('Preview existing'));
  await openPreview(page);
  await expect(page.locator('#preview h2')).toHaveText('Saved heading');
});

test('the choice to show the preview is remembered in the browser', async ({ page }) => {
  await page.goto('/index.php?action=edit&page=Home');
  await openPreview(page);
  await expect(page.locator('#preview h1').first()).toBeVisible();
  await page.goto('/index.php?action=new');
  await expect(page.locator('#preview')).toBeVisible();
  await expect(page.locator('#previewbutton')).toHaveAttribute('aria-pressed', 'true');
  await page.click('#previewbutton');
  await expect(page.locator('#preview')).toBeHidden();
  await page.goto('/index.php?action=edit&page=Home');
  await expect(page.locator('#preview')).toBeHidden();
  await expect(page.locator('#previewbutton')).toHaveAttribute('aria-pressed', 'false');
});

test('without local storage, the preview still works (and starts hidden)', async ({ page }) => {
  await page.addInitScript(() => {
    Object.defineProperty(window, 'localStorage', { get() { throw new Error('no storage'); } });
  });
  const problems = watchProblems(page);
  await page.goto('/index.php?action=new');
  await expect(page.locator('#preview')).toBeHidden();
  await openPreview(page);
  await page.fill('#text', '# Without storage');
  await expect(page.locator('#preview h1')).toHaveText('Without storage');
  expect(problems).toEqual([]);
});

test('typing sends the text once it pauses, and only the latest answer is shown', async ({ page }) => {
  const requests = [];
  let delayFirst = true;
  await page.route(previewRequests, async (route) => {
    const text = new URLSearchParams(route.request().postData() || '').get('newText');
    requests.push(text);
    if (delayFirst && text === 'first') {
      delayFirst = false;
      // (an answer which comes late, after the next one: it must not replace it)
      await new Promise((resolve) => setTimeout(resolve, 1500));
    }
    await route.continue().catch(() => { /* cancelled by the page */ });
  });
  await page.goto('/index.php?action=new');
  await openPreview(page);
  await page.click('#text');
  await page.keyboard.type('first', { delay: 30 });
  // (the pause sends "first", whose answer is delayed)
  await expect.poll(() => requests.includes('first')).toBe(true);
  await page.keyboard.type(' second', { delay: 30 });
  await expect(page.locator('#preview .previewcontent p')).toHaveText('first second');
  await page.waitForTimeout(1800);
  await expect(page.locator('#preview .previewcontent p')).toHaveText('first second');
  // (one request when the preview was opened, then one for each pause: not one for every key)
  expect(requests.length).toBeLessThanOrEqual(4);
});

test('wide screen: text and preview are side by side, each with its own scrollbar, and Save is visible', async ({ page }) => {
  await page.setViewportSize(wide);
  await page.goto('/index.php?action=new');
  await openPreview(page);
  await page.fill('#text', Array.from({ length: 300 }, (_, i) => 'line ' + i).join('\n\n'));
  await expect(page.locator('#preview .previewcontent p').nth(299)).toHaveText('line 299');
  const text = await page.locator('#text').boundingBox();
  const preview = await page.locator('#preview').boundingBox();
  const save = await page.locator('#save').boundingBox();
  expect(preview.x).toBeGreaterThanOrEqual(text.x + text.width);
  expect(preview.x + preview.width).toBeGreaterThan(wide.width - 30);
  expect(text.width).toBeGreaterThan(wide.width / 3);
  expect(preview.width).toBeGreaterThan(wide.width / 3);
  expect(Math.abs(preview.y - text.y)).toBeLessThan(2);
  expect(Math.abs(preview.height - text.height)).toBeLessThan(2);
  expect(save.y + save.height).toBeLessThanOrEqual(wide.height);
  expect(text.y + text.height).toBeLessThanOrEqual(save.y);
  const scrolling = await page.evaluate(() => {
    const t = document.getElementById('text');
    const p = document.getElementById('preview');
    return { page: document.documentElement.scrollHeight - window.innerHeight, text: t.scrollHeight > t.clientHeight, preview: p.scrollHeight > p.clientHeight };
  });
  expect(scrolling).toEqual({ page: 0, text: true, preview: true });
  // (scrolling the text scrolls the preview to about the same part)
  await page.locator('#text').evaluate((t) => { t.scrollTop = t.scrollHeight; });
  await expect.poll(() => page.locator('#preview').evaluate((p) => p.scrollTop / (p.scrollHeight - p.clientHeight))).toBeGreaterThan(0.95);
  // (closing it gives the whole width to the text again)
  await page.click('#previewbutton');
  await expect(page.locator('#preview')).toBeHidden();
  const alone = await page.locator('#text').boundingBox();
  expect(alone.x + alone.width).toBeGreaterThan(wide.width - 30);
});

test('narrow screen: the button switches between the text and the preview', async ({ page }) => {
  await page.setViewportSize(narrow);
  await page.goto('/index.php?action=new');
  await page.fill('#text', '# Small screen\n\n' + Array.from({ length: 200 }, (_, i) => 'line ' + i).join('\n\n'));
  await openPreview(page);
  await expect(page.locator('#text')).toBeHidden();
  await expect(page.locator('#preview h1')).toHaveText('Small screen');
  const preview = await page.locator('#preview').boundingBox();
  const save = await page.locator('#save').boundingBox();
  expect(preview.width).toBeGreaterThan(narrow.width - 40);
  expect(save.y + save.height).toBeLessThanOrEqual(narrow.height);
  expect(preview.y + preview.height).toBeLessThanOrEqual(save.y);
  expect(await page.evaluate(() => document.documentElement.scrollHeight - window.innerHeight)).toBeLessThanOrEqual(0);
  await page.click('#previewbutton');
  await expect(page.locator('#text')).toBeVisible();
  await expect(page.locator('#preview')).toBeHidden();
  await expect(page.locator('#text')).toHaveValue(/^# Small screen/);
});

test('narrow screen: a link inserted while the preview is shown goes into the text', async ({ page, request }) => {
  await savePage(request, 'Page linking to preview', 'See [[Gadgetpreview]] here.');
  await page.setViewportSize(narrow);
  await page.goto('/index.php/' + encodeURIComponent('Software/Gadgetpreview'));
  await page.fill('#text', 'start ');
  await openPreview(page);
  await page.focus('#title');
  await page.click('button.backlink');
  await expect(page.locator('#text')).toHaveValue('start [[Page linking to preview]]');
  await expect(page.locator('#title')).toHaveValue('Software/Gadgetpreview');
  // (an inserted link is a change of the text like typing: the preview follows)
  await expect(page.locator('#preview a')).toHaveText('Page linking to preview');
});

test('links in the preview open in a new tab, and the editor keeps the text', async ({ page, context }) => {
  await page.goto('/index.php?action=new');
  await openPreview(page);
  await page.fill('#text', 'Go to [[Home]] or [outside](http://127.0.0.1:9/).');
  const link = page.locator('#preview a[href="/index.php/Home"]');
  await expect(link).toHaveAttribute('target', '_blank');
  await expect(link).toHaveAttribute('rel', 'noopener');
  await expect(page.locator('#preview a[href^="http"]')).toHaveAttribute('target', '_blank');
  const editor = page.url();
  const [popup] = await Promise.all([context.waitForEvent('page'), link.click()]);
  await popup.waitForLoadState();
  expect(popup.url()).toContain('/index.php/Home');
  await popup.close();
  expect(page.url()).toBe(editor);
  await expect(page.locator('#text')).toHaveValue('Go to [[Home]] or [outside](http://127.0.0.1:9/).');
});

test('links to headings scroll the preview; its anchors do not take the ids of the editor', async ({ page }) => {
  await page.setViewportSize(wide);
  await page.goto('/index.php?action=new');
  await openPreview(page);
  const filler = Array.from({ length: 150 }, (_, i) => 'line ' + i).join('\n\n');
  await page.fill('#text', '[To the end](#save)\n\n' + filler + '\n\n# save\n\n# text');
  await expect(page.locator('#preview h1').nth(1)).toHaveText('text');
  // (a heading "save" must not make document.getElementById('save') find it instead of the button)
  expect(await page.locator('#preview [id]').count()).toBe(0);
  expect(await page.evaluate(() => document.getElementById('save').tagName + ' ' + document.getElementById('text').tagName)).toBe('INPUT TEXTAREA');
  await expect(page.locator('#preview a[data-anchor="save"]')).toHaveCount(1);
  const editor = page.url();
  await page.click('#preview a[href="#save"]');
  await expect.poll(() => page.locator('#preview').evaluate((p) => p.scrollTop)).toBeGreaterThan(100);
  expect(page.url()).toBe(editor);
  // (Ctrl+S still finds the Save button, and saves)
  await page.fill('#title', 'Preview anchors');
  await page.keyboard.press('Control+s');
  await page.waitForURL(/Preview%20anchors/);
  await expect(page.locator('.main h1').first()).toContainText('save');
});

test('errors are shown in the preview, and the text is kept', async ({ page }) => {
  await page.goto('/index.php?action=new');
  await openPreview(page);
  await page.fill('#text', '# Before');
  await expect(page.locator('#preview h1')).toHaveText('Before');
  const status = page.locator('#preview .previewstatus');
  await expect(status).toBeHidden();

  // the token of the session is no longer the one of the form (e.g. the session has expired)
  await page.locator('#edit input[name="csrf_token"]').evaluate((input) => { input.value = 'outdated'; });
  await page.fill('#text', '# After');
  await expect(status).toContainText('No preview:');
  await expect(status).toContainText('security token');
  // (the last preview stays below the message)
  await expect(page.locator('#preview h1')).toHaveText('Before');
  await expect(page.locator('#text')).toHaveValue('# After');

  // not logged in (any more)
  await page.route(previewRequests, (route) => route.fulfill({
    status: 403, contentType: 'application/json',
    body: JSON.stringify({ error: 'not logged in', message: 'You are not logged in (any more).' }),
  }));
  await page.fill('#text', '# Logged out');
  await expect(status).toContainText('You are not logged in');
  await page.unroute(previewRequests);

  // an answer which is not JSON (like the page of a web server which refused the request), and no connection
  await page.route(previewRequests, (route) => route.fulfill({ status: 500, contentType: 'text/html', body: '<p>Error</p>' }));
  await page.fill('#text', '# Broken');
  await expect(status).toContainText('The server did not answer as expected.');
  await page.unroute(previewRequests);
  await page.route(previewRequests, (route) => route.abort('internetdisconnected'));
  await page.fill('#text', '# Offline');
  await expect(status).toContainText('The server cannot be reached.');
  await page.unroute(previewRequests);
  await expect(page.locator('#text')).toHaveValue('# Offline');

  // (once the server answers again, the message goes away; the form gets a valid token by saving, which fails)
  await page.fill('#title', 'Preview errors');
  await page.click('#save');
  await expect(page.locator('.note').first()).toContainText('security token');
  await expect(page.locator('#text')).toHaveValue('# Offline');
  await expect(page.locator('#preview h1')).toHaveText('Offline');
  await expect(status).toBeHidden();
});

test('an applied draft is previewed, until the saved text is back', async ({ page, request }) => {
  await savePage(request, 'Preview draft', 'saved text');
  await page.goto('/index.php?action=edit&page=' + encodeURIComponent('Preview draft'));
  await page.fill('#text', 'draft text');
  await openPreview(page);
  await expect(page.locator('#preview .previewcontent p')).toHaveText('draft text');
  await page.reload();
  // (the draft is only offered: the preview shows what the editor shows)
  await expect(page.locator('#preview .previewcontent p')).toHaveText('saved text');
  await page.click('.main .note input[value="Apply draft"]');
  await expect(page.locator('#text')).toHaveValue('draft text');
  await expect(page.locator('#preview .previewcontent p')).toHaveText('draft text');
});

test('dark color scheme: the preview has the colors of the page', async ({ browser }) => {
  const context = await browser.newContext({ colorScheme: 'dark' });
  const page = await context.newPage();
  await page.goto('/index.php?action=new');
  await openPreview(page);
  await page.fill('#text', 'Text with a [[Missing page]] and `code`.');
  await expect(page.locator('#preview a.noexist')).toBeVisible();
  const colors = await page.evaluate(() => {
    const style = (selector) => getComputedStyle(document.querySelector(selector));
    return { background: style('#preview').backgroundColor, text: style('#preview .previewcontent p').color, body: style('body').backgroundColor, missing: style('#preview a.noexist').color };
  });
  expect(colors).toEqual({ background: colors.body, text: 'rgb(228, 228, 228)', body: 'rgb(28, 28, 30)', missing: 'rgb(255, 123, 123)' });
  await context.close();
});

test.describe('scripts in the text do not run in the preview', () => {
  Object.entries(markdown).forEach(([name, text]) => {
    test(name, async ({ page }) => {
      const dialogs = watchDialogs(page);
      const problems = watchProblems(page);
      await page.goto('/index.php?action=new');
      await openPreview(page);
      await page.fill('#text', '# Payload\n\n' + text);
      await expect(page.locator('#preview h1').first()).toHaveText('Payload');
      // (everything a visitor could do with the elements of the preview)
      await page.evaluate(() => {
        const events = ['mouseover', 'mouseenter', 'mousemove', 'mousedown', 'mouseup', 'focus', 'focusin', 'load', 'error', 'toggle', 'animationstart'];
        for (const element of document.querySelectorAll('#preview *')) {
          for (const type of events) {
            try { element.dispatchEvent(new Event(type, { bubbles: true })); } catch (e) { /* ignore */ }
          }
        }
      });
      for (const link of await page.locator('#preview a[href]').all()) {
        const href = (await link.getAttribute('href')).replace(/[\u0000- ]/g, '').toLowerCase();
        if (href.startsWith('javascript:') || href.startsWith('data:') || href.startsWith('vbscript:')) {
          await link.click({ noWaitAfter: true }).catch(() => {});
        }
      }
      await page.hover('#preview');
      await page.waitForTimeout(300);
      expect(dialogs).toEqual([]);
      const active = await page.evaluate(() => [...document.querySelectorAll('#preview *')].flatMap((element) => [
        ...(element.localName === 'script' ? ['<script>'] : []),
        ...[...element.attributes].filter((a) => a.name.toLowerCase().startsWith('on')).map((a) => `${a.name} on <${element.localName}>`),
        ...[...element.attributes].filter((a) => /^\s*(javascript|data|vbscript):/i.test(a.value)).map((a) => `${a.name}=${a.value}`),
      ]));
      expect(active).toEqual([]);
      expect(problems).toEqual([]);
      expect(page.url()).toContain('action=new');
    });
  });
});
