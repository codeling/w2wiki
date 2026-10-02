const { expect } = require('@playwright/test');

const svgBase = () => process.env.W2_SVG_URL || 'http://127.0.0.1:8091';

/** Remember every dialog (alert, confirm, prompt) the page opens; scripts of the tests use alert() */
function watchDialogs(page) {
  const dialogs = [];
  page.on('dialog', (dialog) => {
    dialogs.push(dialog.message());
    dialog.dismiss().catch(() => {});
  });
  return dialogs;
}

/**
 * Trigger everything a visitor could do with a page: hover, focus and click on all elements, and
 * follow javascript: links. Inline event handlers and script URLs run when this happens.
 */
async function interact(page) {
  await page.evaluate(() => {
    const events = ['mouseover', 'mouseenter', 'mousemove', 'mousedown', 'mouseup', 'focus', 'focusin', 'click', 'load', 'error', 'toggle', 'animationstart'];
    // (the wiki's own handlers, like the Cancel button of the editor, do what they are meant to do)
    const own = ['toggleDrawer(); return false;', 'history.go(-1);'];
    for (const element of document.querySelectorAll('*')) {
      if (own.includes(element.getAttribute('onclick'))) continue;
      for (const type of events) {
        try { element.dispatchEvent(new Event(type, { bubbles: true })); } catch (e) { /* ignore */ }
      }
    }
    for (const link of document.querySelectorAll('a[href], area[href]')) {
      const href = (link.getAttribute('href') || link.getAttribute('xlink:href') || '').replace(/[\u0000- ]/g, '').toLowerCase();
      if (href.startsWith('javascript:') || href.startsWith('data:') || href.startsWith('vbscript:')) {
        try { link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true })); } catch (e) { /* ignore */ }
      }
    }
  }).catch(() => { /* the page may have navigated away, which is fine */ });
  await page.mouse.move(5, 5);
  await page.mouse.move(400, 300, { steps: 10 });
  await page.waitForTimeout(300);
}

/** Names of event handler attributes and script elements in the document (there must be none in shown pages) */
async function activeContent(page) {
  return page.evaluate(() => {
    const found = [];
    for (const element of document.querySelectorAll('*')) {
      if (element.localName === 'script' && !element.getAttribute('src')) found.push('inline <script>');
      for (const attribute of element.attributes) {
        if (attribute.name.toLowerCase().startsWith('on')) found.push(`${attribute.name} on <${element.localName}>`);
      }
    }
    return found;
  });
}

async function csrfToken(request, base = '') {
  const response = await request.get(`${base}/index.php?action=new`);
  const match = /name="csrf_token" value="([^"]+)"/.exec(await response.text());
  // (no token in versions without CSRF protection, which lets these tests run against them, see tests/README.md)
  return match ? match[1] : '';
}

async function savePage(request, name, text, base = '') {
  const token = await csrfToken(request, base);
  const response = await request.post(`${base}/index.php`, {
    form: { action: 'save', isNew: 'true', page: name, newText: text, gitmsg: '', csrf_token: token },
    maxRedirects: 0,
  });
  expect(response.status(), `saving ${name}`).toBe(303);
}

async function upload(request, name, buffer, mimeType, base = '') {
  const token = await csrfToken(request, base);
  return request.post(`${base}/index.php`, {
    multipart: { action: 'uploaded', csrf_token: token, prevpage: 'Home', overwrite: 'true', userfile: { name, mimeType, buffer } },
    maxRedirects: 0,
  });
}

const gif = () => Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64');

module.exports = { watchDialogs, interact, activeContent, csrfToken, savePage, upload, gif, svgBase };
