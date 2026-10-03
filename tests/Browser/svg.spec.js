// Uploaded SVG files are opened directly in the browser, where scripts in them would run in the origin of the wiki
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { watchDialogs, interact, activeContent, upload, svgBase } = require('./helpers');

const fixtures = path.join(__dirname, '..', 'fixtures', 'svg');
const files = fs.readdirSync(fixtures).filter((f) => f.endsWith('.svg')).map((f) => f.replace(/\.svg$/, ''));

for (const name of files) {
  test(`${name}.svg`, async ({ page, request }) => {
    const dialogs = watchDialogs(page);
    const base = svgBase();
    const response = await upload(request, `${name}.svg`, fs.readFileSync(path.join(fixtures, `${name}.svg`)), 'image/svg+xml', base);
    expect(response.status()).toBe(303);

    const stored = await request.get(`${base}/images/${name}.svg`);
    if (stored.status() === 404) return; // refused, which is fine for dangerous files
    expect(stored.status()).toBe(200);

    await page.goto(`${base}/images/${name}.svg`);
    await interact(page);
    expect(dialogs).toEqual([]);
    expect(await activeContent(page)).toEqual([]);
  });
}

test('sanitized SVG files are still drawn', async ({ page, request }) => {
  const base = svgBase();
  await upload(request, 'drawn.svg', fs.readFileSync(path.join(fixtures, 'benign.svg')), 'image/svg+xml', base);
  await page.goto(`${base}/images/drawn.svg`);
  expect(await page.evaluate(() => document.documentElement.localName)).toBe('svg');
  await expect(page.locator('path')).toHaveCount(1);
  await expect(page.locator('text')).toHaveText('Hi');
});

test('SVG files in the upload list and in pages are shown as images', async ({ page, request }) => {
  const dialogs = watchDialogs(page);
  const base = svgBase();
  await upload(request, 'listed.svg', fs.readFileSync(path.join(fixtures, 'script.svg')), 'image/svg+xml', base);
  await page.goto(`${base}/index.php?action=upload`);
  await interact(page);
  expect(dialogs).toEqual([]);
  await expect(page.locator('img.thumbImg[src^="/images/listed.svg?v="]')).toHaveCount(1);
});
