// SPDX-License-Identifier: MIT

// Dropping files and images on the editor and the upload page (setupDrops() in wiki.js): files dropped on the text go
// where they are dropped, files dropped elsewhere in the editor to the cursor (never opened by the browser instead of
// the page), the hint while files are dragged over the page, images dragged from pages (the wiki's own uploads, other
// sites), drags of text which are left alone, undoing, the preview and the draft, the dark scheme and the
// Content-Security-Policy. The drags are made of DataTransfer objects, as a browser would send them.
const { test, expect } = require('@playwright/test');
const { gif, upload, expectReadable } = require('./helpers');

/** Script errors, and the messages about the Content-Security-Policy, which must not happen */
function watchProblems(page) {
  const problems = [];
  page.on('pageerror', (error) => problems.push(error.message));
  page.on('console', (message) => { if (message.type() === 'error' || /content security policy|refused to/i.test(message.text())) problems.push(message.text()); });
  page.on('dialog', (dialog) => { problems.push('dialog: ' + dialog.message()); dialog.dismiss().catch(() => {}); });
  return problems;
}

const unique = (name) => name.replace(/\.(\w+)$/, `-${Date.now()}-${Math.floor(Math.random() * 1000)}.$1`);
const gifFile = (name) => ({ name, base64: gif().toString('base64'), type: 'image/gif' });
const textFile = (name, text) => ({ name, text, type: 'text/markdown' });

/**
 * Sends a drag event (dragenter, dragover, dragleave, drop, dragstart, dragend) with files and data: to the element at
 * the point (in the window), or to the element of the selector. Returns whether the page took it (preventDefault()).
 */
async function drag(page, type, { files = [], data = {}, point = null, target = null } = {}) {
  return page.evaluate(({ type, files, data, point, target }) => {
    const transfer = new DataTransfer();
    for (const file of files) {
      const content = file.base64 ? Uint8Array.from(atob(file.base64), (c) => c.charCodeAt(0)) : file.text;
      transfer.items.add(new File([content], file.name, { type: file.type }));
    }
    for (const [format, value] of Object.entries(data)) transfer.setData(format, value);
    const element = target ? (target === 'document' ? document : document.querySelector(target)) : document.elementFromPoint(point.x, point.y);
    const event = new DragEvent(type, {
      dataTransfer: transfer, bubbles: true, cancelable: true, clientX: point ? point.x : 0, clientY: point ? point.y : 0,
    });
    element.dispatchEvent(event);
    return event.defaultPrevented;
  }, { type, files, data, point, target });
}

/** Drags over the point and drops there, like a browser: dragenter, dragover, drop */
async function dropAt(page, point, transfer) {
  await drag(page, 'dragenter', { ...transfer, point });
  const over = await drag(page, 'dragover', { ...transfer, point });
  const dropped = await drag(page, 'drop', { ...transfer, point });
  return { over, dropped };
}

/** A point in the text area, as fractions of its size */
async function textPoint(page, fx, fy) {
  const box = await page.locator('#text').boundingBox();
  return { x: Math.round(box.x + box.width * fx), y: Math.round(box.y + box.height * fy) };
}

/** The middle of an element */
async function middle(page, selector) {
  const box = await page.locator(selector).boundingBox();
  return { x: Math.round(box.x + box.width / 2), y: Math.round(box.y + box.height / 2) };
}

/** Where a click at the point puts the cursor of the text area: where the browser itself places it */
async function clickedOffset(page, point) {
  await page.mouse.click(point.x, point.y);
  return page.locator('#text').evaluate((t) => t.selectionStart);
}

async function setCursor(page, offset) {
  await page.locator('#text').evaluate((t, offset) => { t.focus(); t.setSelectionRange(offset, offset); }, offset);
}

const lines = Array.from({ length: 80 }, (_, i) => `line ${String(i + 1).padStart(2, '0')} with a few words in it`).join('\n');

