// SPDX-License-Identifier: MIT

// Uploading files from the editor: the dialog of the symbol bar, cropping, and that the link is inserted at the cursor,
// the typed text stays
const { test, expect } = require('@playwright/test');
const { gif, png, pngSize } = require('./helpers');

const upload = (name) => ({ name, mimeType: 'image/gif', buffer: gif() });

/** Chooses the file in the dialog of the symbol bar, and uploads it (the dialog closes when the link is inserted) */
async function uploadFromDialog(page, file) {
  await page.click('#uploadbutton');
  await expect(page.locator('#uploaddialog')).toBeVisible();
  await page.setInputFiles('#uploadfile', file);
  await expect(page.locator('#uploadname')).toHaveText(file.name);
  await page.click('#uploadstart');
  await expect(page.locator('#uploaddialog')).toBeHidden();
}

test('upload button: the link is inserted at the cursor and the typed text is kept', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/index.php/' + encodeURIComponent('Page with upload'));
  await page.fill('#text', 'before after');
  // (the cursor between the two words)
  await page.evaluate(() => { const t = document.getElementById('text'); t.focus(); t.setSelectionRange(7, 7); });
  // (nothing of the upload is shown in the editor itself)
  await expect(page.locator('#uploaddialog')).toBeHidden();
  await uploadFromDialog(page, upload('editor-upload.gif'));
  await expect(page.locator('#text')).toHaveValue('before ![Image Description](/images/editor-upload.gif)after');
  expect(page.url()).toContain('Page%20with%20upload');
  expect(errors).toEqual([]);

  // the page was not saved by the upload, and it can be saved now
  await page.click('#save');
  await page.waitForURL(/Page%20with%20upload/);
  await expect(page.locator('img[src^="/file.php?name=editor-upload.gif&v="]')).toHaveCount(1);
});

test('upload: a space in the name is replaced, and the stored name is linked', async ({ page }) => {
  await page.goto('/index.php?action=new');
  await uploadFromDialog(page, upload('my picture.gif'));
  await expect(page.locator('#text')).toHaveValue('![Image Description](/images/my_picture.gif)');
});

test('upload: a refused file shows the error and leaves the text alone', async ({ page }) => {
  await page.goto('/index.php?action=new');
  await page.fill('#text', 'typed text');
  await page.click('#uploadbutton');
  await page.setInputFiles('#uploadfile', { name: 'script.php', mimeType: 'text/plain', buffer: Buffer.from('<?php echo 1;') });
  await page.click('#uploadstart');
  // (the dialog stays open with the error)
  await expect(page.locator('#uploadstatus.error')).toContainText('invalid file type');
  await expect(page.locator('#uploaddialog')).toBeVisible();
  await page.click('#uploadcancel');
  await expect(page.locator('#uploaddialog')).toBeHidden();
  await expect(page.locator('#text')).toHaveValue('typed text');
});

test('upload: an existing file is only replaced after confirmation', async ({ page }) => {
  await page.goto('/index.php?action=new');
  await uploadFromDialog(page, upload('twice.gif'));
  await expect(page.locator('#text')).toHaveValue('![Image Description](/images/twice.gif)');
  const messages = [];
  page.on('dialog', async (dialog) => { messages.push(dialog.message()); await dialog.dismiss(); });
  await page.click('#uploadbutton');
  await page.setInputFiles('#uploadfile', upload('twice.gif'));
  await page.click('#uploadstart');
  await expect.poll(() => messages.length).toBe(1);
  expect(messages[0]).toContain('twice.gif');
  await expect(page.locator('#uploaddialog')).toBeHidden();
  await expect(page.locator('#text')).toHaveValue('![Image Description](/images/twice.gif)');
});

test('upload: the name chosen under "Save as" is used, and it keeps the extension', async ({ page }) => {
  await page.goto('/index.php?action=new');
  await page.click('#uploadbutton');
  await page.fill('#uploadnewname', 'holiday photo');
  await page.setInputFiles('#uploadfile', upload('IMG_0001.gif'));
  // (the name can be typed before or after the file is chosen)
  await expect(page.locator('#uploadnewname')).toHaveValue('holiday photo');
  await page.click('#uploadstart');
  await expect(page.locator('#uploaddialog')).toBeHidden();
  await expect(page.locator('#text')).toHaveValue('![Image Description](/images/holiday_photo.gif)');
});

