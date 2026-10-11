#!/usr/bin/env node
/*
 * Test tv-remote.js w Chrome udającym telewizor Samsung (User-Agent Tizen).
 *   1) strona próbna: kafelki z data-action, podmiana zaznaczonego elementu (jak Mercure),
 *      szybkie podwójne Wstecz,
 *   2) prawdziwa strona https://gierkownia.j33.pl/ ze wstrzykniętym skryptem.
 *
 *   node site-integration/test-tv-remote.js
 */
'use strict';

const puppeteer = require(process.env.PUPPETEER || '/home/www/node_modules/puppeteer-core');
const fs = require('fs');
const path = require('path');
const assert = require('assert');

const UA = 'Mozilla/5.0 (SMART-TV; LINUX; Tizen 9.0) AppleWebKit/537.36 (KHTML, like Gecko) 120.0.6099.5/9.0 TV Safari/537.36';
const SRC = fs.readFileSync(path.join(__dirname, 'tv-remote.js'), 'utf8');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let passed = 0;
let failed = 0;
async function step(name, fn) {
    try { await fn(); passed++; console.log('  ✓ ' + name); } catch (e) { failed++; console.log('  ✗ ' + name + '\n      ' + e.message); }
}

const HARNESS = `<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="font:24px Arial;background:#111;color:#eee">
<button class="btn-primary" id="start">Start</button>
<div style="display:flex;gap:20px;margin:40px 0" id="tiles">
  <div class="tile" data-action="click->gra#wybierz" data-id="a" style="width:150px;height:100px;background:#333">A</div>
  <div class="tile" data-action="click->gra#wybierz" data-id="b" style="width:150px;height:100px;background:#333">B</div>
  <div class="tile" data-action="gra#wybierz" data-id="c" style="width:150px;height:100px;background:#333">C</div>
</div>
<div id="live"><button id="old">Następna runda</button></div>
<button data-tv-skip id="skip">pomiń mnie</button>
<p id="out"></p>
<div style="position:absolute;right:60px;top:640px">
  <select id="poziom">
    <option value="latwy">Łatwy</option>
    <option value="sredni" selected>Średni</option>
    <option value="trudny">Trudny</option>
  </select>
  <select id="lista" size="3" style="margin-left:20px">
    <option>jeden</option><option>dwa</option><option>trzy</option>
  </select>
</div>

<script>
  document.querySelectorAll('.tile').forEach(t => t.addEventListener('click', () => { document.getElementById('out').textContent = 'wybrano ' + t.dataset.id; }));
  window.zmiany = [];
  document.getElementById('poziom').addEventListener('change', (e) => window.zmiany.push(e.target.value));
  window.exited = 0;
  window.tizen = { application: { getCurrentApplication: () => ({ exit: () => { window.exited++; } }) } };
</script></body></html>`;

const active = (p) => p.evaluate(() => {
    const a = document.activeElement;
    return a ? (a.id || a.dataset.id || a.textContent.trim().slice(0, 30)) : null;
});
const back = (p) => p.evaluate(() => {
    const e = new KeyboardEvent('keydown', { bubbles: true, cancelable: true });
    Object.defineProperty(e, 'keyCode', { get: () => 10009 });
    (document.activeElement || document).dispatchEvent(e);
});