test.describe('the position of the drop in the text', () => {
  for (const [name, fx, fy, scroll] of [
    ['the start', 0.001, 0.002, 0],
    ['a line in the middle', 0.03, 0.4, 0],
    ['the end of a line', 0.6, 0.25, 0],
    ['after scrolling the text', 0.02, 0.5, 600],
    ['a line in the middle, in a browser without caretPositionFromPoint()', 0.1, 0.6, 300],
  ]) {
    test(`a file dropped at ${name} goes where the browser would put the cursor`, async ({ page }) => {
      const problems = watchProblems(page);
      if (name.includes('without')) {
        // (like Chrome before version 128 and Safari, which have caretRangeFromPoint() instead)
        await page.addInitScript(() => { delete Document.prototype.caretPositionFromPoint; });
      }
      await page.goto('/index.php/' + encodeURIComponent('Drop position page'));
      await page.fill('#text', lines);
      expect(await page.evaluate(() => [typeof document.caretPositionFromPoint, typeof document.caretRangeFromPoint]))
        .toEqual(name.includes('without') ? ['undefined', 'function'] : [expect.any(String), expect.any(String)]);
      await page.locator('#text').evaluate((t, scroll) => { t.scrollTop = scroll; }, scroll);
      const point = await textPoint(page, fx, fy);
      const expected = await clickedOffset(page, point);
      if (name !== 'the start') expect(expected, 'a point within the text').toBeGreaterThan(0);
      expect(expected).toBeLessThan(lines.length);
      // (the cursor somewhere else: the drop decides)
      await setCursor(page, lines.length);
      await page.locator('#text').evaluate((t, scroll) => { t.scrollTop = scroll; }, scroll);
      const { over, dropped } = await dropAt(page, point, { files: [textFile('dropped.md', 'DROPPED')] });
      expect(over).toBe(true);
      expect(dropped).toBe(true);
      await expect(page.locator('#text')).toHaveValue(lines.slice(0, expected) + 'DROPPED' + lines.slice(expected));
      expect(problems).toEqual([]);
    });
  }

  test('a file dropped below the last line goes at the end', async ({ page }) => {
    await page.goto('/index.php/' + encodeURIComponent('Drop position page'));
    await page.fill('#text', 'short\ntext');
    await setCursor(page, 0);
    await dropAt(page, await textPoint(page, 0.5, 0.8), { files: [textFile('end.md', '!')] });
    await expect(page.locator('#text')).toHaveValue('short\ntext!');
  });

  test('while files are dragged over the text, the cursor follows them, and goes back if they are not dropped', async ({ page }) => {
    await page.goto('/index.php/' + encodeURIComponent('Drop position page'));
    await page.fill('#text', lines);
    await page.locator('#text').evaluate((t) => { t.focus(); t.setSelectionRange(3, 7); t.scrollTop = 0; });
    const point = await textPoint(page, 0.03, 0.3);
    const files = [gifFile('caret.gif')];
    await drag(page, 'dragenter', { files, point });
    expect(await drag(page, 'dragover', { files, point })).toBe(true);
    await expect(page.locator('.editorbox')).toHaveClass(/dropping/);
    const during = await page.locator('#text').evaluate((t) => [t.selectionStart, t.selectionEnd, document.activeElement === t]);
    expect(during[0]).toBeGreaterThan(7);
    expect(during[0]).toBe(during[1]);
    expect(during[2]).toBe(true);
    // (over the toolbar: back to the selection, where a drop there would go)
    await drag(page, 'dragover', { files, point: await middle(page, '#formatbar') });
    await expect(page.locator('.editorbox')).not.toHaveClass(/dropping/);
    expect(await page.locator('#text').evaluate((t) => [t.selectionStart, t.selectionEnd])).toEqual([3, 7]);
    // (back over the text, and the drag leaves the window)
    await drag(page, 'dragover', { files, point });
    await drag(page, 'dragleave', { files, point });
    await expect(page.locator('.dropoverlay')).toBeHidden();
    expect(await page.locator('#text').evaluate((t) => [t.selectionStart, t.selectionEnd])).toEqual([3, 7]);
    await expect(page.locator('#text')).toHaveValue(lines);
  });

  test('several files are uploaded in their order, and their links go where they were dropped', async ({ page }) => {
    const problems = watchProblems(page);
    await page.goto('/index.php/' + encodeURIComponent('Drop several page'));
    await page.fill('#text', lines);
    await page.locator('#text').evaluate((t) => { t.scrollTop = 0; });
    const point = await textPoint(page, 0.002, 0.2);
    const expected = await clickedOffset(page, point);
    await setCursor(page, 0);
    const [first, second] = [unique('first.gif'), unique('second.gif')];
    await dropAt(page, point, { files: [gifFile(first), gifFile(second)] });
    await expect(page.locator('#uploaddialog')).toBeVisible();
    await expect(page.locator('#uploadname')).toHaveText(first);
    await page.click('#uploadstart');
    await expect(page.locator('#uploadname')).toHaveText(second);
    await page.click('#uploadstart');
    await expect(page.locator('#uploaddialog')).toBeHidden();
    const links = `![Image Description](/images/${first})\n![Image Description](/images/${second})`;
    await expect(page.locator('#text')).toHaveValue(lines.slice(0, expected) + links + lines.slice(expected));
    // (one step to undo)
    await page.keyboard.press('Control+z');
    await expect(page.locator('#text')).toHaveValue(lines);
    expect(problems).toEqual([]);
  });
});