test('upload: whether a file exists is checked for the name chosen under "Save as"', async ({ page }) => {
  await page.goto('/index.php?action=new');
  await uploadFromDialog(page, upload('taken-name.gif'));
  const messages = [];
  page.on('dialog', async (dialog) => { messages.push(dialog.message()); await dialog.dismiss(); });
  await page.click('#uploadbutton');
  await page.setInputFiles('#uploadfile', upload('other.gif'));
  await page.fill('#uploadnewname', 'taken-name');
  await page.click('#uploadstart');
  await expect.poll(() => messages.length).toBe(1);
  expect(messages[0]).toContain('taken-name.gif');
  await expect(page.locator('#uploaddialog')).toBeHidden();
  expect((await page.request.get('/file.php?name=other.gif')).status()).toBe(404);
});

test('upload: the name under "Save as" is for one file, the next file keeps its own name', async ({ page }) => {
  await page.goto('/index.php?action=new');
  await page.click('#uploadbutton');
  await page.setInputFiles('#uploadfile', [upload('first.gif'), upload('second.gif')]);
  await page.fill('#uploadnewname', 'renamed');
  await page.click('#uploadstart');
  await expect(page.locator('#uploadname')).toHaveText('second.gif');
  await expect(page.locator('#uploadnewname')).toHaveValue('');
  await page.click('#uploadstart');
  await expect(page.locator('#uploaddialog')).toBeHidden();
  await expect(page.locator('#text')).toHaveValue('![Image Description](/images/renamed.gif)\n![Image Description](/images/second.gif)');
});

test('drop: files dropped on the text are shown in the dialog, and uploaded from there', async ({ page }) => {
  await page.goto('/index.php?action=new');
  await page.fill('#text', 'x');
  await page.evaluate(async (base64) => {
    const bytes = Uint8Array.from(atob(base64), (c) => c.charCodeAt(0));
    const transfer = new DataTransfer();
    transfer.items.add(new File([bytes], 'dropped.gif', { type: 'image/gif' }));
    document.getElementById('text').dispatchEvent(new DragEvent('drop', { dataTransfer: transfer, bubbles: true, cancelable: true }));
  }, gif().toString('base64'));
  await expect(page.locator('#uploadname')).toHaveText('dropped.gif');
  await page.click('#uploadstart');
  await expect(page.locator('#text')).toHaveValue(/!\[Image Description\]\(\/images\/dropped\.gif\)/);
});

