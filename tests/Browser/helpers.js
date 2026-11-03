// SPDX-License-Identifier: MIT

const { expect } = require('@playwright/test');

const svgBase = () => process.env.W2_SVG_URL || 'http://127.0.0.1:8091';
// (the wiki which asks for a password: hunter2)
const loginBase = () => process.env.W2_LOGIN_URL || 'http://127.0.0.1:8092';

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

// --- contrast and colors of the dark and light color scheme (see dark-mode.spec.js, theme-switch.spec.js) ---

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

/**
 * Contrast of the text of the title bar and of the toolbar against both ends of their gradients (the
 * custom properties of the style sheet), which the check of expectReadable() can't see
 */
async function barContrasts(page) {
  return page.evaluate(() => {
    // (the custom properties hold light-dark(...), so the used color is read from an element which uses it)
    const probe = document.createElement('div');
    document.body.appendChild(probe);
    const color = (name) => {
      probe.style.backgroundColor = `var(${name})`;
      return getComputedStyle(probe).backgroundColor.match(/\d+/g).slice(0, 3).map(Number);
    };
    const luminance = (c) => {
      const [R, G, B] = c.map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; });
      return 0.2126 * R + 0.7152 * G + 0.0722 * B;
    };
    const ratio = (a, b) => { const [x, y] = [luminance(a), luminance(b)]; return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
    const result = {};
    for (const end of ['--title-top', '--title-bottom', '--toolbar-top', '--toolbar-bottom']) {
      result[`text on ${end}`] = ratio(color('--bar-fg'), color(end));
    }
    result['date on --title-top'] = ratio(color('--bar-fg-muted'), color('--title-top'));
    result['date on --title-bottom'] = ratio(color('--bar-fg-muted'), color('--title-bottom'));
    probe.remove();
    return result;
  });
}

/** The colors (red, green, blue) at both ends of the gradients of the bars: title bar top and bottom, toolbar top and bottom */
async function barColors(page) {
  return page.evaluate(() => {
    const probe = document.createElement('div');
    document.body.appendChild(probe);
    const colors = ['--title-top', '--title-bottom', '--toolbar-top', '--toolbar-bottom'].map((name) => {
      probe.style.backgroundColor = `var(${name})`;
      return getComputedStyle(probe).backgroundColor.match(/\d+/g).slice(0, 3).map(Number);
    });
    probe.remove();
    return colors;
  });
}

/** The brightness (0 to 1) of the background of the page */
async function pageBrightness(page) {
  return page.evaluate(() => {
    const m = /rgba?\((\d+), (\d+), (\d+)/.exec(getComputedStyle(document.body).backgroundColor);
    return (Number(m[1]) + Number(m[2]) + Number(m[3])) / (3 * 255);
  });
}

/** Color (red, green, blue) of the first element which the selector finds (icons are painted by their text color) */
async function iconColor(page, selector) {
  return page.locator(selector).first().evaluate((element) => /(\d+), (\d+), (\d+)/.exec(getComputedStyle(element).color).slice(1).map(Number));
}

module.exports = {contrasts, expectReadable, barContrasts, barColors, pageBrightness, iconColor,  watchDialogs, interact, activeContent, csrfToken, savePage, upload, gif, png, pngSize, svgBase, loginBase };