(async () => {
    const browser = await puppeteer.launch({ executablePath: process.env.CHROME || '/usr/bin/google-chrome', args: ['--no-sandbox'] });
    const page = await browser.newPage();
    await page.setUserAgent(UA);
    await page.setViewport({ width: 1920, height: 1080 });
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));

    console.log('Strona próbna');
    await page.evaluateOnNewDocument(SRC);
    const harness = path.join(require('os').tmpdir(), 'tv-remote-harness.html');
    fs.writeFileSync(harness, HARNESS);
    await page.goto('file://' + harness);
    await sleep(300);

    await step('start: fokus na przycisku głównym', async () => {
        assert.strictEqual(await active(page), 'start');
    });
    await step('strzałki wchodzą na kafelki z data-action (nie-przyciski)', async () => {
        await page.keyboard.press('ArrowDown');
        assert.strictEqual(await active(page), 'a');
        await page.keyboard.press('ArrowRight');
        assert.strictEqual(await active(page), 'b');
        await page.keyboard.press('ArrowRight');
        assert.strictEqual(await active(page), 'c');   // data-action bez zdarzenia = click
    });
    await step('pole wyboru: ◀▶ zmienia wartość (ze zdarzeniem change), ▲▼ wychodzi dalej', async () => {
        await page.evaluate(() => { document.getElementById('poziom').focus(); window.zmiany = []; });
        assert.strictEqual(await active(page), 'poziom');
        await page.keyboard.press('ArrowRight');
        assert.strictEqual(await page.evaluate(() => document.getElementById('poziom').value), 'trudny');
        await page.keyboard.press('ArrowRight');       // koniec listy — bez zmian
        assert.strictEqual(await page.evaluate(() => document.getElementById('poziom').value), 'trudny');
        await page.keyboard.press('ArrowLeft');
        await page.keyboard.press('ArrowLeft');
        assert.strictEqual(await page.evaluate(() => document.getElementById('poziom').value), 'latwy');
        assert.deepStrictEqual(await page.evaluate(() => window.zmiany), ['trudny', 'sredni', 'latwy']);
        // ▲▼ nie zmieniają wartości i wyprowadzają z pola — to był błąd: zaznaczenie
        // zostawało w rozwijanej liście i nie dało się jej opuścić pilotem
        await page.keyboard.press('ArrowDown');
        assert.strictEqual(await page.evaluate(() => document.getElementById('poziom').value), 'latwy',
            '▼ nie zmienia wartości');
        await page.keyboard.press('ArrowUp');
        assert.notStrictEqual(await active(page), 'poziom', 'zaznaczenie wychodzi z pola wyboru');
        assert.strictEqual(await page.evaluate(() => document.getElementById('poziom').value), 'latwy',
            '▲ też nie zmienia wartości');
        // lista wielowierszowa zostaje przy dotychczasowym zachowaniu (▲▼ przewijają ją)
        await page.evaluate(() => document.getElementById('lista').focus());
        await page.keyboard.press('ArrowDown');
        assert.strictEqual(await active(page), 'lista', 'lista size=3 obsługuje ▲▼ sama');
        await page.evaluate(() => document.querySelector('[data-id="c"]').focus());   // stan dla kolejnego kroku
    });
    await step('OK „klika" kafelek', async () => {
        await page.keyboard.press('Enter');
        assert.strictEqual(await page.$eval('#out', (e) => e.textContent), 'wybrano c');
    });
    await step('data-tv-skip — pilot pomija element', async () => {
        await page.keyboard.press('ArrowDown');
        assert.strictEqual(await active(page), 'old');
        await page.keyboard.press('ArrowDown');
        assert.notStrictEqual(await active(page), 'skip');
    });
    await step('podmiana zaznaczonego elementu (jak Mercure) → fokus wraca sam', async () => {
        await page.focus('#old');
        await page.evaluate(() => { document.getElementById('live').innerHTML = '<button id="new">Kolejne pytanie</button>'; });
        await sleep(400);
        const a = await active(page);
        assert.ok(a && a !== 'BODY' && a !== '', 'fokus: ' + a);
    });
    await step('pojedyncze Wstecz nie zamyka; szybkie podwójne zamyka aplikację', async () => {
        await back(page);                                   // pierwsze: tylko komunikat
        assert.strictEqual(await page.evaluate(() => window.exited), 0);
        assert.match(await page.evaluate(() => document.querySelector('.tv-toast').textContent), /jeszcze raz/);
        await sleep(2300);                                  // po oknie „naciśnij jeszcze raz" (2 s)
        await back(page);
        await sleep(150);
        await back(page);
        assert.strictEqual(await page.evaluate(() => window.exited), 1);
    });
    await step('brak błędów JS', async () => { assert.deepStrictEqual(errors, []); });

    console.log('Prawdziwa strona gierkownia.j33.pl');
    const live = await browser.newPage();
    await live.setUserAgent(UA);
    await live.setViewport({ width: 1920, height: 1080 });
    const liveErrors = [];
    live.on('pageerror', (e) => liveErrors.push(e.message));
    await live.evaluateOnNewDocument(SRC);
    await live.goto('https://gierkownia.j33.pl/', { waitUntil: 'networkidle2' });
    await sleep(300);
    await step('strona główna: fokus „Nowa gra", strzałki między polami i przyciskami', async () => {
        const txt = () => live.evaluate(() => {
            const a = document.activeElement;
            return a.tagName + ':' + (a.textContent || a.placeholder || a.name || '').trim().slice(0, 20);
        });
        assert.strictEqual(await txt(), 'BUTTON:Nowa gra');
        await live.keyboard.press('ArrowUp');
        assert.match(await txt(), /^INPUT:Hasło hosta/);
        await live.keyboard.press('ArrowRight');
        assert.match(await txt(), /^INPUT:ABCD/);
        await live.keyboard.press('ArrowDown');
        assert.strictEqual(await txt(), 'BUTTON:Dołącz');
        await live.screenshot({ path: path.join(process.argv[2] || '/tmp', 'gierki-pilot.png') });
    });
    await step('tryb TV włączony, brak błędów JS na stronie', async () => {
        assert.ok(await live.evaluate(() => document.documentElement.classList.contains('tv-mode')));
        assert.deepStrictEqual(liveErrors, []);
    });

    await browser.close();
    console.log('\n' + passed + ' OK, ' + failed + ' błędów');
    process.exit(failed ? 1 : 0);
})();