test.describe('the whole editor takes files', () => {
  for (const [name, selector] of [['the preview', '#preview'], ['the toolbar', '#formatbar'], ['the buttons below the text', '.editactions'], ['the top of the window', null]]) {
    test(`a file dropped on ${name} goes to the cursor, and is not opened by the browser`, async ({ page }) => {
      const problems = watchProblems(page);
      await page.goto('/index.php/' + encodeURIComponent('Drop elsewhere page'));
      await page.fill('#text', 'one two');
      await setCursor(page, 3);
      if (selector === '#preview') {
        await page.click('#previewbutton');
        await expect(page.locator('#preview')).toBeVisible();
      }
      const url = page.url();
      const point = selector ? await middle(page, selector) : { x: 3, y: 3 };
      const { over, dropped } = await dropAt(page, point, { files: [textFile('elsewhere.md', '[X]')] });
      expect(over, 'the browser is told that the page takes the file').toBe(true);
      expect(dropped, 'the browser does not open the file').toBe(true);
      await expect(page.locator('#text')).toHaveValue('one[X] two');
      expect(page.url()).toBe(url);
      expect(problems).toEqual([]);
    });
  }

  test('a file dropped next to a text which never had the focus goes at its end', async ({ page, request }) => {
    const name = 'Drop never focused ' + Date.now();
    const token = /name="csrf_token" value="([^"]+)"/.exec(await (await request.get('/index.php?action=new')).text())[1];
    await request.post('/index.php', { form: { action: 'save', isNew: 'true', page: name, newText: 'saved text', gitmsg: '', csrf_token: token }, maxRedirects: 0 });
    await page.goto('/index.php?action=edit&page=' + encodeURIComponent(name));
    await expect(page.locator('#text')).toHaveValue('saved text');
    await dropAt(page, await middle(page, '#formatbar'), { files: [textFile('end.md', '!')] });
    await expect(page.locator('#text')).toHaveValue('saved text!');
  });

  test('an image file dropped on the toolbar opens the dialog, and its link goes to the cursor', async ({ page }) => {
    await page.goto('/index.php/' + encodeURIComponent('Drop elsewhere page'));
    await page.fill('#text', 'one two');
    await setCursor(page, 4);
    const name = unique('toolbar.gif');
    await dropAt(page, await middle(page, '#formatbar'), { files: [gifFile(name)] });
    await expect(page.locator('#uploadname')).toHaveText(name);
    await page.click('#uploadstart');
    await expect(page.locator('#text')).toHaveValue(`one ![Image Description](/images/${name})two`);
  });
});

