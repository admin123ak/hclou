import express from 'express';
import axios from 'axios';
import dotenv from 'dotenv';
import moment from 'moment-timezone';
import puppeteer from 'puppeteer';

dotenv.config({ quiet: true });

const PORT = Number(process.env.PORT || 3120);
const HOST = process.env.HOST || '127.0.0.1';
const username = process.env.MBB_USER;
const password = process.env.MBB_PASS;
const accountNo = process.env.MBB_ACCOUNT_NUMBER;
const captchaApiUrl = process.env.CAPTCHA_API_URL || 'http://103.153.64.187:8277/api/captcha/mbbank';
const executablePath = process.env.PUPPETEER_EXECUTABLE_PATH || puppeteer.executablePath();
const sessionTtlMs = Number(process.env.MBB_SESSION_TTL_MS || 20 * 60 * 1000);

let session = null;
let loginLock = null;
let lastError = '';
let lastLoginAt = 0;

function nowIso() { return new Date().toISOString(); }
function log(...args) { console.log(nowIso(), ...args); }
function mask(v) { return String(v || '').replace(/^(.{3}).*(.{2})$/, '$1***$2'); }

async function solveCaptcha(base64) {
  const res = await axios.post(captchaApiUrl, { base64 }, { timeout: 30000 });
  if (!res.data?.captcha) throw new Error('Captcha API did not return captcha');
  return String(res.data.captcha).trim();
}

async function loginMBBank() {
  if (loginLock) return loginLock;
  loginLock = (async () => {
    if (!username || !password || !accountNo) throw new Error('Missing MBB env');
    log('login start user=', mask(username), 'account=', mask(accountNo));
    const browser = await puppeteer.launch({
      executablePath,
      headless: 'new',
      args: [
        '--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage',
        '--disable-gpu', '--window-size=1920,1080', '--disable-extensions'
      ]
    });
    try {
      const page = await browser.newPage();
      await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36');
      await page.setViewport({ width: 1920, height: 1080 });
      let loginResponse = null;
      page.on('response', async (resp) => {
        try {
          if (resp.url().includes('doLogin')) {
            const txt = await resp.text();
            if (txt.trim().startsWith('{')) loginResponse = JSON.parse(txt);
          }
        } catch {}
      });
      await page.goto('https://online.mbbank.com.vn/pl/login?returnUrl=%2F', { waitUntil: 'networkidle2', timeout: 60000 });
      await page.waitForSelector('img[src^="data:image/png;base64,"]', { timeout: 30000 });
      const base64 = await page.$eval('img[src^="data:image/png;base64,"]', img => img.getAttribute('src').replace(/^data:image\/png;base64,/, ''));
      const captcha = await solveCaptcha(base64);
      await page.waitForSelector('#user-id', { timeout: 15000 });
      await page.type('#user-id', username, { delay: 20 });
      await page.type('#new-password', password, { delay: 20 });
      const captchaSelector = 'input[placeholder="NHẬP MÃ KIỂM TRA"], mbb-word-captcha input[type="text"], input.pl-upper';
      await page.waitForSelector(captchaSelector, { timeout: 15000 });
      await page.click(captchaSelector);
      await page.keyboard.down('Control'); await page.keyboard.press('A'); await page.keyboard.up('Control');
      await page.type(captchaSelector, captcha, { delay: 20 });
      await Promise.allSettled([
        page.click('#login-btn'),
        page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 })
      ]);
      const deadline = Date.now() + 30000;
      while (!loginResponse && Date.now() < deadline) await new Promise(r => setTimeout(r, 500));
      if (!loginResponse) throw new Error('No doLogin response captured');
      const result = loginResponse.result || loginResponse.loginResult || loginResponse;
      if (result?.responseCode !== '00' && result?.ok !== true) throw new Error('Login failed: ' + JSON.stringify(result).slice(0, 500));
      const sessionId = result.sessionId || loginResponse.sessionId;
      const deviceId = result?.cust?.deviceId || loginResponse?.cust?.deviceId;
      if (!sessionId || !deviceId) throw new Error('Missing sessionId/deviceId after login');
      session = { sessionId, deviceId, createdAt: Date.now() };
      lastLoginAt = Date.now();
      lastError = '';
      log('login ok');
      return session;
    } finally {
      await browser.close().catch(() => {});
      loginLock = null;
    }
  })();
  return loginLock;
}

async function getSession(force = false) {
  if (!force && session && Date.now() - session.createdAt < sessionTtlMs) return session;
  return loginMBBank();
}

async function fetchHistory(forceLogin = false) {
  const s = await getSession(forceLogin);
  const time = moment.tz('Asia/Ho_Chi_Minh').format('YYYYMMDDHHmmss') + '00';
  const refNo = `${accountNo}-${time}`;
  const payload = {
    accountNo,
    fromDate: moment().subtract(3, 'days').format('DD/MM/YYYY'),
    toDate: moment().format('DD/MM/YYYY'),
    sessionId: s.sessionId,
    refNo,
    deviceIdCommon: s.deviceId
  };
  const res = await axios.post('https://online.mbbank.com.vn/api/retail-transactionms/transactionms/get-account-transaction-history', payload, {
    timeout: 30000,
    headers: {
      app: 'MB_WEB',
      Authorization: 'Basic RU1CUkVUQUlMV0VCOlNEMjM0ZGZnMzQlI0BGR0AzNHNmc2RmNDU4NDNm',
      Deviceid: s.deviceId,
      Host: 'online.mbbank.com.vn',
      Origin: 'https://online.mbbank.com.vn',
      Referer: 'https://online.mbbank.com.vn/information-account/source-account',
      Refno: refNo,
      'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
      'X-Request-Id': refNo,
      'Content-Type': 'application/json'
    }
  });
  const data = res.data;
  if (!data.result?.ok) {
    const msg = data.result?.message || '';
    if (/session|expired|401/i.test(msg) || data.result?.responseCode === '401') {
      session = null;
      return fetchHistory(true);
    }
    throw new Error('MBB history error: ' + JSON.stringify(data.result || data).slice(0, 500));
  }
  const transactions = (data.transactionHistoryList || []).map(tx => ({
    transaction_date: tx.transactionDate || tx.postingDate || '',
    formatted_date: tx.transactionDate || tx.postingDate || '',
    credit_amount: Number(tx.creditAmount || 0),
    amount: Number(tx.creditAmount || 0),
    type: Number(tx.creditAmount || 0) > 0 ? 'IN' : 'OUT',
    description: tx.description || tx.addDescription || '',
    addDescription: tx.addDescription || '',
    refNo: tx.refNo || '',
    raw: tx
  }));
  return { success: true, source: 'mbbank-direct', result: data.result, transactions };
}

const app = express();
app.get('/health', (req, res) => res.json({ success: true, service: 'mbbank-direct', session: !!session, lastLoginAt, lastError }));
app.get('/history', async (req, res) => {
  try {
    const data = await fetchHistory(req.query.forceLogin === '1');
    res.json(data);
  } catch (e) {
    lastError = e.message;
    res.status(500).json({ success: false, error: e.message });
  }
});
app.get('/', (req, res) => res.redirect('/history'));

app.listen(PORT, HOST, () => log(`mbbank-direct listening http://${HOST}:${PORT}`));
