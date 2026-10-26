// SPDX-License-Identifier: MIT

// Uploading files from the editor: the link is inserted at the cursor, the typed text stays
const { test, expect } = require('@playwright/test');
const { gif } = require('./helpers');

const upload = (name) => ({ name, mimeType: 'image/gif', buffer: gif() });

test('upload button: the link is inserted at the cursor and the typed text is kept', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/index.php/' + encodeURIComponent('Page with upload'));
  await page.fill('#text', 'before after');
  // (the cursor between the two words)
  await page.evaluate(() => { const t = document.getElementById('text'); t.focus(); t.setSelectionRange(7, 7); });
  await page.setInputFiles('#uploadfile', upload('editor-upload.gif'));
  await expect(page.locator('#uploadstatus')).toContainText('editor-upload.gif');
  await expect(page.locator('#text')).toHaveValue('before ![Image Description](/images/editor-upload.gif)after');
  expect(page.url()).toContain('Page%20with%20upload');
  expect(errors).toEqual([]);

  // the page was not saved by the upload, and it can be saved now
  await page.click('#save');
  await page.waitForURL(/Page%20with%20upload/);
  await expect(page.locator('img[src^="/images/editor-upload.gif"]')).toHaveCount(1);
});

test('upload: a space in the name is replaced, and the stored name is linked', async ({ page }) => {
  await page.goto('/index.php?action=new');
  await page.setInputFiles('#uploadfile', upload('my picture.gif'));
  await expect(page.locator('#text')).toHaveValue('![Image Description](/images/my_picture.gif)');
});

test('upload: a refused file shows the error and leaves the text alone', async ({ page }) => {
  await page.goto('/index.php?action=new');
  await page.fill('#text', 'typed text');
  await page.setInputFiles('#uploadfile', { name: 'script.php', mimeType: 'text/plain', buffer: Buffer.from('<?php echo 1;') });
  await expect(page.locator('#uploadstatus.error')).toContainText('invalid file type');
  await expect(page.locator('#text')).toHaveValue('typed text');
});

test('upload: an existing file is only replaced after confirmation', async ({ page }) => {
  await page.goto('/index.php?action=new');
  await page.setInputFiles('#uploadfile', upload('twice.gif'));
  await expect(page.locator('#text')).toHaveValue('![Image Description](/images/twice.gif)');
  const messages = [];
  page.on('dialog', async (dialog) => { messages.push(dialog.message()); await dialog.dismiss(); });
  await page.setInputFiles('#uploadfile', upload('twice.gif'));
  await expect.poll(() => messages.length).toBe(1);
  expect(messages[0]).toContain('twice.gif');
  await expect(page.locator('#text')).toHaveValue('![Image Description](/images/twice.gif)');
});

test('drop: files dropped on the text are uploaded', async ({ page }) => {
  await page.goto('/index.php?action=new');
  await page.fill('#text', 'x');
  await page.evaluate(async (base64) => {
    const bytes = Uint8Array.from(atob(base64), (c) => c.charCodeAt(0));
    const transfer = new DataTransfer();
    transfer.items.add(new File([bytes], 'dropped.gif', { type: 'image/gif' }));
    document.getElementById('text').dispatchEvent(new DragEvent('drop', { dataTransfer: transfer, bubbles: true, cancelable: true }));
  }, gif().toString('base64'));
  await expect(page.locator('#text')).toHaveValue(/!\[Image Description\]\(\/images\/dropped\.gif\)/);
});

test('paste: an image from the clipboard is uploaded under a new name', async ({ page }) => {
  await page.goto('/index.php?action=new');
  await page.click('#text');
  await page.evaluate(async (base64) => {
    const bytes = Uint8Array.from(atob(base64), (c) => c.charCodeAt(0));
    const transfer = new DataTransfer();
    transfer.items.add(new File([bytes], 'image.gif', { type: 'image/gif' }));
    document.getElementById('text').dispatchEvent(new ClipboardEvent('paste', { clipboardData: transfer, bubbles: true, cancelable: true }));
  }, gif().toString('base64'));
  await expect(page.locator('#text')).toHaveValue(/!\[Image Description\]\(\/images\/image-\d{14}\.gif\)/);
});

test('pasting text is not touched', async ({ page }) => {
  await page.goto('/index.php?action=new');
  await page.fill('#text', '');
  await page.evaluate(() => {
    const transfer = new DataTransfer();
    transfer.setData('text/plain', 'plain');
    const event = new ClipboardEvent('paste', { clipboardData: transfer, bubbles: true, cancelable: true });
    window.pasteWasHandled = !document.getElementById('text').dispatchEvent(event);
  });
  expect(await page.evaluate(() => window.pasteWasHandled)).toBe(false);
});