test('paste: an image from the clipboard is uploaded under a new name, after the dialog', async ({ page }) => {
  await page.goto('/index.php?action=new');
  await page.click('#text');
  await page.evaluate(async (base64) => {
    const bytes = Uint8Array.from(atob(base64), (c) => c.charCodeAt(0));
    const transfer = new DataTransfer();
    transfer.items.add(new File([bytes], 'image.gif', { type: 'image/gif' }));
    document.getElementById('text').dispatchEvent(new ClipboardEvent('paste', { clipboardData: transfer, bubbles: true, cancelable: true }));
  }, gif().toString('base64'));
  await expect(page.locator('#uploadname')).toHaveText(/^image-\d{14}\.gif$/);
  await page.click('#uploadstart');
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

test.describe('cropping', () => {
  // (needs the PHP extension imagick on the server; the editor offers the crop only with it)
  const cropAvailable = async (page) => (await page.locator('#cropbox').count()) > 0;

  async function choose(page, name, width, height) {
    await page.goto('/index.php?action=new');
    test.skip(!(await cropAvailable(page)), 'no image processing on the server');
    await page.click('#uploadbutton');
    await page.setInputFiles('#uploadfile', { name, mimeType: 'image/png', buffer: png(width, height) });
    await expect(page.locator('#cropsection')).toBeVisible();
    await expect(page.locator('#cropimage')).toBeVisible();
  }

  async function storedSize(page, name) {
    const response = await page.request.get('/file.php?name=' + encodeURIComponent(name));
    expect(response.status()).toBe(200);
    return pngSize(await response.body());
  }

  test('the whole image is the start, and the result size is shown', async ({ page }) => {
    await choose(page, 'whole.png', 400, 200);
    await expect(page.locator('#resultsize')).toContainText('400 × 200');
    await page.click('#uploadstart');
    await expect(page.locator('#uploaddialog')).toBeHidden();
    expect(await storedSize(page, 'whole.png')).toEqual([400, 200]);
  });

  test('dragging a border crops the image', async ({ page }) => {
    await choose(page, 'dragged.png', 400, 200);
    const image = await page.locator('#cropimage').boundingBox();
    // the right border to the middle of the image, the bottom border to a quarter of its height
    const east = await page.locator('#cropbox .handle.e').boundingBox();
    await page.mouse.move(east.x + east.width / 2, east.y + east.height / 2);
    await page.mouse.down();
    await page.mouse.move(image.x + image.width / 2, image.y + image.height / 2, { steps: 5 });
    await page.mouse.up();
    const south = await page.locator('#cropbox .handle.s').boundingBox();
    await page.mouse.move(south.x + south.width / 2, south.y + south.height / 2);
    await page.mouse.down();
    await page.mouse.move(image.x + image.width / 4, image.y + image.height / 2, { steps: 5 });
    await page.mouse.up();
    await expect(page.locator('#resultsize')).toContainText(/Result: \d+ × \d+ pixels/);
    await expect(page.locator('#resultsize')).not.toContainText('400 × 200');
    await page.click('#uploadstart');
    await expect(page.locator('#uploaddialog')).toBeHidden();
    const [width, height] = await storedSize(page, 'dragged.png');
    expect(Math.abs(width - 200)).toBeLessThanOrEqual(8);
    expect(Math.abs(height - 100)).toBeLessThanOrEqual(8);
    await expect(page.locator('#text')).toHaveValue('![Image Description](/images/dragged.png)');
  });

  test('arrow keys move the frame, Shift changes its size, and the image can be shrunk after cropping', async ({ page }) => {
    await choose(page, 'keys.png', 400, 200);
    await page.locator('#cropbox').focus();
    // 50 times Shift+Left: the frame gets 50 % narrower; the result is then shrunk to 100 pixels
    for (let i = 0; i < 50; i++) await page.keyboard.press('Shift+ArrowLeft');
    await expect(page.locator('#resultsize')).toContainText('200 × 200');
    await page.fill('#uploadmaxsize', '100');
    await expect(page.locator('#resultsize')).toContainText('100 × 100');
    await page.click('#uploadstart');
    await expect(page.locator('#uploaddialog')).toBeHidden();
    expect(await storedSize(page, 'keys.png')).toEqual([100, 100]);
  });

  test('"Whole image" takes the frame back', async ({ page }) => {
    await choose(page, 'reset.png', 400, 200);
    await page.locator('#cropbox').focus();
    for (let i = 0; i < 10; i++) await page.keyboard.press('Shift+ArrowLeft');
    await expect(page.locator('#resultsize')).not.toContainText('400 × 200');
    await page.click('#cropreset');
    await expect(page.locator('#resultsize')).toContainText('400 × 200');
  });

  test('Cancel uploads nothing', async ({ page }) => {
    await choose(page, 'cancelled.png', 40, 20);
    await page.click('#uploadcancel');
    await expect(page.locator('#uploaddialog')).toBeHidden();
    expect((await page.request.get('/file.php?name=cancelled.png')).status()).toBe(404);
    await expect(page.locator('#text')).toHaveValue('');
  });

  test('the preview also works when the web server adds a stricter Content-Security-Policy', async ({ page }) => {
    // (all policies of a response apply: this one has no img-src, so images can only come from the wiki itself;
    // neither a data: nor a blob: address may be used to show the file which was chosen)
    await page.route('**/index.php?action=new', async (route) => {
      const response = await route.fetch();
      await route.fulfill({ response, headers: { ...response.headers(), 'content-security-policy': response.headers()['content-security-policy'] + ', default-src \'self\'' } });
    });
    const violations = [];
    page.on('console', (message) => { if (/content security policy|content-security-policy/i.test(message.text())) violations.push(message.text()); });
    await choose(page, 'strict.png', 400, 200);
    const size = await page.locator('#cropimage').boundingBox();
    expect(size.width).toBeGreaterThan(50);
    expect(size.height).toBeGreaterThan(20);
    await page.locator('#cropbox').focus();
    for (let i = 0; i < 50; i++) await page.keyboard.press('Shift+ArrowLeft');
    await expect(page.locator('#resultsize')).toContainText('200 × 200');
    await page.click('#uploadstart');
    await expect(page.locator('#uploaddialog')).toBeHidden();
    expect(await storedSize(page, 'strict.png')).toEqual([200, 200]);
    expect(violations).toEqual([]);
  });
});
