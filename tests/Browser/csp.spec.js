// SVG files which were uploaded before sanitizing existed (or put in the uploads folder otherwise) can contain
// scripts. The web server rules make browsers ignore them; this needs the wiki to be served by Apache or nginx:
//   W2_BROWSER=1 tests/Server/run.sh apache
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { watchDialogs, interact } = require('./helpers');

const root = process.env.W2_SERVER_ROOT;
test.skip(!root, 'needs a wiki served by a real web server, see tests/Server/run.sh');

const script = '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><script>alert("svg script")</script><rect width="100" height="100" onmouseover="alert(\'svg handler\')" onload="alert(\'svg onload\')"/></svg>';
const created = [];

function place(relativePath) {
  const file = path.join(root, relativePath);
  fs.writeFileSync(file, script);
  fs.chmodSync(file, 0o644);
  created.push(file);
}

test.afterAll(() => {
  for (const file of created) fs.rmSync(file, { force: true });
});

test('scripts in SVG files of the uploads folder do not run', async ({ page }) => {
  const dialogs = watchDialogs(page);
  place('pages/images/legacy.svg');
  await page.goto('/images/legacy.svg');
  await interact(page);
  expect(dialogs).toEqual([]);
});

test('control: the same file elsewhere does run scripts, so the test above can notice them', async ({ page }) => {
  const dialogs = watchDialogs(page);
  place('Michelf/unprotected.svg');
  await page.goto('/Michelf/unprotected.svg');
  await interact(page);
  expect(dialogs.length).toBeGreaterThan(0);
});