test.describe('the hint while files are dragged over the page', () => {
  test('it is shown while files are over the page, and goes when they leave, are dropped or the drag ends', async ({ page }) => {
    const problems = watchProblems(page);
    await page.goto('/index.php?action=new');
    const overlay = page.locator('.dropoverlay');
    await expect(overlay).toBeHidden();
    await expect(overlay).toHaveAttribute('aria-hidden', 'true');
    const files = [gifFile('hint.gif')];
    const bar = await middle(page, '#formatbar');
    const text = await textPoint(page, 0.5, 0.5);
    // (enter the toolbar, then the text, then leave the toolbar: still over the page)
    await drag(page, 'dragenter', { files, point: bar });
    await expect(overlay).toBeVisible();
    await expect(overlay).toHaveText('Drop files to upload them and insert links');
    await drag(page, 'dragenter', { files, point: text });
    await drag(page, 'dragleave', { files, point: bar });
    await expect(overlay).toBeVisible();
    await drag(page, 'dragleave', { files, point: text });
    await expect(overlay).toBeHidden();
    // (Esc, and the end of the drag)
    await drag(page, 'dragenter', { files, point: bar });
    await expect(overlay).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(overlay).toBeHidden();
    await drag(page, 'dragenter', { files, point: bar });
    await expect(overlay).toBeVisible();
    await drag(page, 'dragend', { files, target: 'document' });
    await expect(overlay).toBeHidden();
    // (a drop)
    await drag(page, 'dragenter', { files: [textFile('hint.md', 'hint')], point: text });
    await expect(overlay).toBeVisible();
    await drag(page, 'drop', { files: [textFile('hint.md', 'hint')], point: text });
    await expect(overlay).toBeHidden();
    await expect(page.locator('#text')).toHaveValue('hint');
    // (the hint lets the mouse through)
    expect(await overlay.evaluate((e) => getComputedStyle(e).pointerEvents)).toBe('none');
    expect(problems).toEqual([]);
  });

  test('it is not shown for text, links and drags within the page', async ({ page }) => {
    await page.goto('/index.php?action=new');
    const overlay = page.locator('.dropoverlay');
    await drag(page, 'dragenter', { data: { 'text/plain': 'some text' }, point: await textPoint(page, 0.5, 0.5) });
    await drag(page, 'dragenter', { data: { 'text/uri-list': 'https://example.com/', 'text/html': '<a href="https://example.com/">Example</a>' }, point: await middle(page, '#formatbar') });
    await expect(overlay).toBeHidden();
    await drag(page, 'dragstart', { target: '#formatbar' });
    await drag(page, 'dragenter', { files: [gifFile('inner.gif')], point: await middle(page, '#formatbar') });
    await expect(overlay).toBeHidden();
  });

  test.describe('dark color scheme', () => {
    test.use({ colorScheme: 'dark' });

    test('the hint is readable', async ({ page }) => {
      const problems = watchProblems(page);
      await page.goto('/index.php?action=new');
      await drag(page, 'dragenter', { files: [gifFile('dark.gif')], point: await middle(page, '#formatbar') });
      await expect(page.locator('.dropoverlay')).toBeVisible();
      await expectReadable(page, ['.dropoverlay p']);
      const background = await page.locator('.dropoverlay p').evaluate((e) => getComputedStyle(e).backgroundColor);
      expect(background).not.toBe('rgb(255, 255, 255)');
      expect(problems).toEqual([]);
    });
  });

  test('the hint is readable in the light scheme', async ({ page }) => {
    await page.goto('/index.php?action=upload');
    await drag(page, 'dragenter', { files: [gifFile('light.gif')], point: { x: 10, y: 10 } });
    await expect(page.locator('.dropoverlay')).toBeVisible();
    await expectReadable(page, ['.dropoverlay p']);
  });
});

