// SPDX-License-Identifier: MIT

// Inserting Markdown and text files into the text of the editor: with the button below the text, or by dropping them
const { test, expect } = require('@playwright/test');
const { savePage, gif } = require('./helpers');

/** A file for the file input of the page */
const textFile = (name, text) => ({ name, mimeType: 'text/markdown', buffer: Buffer.from(text, 'utf8') });

/** Drops files (name, text or base64 bytes, type) on the text of the editor */
async function drop(page, files) {
  await page.evaluate((list) => {
    const transfer = new DataTransfer();
    for (const file of list) {
      const content = file.base64 ? Uint8Array.from(atob(file.base64), (c) => c.charCodeAt(0)) : file.text;
      transfer.items.add(new File([content], file.name, { type: file.type }));
    }
    document.getElementById('text').dispatchEvent(new DragEvent('drop', { dataTransfer: transfer, bubbles: true, cancelable: true }));
  }, files);
}

test('new page: the chosen file fills the text and the title, and is saved like typed text', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  const dialogs = [];
  page.on('dialog', async (dialog) => { dialogs.push(dialog.type()); await dialog.dismiss(); });
  await page.goto('/index.php?action=new');
  await page.setInputFiles('#importfile', textFile('Imported notes.md', '﻿# Heading\r\n\r\nSome *imported* text\r\n'));
  await expect(page.locator('#text')).toHaveValue('# Heading\n\nSome *imported* text\n');
  await expect(page.locator('#title')).toHaveValue('Imported notes');
  await page.click('#save');
  await page.waitForURL(/Imported%20notes$/);
  await expect(page.locator('.main h1')).toHaveText('Heading');
  await expect(page.locator('.main em')).toHaveText('imported');
  expect(dialogs).toEqual([]);
  expect(errors).toEqual([]);
});

test('the button opens the file chooser', async ({ page }) => {
  await page.goto('/index.php?action=new');
  const chooser = page.waitForEvent('filechooser');
  await page.click('#importbutton');
  const fileChooser = await chooser;
  expect(fileChooser.isMultiple()).toBe(true);
  await fileChooser.setFiles(textFile('Chosen.txt', 'chosen text'));
  await expect(page.locator('#text')).toHaveValue('chosen text');
});

test('existing page: the text of the file is inserted at the cursor, the page keeps its name', async ({ page, request }) => {
  await savePage(request, 'Page with imported part', 'before after');
  await page.goto('/index.php?action=edit&page=' + encodeURIComponent('Page with imported part'));
  await page.evaluate(() => document.getElementById('text').setSelectionRange(7, 7));
  await page.setInputFiles('#importfile', textFile('part.md', 'inserted '));
  await expect(page.locator('#text')).toHaveValue('before inserted after');
  // (an inserted file is a change, which can be undone)
  await page.keyboard.press('Control+z');
  await expect(page.locator('#text')).toHaveValue('before after');
});

test('new page with a title: the title is kept, and several files are inserted one after the other', async ({ page }) => {
  await page.goto('/index.php/' + encodeURIComponent('Page named before'));
  await page.setInputFiles('#importfile', [textFile('one.md', 'first file'), textFile('two.txt', 'second file')]);
  await expect(page.locator('#text')).toHaveValue('first file\n\nsecond file');
  await expect(page.locator('#title')).toHaveValue('Page named before');
});

test('the inserted text is a change: leaving the page asks for confirmation', async ({ page }) => {
  const dialogs = [];
  page.on('dialog', async (dialog) => { dialogs.push(dialog.type()); await dialog.dismiss(); });
  await page.goto('/index.php?action=edit&page=Home');
  await page.setInputFiles('#importfile', textFile('more.md', 'more text'));
  await expect(page.locator('#text')).toHaveValue(/more text/);
  await page.click('a[href*="action=upload"]', { noWaitAfter: true });
  await page.waitForTimeout(500);
  expect(dialogs).toEqual(['beforeunload']);
});

test('drop: a Markdown file is inserted, an image still opens the upload dialog', async ({ page }) => {
  await page.goto('/index.php?action=new');
  await drop(page, [{ name: 'Dropped page.markdown', text: 'dropped text', type: '' }]);
  await expect(page.locator('#text')).toHaveValue('dropped text');
  await expect(page.locator('#title')).toHaveValue('Dropped page');
  await expect(page.locator('#uploaddialog')).toBeHidden();
  await drop(page, [{ name: 'notes.txt', text: ' and notes', type: 'text/plain' }, { name: 'picture.gif', base64: gif().toString('base64'), type: 'image/gif' }]);
  await expect(page.locator('#text')).toHaveValue('dropped text and notes');
  await expect(page.locator('#uploaddialog')).toBeVisible();
  await expect(page.locator('#uploadname')).toHaveText('picture.gif');
});

test('files which are too large or no text files are refused with a message', async ({ page }) => {
  const messages = [];
  page.on('dialog', async (dialog) => { messages.push(dialog.message()); await dialog.dismiss(); });
  await page.goto('/index.php?action=new');
  await page.setInputFiles('#importfile', [
    textFile('huge.md', 'x'.repeat(2 * 1024 * 1024 + 1)),
    { name: 'picture.gif', mimeType: 'image/gif', buffer: gif() },
    { name: 'binary.txt', mimeType: 'text/plain', buffer: Buffer.from([0x41, 0x00, 0x42]) },
  ]);
  await expect.poll(() => messages.length).toBe(3);
  expect(messages[0]).toContain('huge.md');
  expect(messages[0]).toContain('2 MB');
  expect(messages[1]).toContain('picture.gif');
  expect(messages[2]).toContain('binary.txt');
  await expect(page.locator('#text')).toHaveValue('');
  await expect(page.locator('#title')).toHaveValue('');
});

test('scripts in an inserted file do not run, in the editor or on the saved page', async ({ page }) => {
  const dialogs = [];
  page.on('dialog', async (dialog) => { dialogs.push(dialog.message()); await dialog.dismiss(); });
  await page.goto('/index.php?action=new');
  const payload = '<script>alert("script")</script>\n<img src=x onerror="alert(\'img\')">\n[link](javascript:alert("link"))\n';
  await page.setInputFiles('#importfile', textFile('<img src=x onerror=alert(1)>.md', payload));
  await expect(page.locator('#text')).toHaveValue(payload);
  await page.fill('#title', 'Imported scripts');
  await page.click('#save');
  await page.waitForURL(/Imported%20scripts$/);
  await page.click('.main a', { noWaitAfter: true }).catch(() => {});
  await page.waitForTimeout(300);
  expect(dialogs).toEqual([]);
});
