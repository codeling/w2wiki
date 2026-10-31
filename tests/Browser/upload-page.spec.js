// SPDX-License-Identifier: MIT

// The upload page uses the dialog of the editor (there is no other way to upload files): the button opens it, and the
// list of the page shows the new files once they are uploaded
const { test, expect } = require('@playwright/test');
const { gif, png, pngSize } = require('./helpers');

test('upload page: the button opens the dialog, and the uploaded file is listed', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/index.php?action=upload');
  await expect(page.locator('form#upload')).toHaveCount(0);
  await expect(page.locator('#uploaddialog')).toBeHidden();
  await page.click('#uploadbutton');
  await expect(page.locator('#uploaddialog')).toBeVisible();
  await page.setInputFiles('#uploadfile', { name: 'from-the-list.gif', mimeType: 'image/gif', buffer: gif() });
  await page.fill('#uploadnewname', 'listed');
  await page.click('#uploadstart');
  // (the page is loaded again to show the file in its list, under the name chosen)
  await expect(page.locator('.uploadFileName', { hasText: 'listed.gif' })).toHaveCount(1);
  await expect(page.locator('#uploaddialog')).toBeHidden();
  expect((await page.request.get('/file.php?name=listed.gif')).status()).toBe(200);
  expect(errors).toEqual([]);
});

test('upload page: images can be cropped in the dialog', async ({ page }) => {
  await page.goto('/index.php?action=upload');
  test.skip((await page.locator('#cropbox').count()) === 0, 'no image processing on the server');
  await page.click('#uploadbutton');
  await page.setInputFiles('#uploadfile', { name: 'cropped-from-list.png', mimeType: 'image/png', buffer: png(400, 200) });
  await expect(page.locator('#cropsection')).toBeVisible();
  await page.locator('#cropbox').focus();
  for (let i = 0; i < 50; i++) await page.keyboard.press('Shift+ArrowLeft');
  await expect(page.locator('#resultsize')).toContainText('200 × 200');
  await page.click('#uploadstart');
  await expect(page.locator('.uploadFileName', { hasText: 'cropped-from-list.png' })).toHaveCount(1);
  const response = await page.request.get('/file.php?name=cropped-from-list.png');
  expect(pngSize(await response.body())).toEqual([200, 200]);
});

test('upload page: a refused file shows the error, and Cancel leaves the page as it is', async ({ page }) => {
  await page.goto('/index.php?action=upload');
  await page.click('#uploadbutton');
  await page.setInputFiles('#uploadfile', { name: 'script.php', mimeType: 'text/plain', buffer: Buffer.from('<?php echo 1;') });
  await page.click('#uploadstart');
  await expect(page.locator('#uploadstatus.error')).toContainText('invalid file type');
  await page.click('#uploadcancel');
  await expect(page.locator('#uploaddialog')).toBeHidden();
  await expect(page.locator('.uploadFileName', { hasText: 'script.php' })).toHaveCount(0);
});