test.describe('drags of text are left alone', () => {
  test('text dragged within the text area, and links dropped on it, are inserted by the browser', async ({ page }) => {
    const problems = watchProblems(page);
    await page.goto('/index.php?action=new');
    await page.fill('#text', 'move these words');
    const point = await textPoint(page, 0.5, 0.5);
    await drag(page, 'dragstart', { data: { 'text/plain': 'these' }, target: '#text' });
    expect(await drag(page, 'dragover', { data: { 'text/plain': 'these' }, point })).toBe(false);
    expect(await drag(page, 'drop', { data: { 'text/plain': 'these' }, point })).toBe(false);
    await drag(page, 'dragend', { data: { 'text/plain': 'these' }, target: '#text' });
    const link = { 'text/uri-list': 'https://example.com/page', 'text/html': '<a href="https://example.com/page">a page</a>', 'text/plain': 'https://example.com/page' };
    expect(await drag(page, 'dragover', { data: link, point })).toBe(false);
    expect(await drag(page, 'drop', { data: link, point })).toBe(false);
    // (an address of no image, without HTML)
    expect(await drag(page, 'drop', { data: { 'text/uri-list': 'https://example.com/document.pdf' }, point })).toBe(false);
    // (a selection of a page with text and an image)
    expect(await drag(page, 'drop', { data: { 'text/html': '<p>Some text <img src="https://example.com/a.png"></p>' }, point })).toBe(false);
    await expect(page.locator('#text')).toHaveValue('move these words');
    await expect(page.locator('.dropoverlay')).toBeHidden();
    expect(problems).toEqual([]);
  });

  test('selected text dragged with the mouse within the text area is moved by the browser', async ({ page }) => {
    const problems = watchProblems(page);
    await page.goto('/index.php?action=new');
    await page.fill('#text', 'aaaa bbbb cccc');
    await page.locator('#text').evaluate((t) => { t.focus(); t.setSelectionRange(0, 5); t.scrollTop = 0; });
    // (from the selected "aaaa " to the end of the line)
    const start = await textPoint(page, 0, 0);
    const box = await page.locator('#text').boundingBox();
    await page.mouse.move(start.x + 8, start.y + 8);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width * 0.5, start.y + 8, { steps: 10 });
    await expect(page.locator('.dropoverlay')).toBeHidden();
    await page.mouse.up();
    await expect(page.locator('#text')).toHaveValue('bbbb ccccaaaa ');
    expect(problems).toEqual([]);
  });

  test('text dropped on the title is inserted by the browser', async ({ page }) => {
    await page.goto('/index.php?action=new');
    expect(await drag(page, 'drop', { data: { 'text/plain': 'title' }, target: '#title' })).toBe(false);
    expect(await drag(page, 'dragover', { data: { 'text/uri-list': 'https://example.com/' }, target: '#title' })).toBe(false);
  });
});

