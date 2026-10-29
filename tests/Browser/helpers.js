// SPDX-License-Identifier: MIT

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

/** A PNG of one color (an image the browser shows and the wiki can crop) */
function png(width, height, [red, green, blue] = [200, 30, 30]) {
  const zlib = require('zlib');
  const crcTable = Array.from({ length: 256 }, (_, n) => {
    let c = n;
    for (let k = 0; k < 8; k++) c = (c & 1) ? (0xedb88320 ^ (c >>> 1)) : (c >>> 1);
    return c >>> 0;
  });
  const crc = (buffer) => {
    let c = 0xffffffff;
    for (const byte of buffer) c = crcTable[(c ^ byte) & 0xff] ^ (c >>> 8);
    return (c ^ 0xffffffff) >>> 0;
  };
  const chunk = (type, data) => {
    const body = Buffer.concat([Buffer.from(type, 'ascii'), data]);
    const length = Buffer.alloc(4); length.writeUInt32BE(data.length);
    const check = Buffer.alloc(4); check.writeUInt32BE(crc(body));
    return Buffer.concat([length, body, check]);
  };
  const header = Buffer.alloc(13);
  header.writeUInt32BE(width, 0); header.writeUInt32BE(height, 4);
  header[8] = 8; header[9] = 2; // 8 bits, RGB
  const row = Buffer.concat([Buffer.from([0]), Buffer.from(Array.from({ length: width }, () => [red, green, blue]).flat())]);
  const rows = Buffer.concat(Array.from({ length: height }, () => row));
  return Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', header), chunk('IDAT', zlib.deflateSync(rows)), chunk('IEND', Buffer.alloc(0))]);
}

/** Width and height of a PNG file */
const pngSize = (buffer) => [buffer.readUInt32BE(16), buffer.readUInt32BE(20)];

module.exports = { watchDialogs, interact, activeContent, csrfToken, savePage, upload, gif, png, pngSize, svgBase };