test.describe('images dragged from pages', () => {
  test('an image of the upload page becomes the link of the upload, where it is dropped', async ({ page, request }) => {
    const problems = watchProblems(page);
    const name = unique('listed.gif');
    expect((await upload(request, name, gif(), 'image/gif')).status()).toBe(303);
    await page.goto('/index.php?action=upload');
    const thumbnail = page.locator('tr', { has: page.locator('.uploadFileName', { hasText: name }) }).locator('img.thumbImg');
    // (what a browser puts into the drag of the image: its address, and the image as HTML; Chrome also the file)
    const source = await thumbnail.evaluate((img) => img.src);
    expect(source).toContain('/file.php?name=' + encodeURIComponent(name));
    await page.goto('/index.php/' + encodeURIComponent('Drop image page'));
    await page.fill('#text', 'before\nafter');
    await page.locator('#text').evaluate((t) => { t.scrollTop = 0; });
    const point = await textPoint(page, 0.001, 0.002);
    await setCursor(page, 12);
    const { dropped } = await dropAt(page, point, {
      data: { 'text/uri-list': source, 'text/html': `<meta charset="utf-8"><img src="${source}" alt="">` },
      files: [gifFile(name)],
    });
    expect(dropped).toBe(true);
    await expect(page.locator('#text')).toHaveValue(`![Image Description](/images/${name})before\nafter`);
    await expect(page.locator('#uploaddialog')).toBeHidden();
    // (only the address, as from the address of a file of the uploads)
    await setCursor(page, 0);
    await page.locator('#text').evaluate((t) => t.setSelectionRange(t.value.length, t.value.length));
    await drag(page, 'drop', { data: { 'text/uri-list': `${new URL(page.url()).origin}/images/${name}` }, target: '#text' });
    await expect(page.locator('#text')).toHaveValue(`![Image Description](/images/${name})before\nafter![Image Description](/images/${name})`);
    expect(problems).toEqual([]);
  });

  test('an image of the preview dragged into the text with the mouse becomes the link of the upload, where it is dropped', async ({ page, request }) => {
    const problems = watchProblems(page);
    const name = unique('preview.gif');
    expect((await upload(request, name, gif(), 'image/gif')).status()).toBe(303);
    await page.goto('/index.php/' + encodeURIComponent('Drop image page'));
    const text = `![](/images/${name})\n\n${lines}`;
    await page.fill('#text', text);
    await page.click('#previewbutton');
    const image = page.locator('#preview img');
    await expect(image).toBeVisible();
    // (large enough to grab)
    await image.evaluate((img) => { img.width = 40; img.height = 40; });
    await page.locator('#text').evaluate((t) => { t.scrollTop = 0; });
    const box = await page.locator('#text').boundingBox();
    const target = { x: 60, y: Math.round(box.height / 2) };
    const lineEnd = await clickedOffset(page, { x: box.x + target.x, y: box.y + target.y });
    expect(lineEnd).toBeGreaterThan(0);
    expect(lineEnd).toBeLessThan(text.length);
    await setCursor(page, 0);
    await page.locator('#text').evaluate((t) => { t.scrollTop = 0; });
    // (a real drag of the browser: within the page, with the address and the HTML of the image)
    await image.dragTo(page.locator('#text'), { targetPosition: target });
    await expect(page.locator('#text')).toHaveValue(text.slice(0, lineEnd) + `![Image Description](/images/${name})` + text.slice(lineEnd));
    await expect(page.locator('.dropoverlay')).toBeHidden();
    expect(problems).toEqual([]);
  });

  test('an image of another site becomes ![alt](address), also next to the text, and updates the preview and the draft', async ({ page }) => {
    const problems = watchProblems(page);
    // (the images of the other sites, for the preview)
    await page.route(/^https?:\/\/([a-z]+\.)?example\.com\//, (route) => route.fulfill({ body: gif(), contentType: 'image/gif' }));
    await page.goto('/index.php/' + encodeURIComponent('Drop external page'));
    await page.click('#previewbutton');
    await page.fill('#text', 'text');
    await setCursor(page, 4);
    const html = '<img src="https://images.example.com/photos/sun (1).png" alt="A [bright]\n sun">';
    const { dropped } = await dropAt(page, await textPoint(page, 0.5, 0.5), { data: { 'text/html': html, 'text/uri-list': 'https://images.example.com/photos/sun%20(1).png' } });
    expect(dropped).toBe(true);
    const markdown = '![A \\[bright\\] sun](https://images.example.com/photos/sun%20%281%29.png)';
    await expect(page.locator('#text')).toHaveValue('text' + markdown);
    await expect(page.locator('#preview img')).toHaveAttribute('src', 'https://images.example.com/photos/sun%20%281%29.png');
    const key = await page.locator('#edit').getAttribute('data-draft-key');
    await expect.poll(() => page.evaluate((key) => localStorage.getItem(key) || '', key)).toContain('images.example.com');
    // (an address of an image file, without HTML, dropped on the toolbar: at the cursor)
    await setCursor(page, 0);
    expect((await dropAt(page, await middle(page, '#formatbar'), { data: { 'text/uri-list': '# comment\r\nhttp://example.com/a.JPG?size=2' } })).dropped).toBe(true);
    await expect(page.locator('#text')).toHaveValue('![](http://example.com/a.JPG?size=2)text' + markdown);
    // (one step to undo each)
    await page.locator('#text').focus();
    await page.keyboard.press('Control+z');
    await expect(page.locator('#text')).toHaveValue('text' + markdown);
    await page.keyboard.press('Control+z');
    await expect(page.locator('#text')).toHaveValue('text');
    expect(problems).toEqual([]);
  });

  test('javascript: and data: addresses are not inserted', async ({ page }) => {
    const problems = watchProblems(page);
    await page.goto('/index.php?action=new');
    await page.fill('#text', 'safe');
    const point = await textPoint(page, 0.5, 0.5);
    for (const data of [
      { 'text/html': '<img src="javascript:alert(1)">' },
      { 'text/html': '<img src=" JaVaScRiPt:alert(1)" alt="x">' },
      { 'text/uri-list': 'javascript:alert(1)//.png' },
      { 'text/html': `<img src="data:image/gif;base64,${gif().toString('base64')}">` },
      { 'text/uri-list': `data:image/gif;base64,${gif().toString('base64')}` },
      { 'text/html': '<img src="blob:https://example.com/0000">' },
      { 'text/html': '<img src="file:///etc/passwd.png">' },
    ]) {
      expect(await drag(page, 'drop', { data, point }), JSON.stringify(data)).toBe(false);
    }
    // (next to the text: nothing happens, and the browser does not open them)
    expect(await drag(page, 'drop', { data: { 'text/uri-list': 'javascript:alert(1)//.png' }, point: await middle(page, '#formatbar') })).toBe(true);
    await expect(page.locator('#text')).toHaveValue('safe');
    expect(problems).toEqual([]);
  });
});

test('upload page: files dropped anywhere open the dialog, and are listed after uploading', async ({ page }) => {
  const problems = watchProblems(page);
  await page.goto('/index.php?action=upload');
  const name = unique('dropped-on-list.gif');
  const point = { x: 200, y: 300 };
  await drag(page, 'dragenter', { files: [gifFile(name)], point });
  await expect(page.locator('.dropoverlay')).toHaveText('Drop files to upload them');
  const { over, dropped } = await dropAt(page, point, { files: [gifFile(name)] });
  expect(over).toBe(true);
  expect(dropped).toBe(true);
  await expect(page.locator('.dropoverlay')).toBeHidden();
  await expect(page.locator('#uploaddialog')).toBeVisible();
  await expect(page.locator('#uploadname')).toHaveText(name);
  await page.click('#uploadstart');
  await expect(page.locator('.uploadFileName', { hasText: name })).toHaveCount(1);
  expect(problems).toEqual([]);
});

test('files dropped on the open dialog to upload are chosen in it', async ({ page }) => {
  await page.goto('/index.php?action=new');
  await page.click('#uploadbutton');
  await expect(page.locator('#uploaddialog')).toBeVisible();
  // (no hint over the dialog)
  await drag(page, 'dragenter', { files: [gifFile('on-dialog.gif')], target: '#uploaddialog' });
  await expect(page.locator('.dropoverlay')).toBeHidden();
  expect(await drag(page, 'drop', { files: [gifFile('on-dialog.gif')], target: '#uploaddialog h5' })).toBe(true);
  await expect(page.locator('#uploadname')).toHaveText('on-dialog.gif');
  await page.click('#uploadcancel');
  await expect(page.locator('#uploaddialog')).toBeHidden();
  await expect(page.locator('#text')).toHaveValue('');
});
