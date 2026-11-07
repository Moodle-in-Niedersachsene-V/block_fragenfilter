/* Run against a dedicated devstack with the manifest produced by seed.php. */
const {chromium, expect} = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

(async () => {
    const base = process.env.FRAGENFILTER_E2E_URL;
    const fixture = JSON.parse(fs.readFileSync(process.env.FRAGENFILTER_E2E_FIXTURE, 'utf8'));
    if (!base || !/^https?:\/\//.test(base)) {
        throw new Error('Set FRAGENFILTER_E2E_URL to the dedicated test site.');
    }
    const out = process.env.FRAGENFILTER_E2E_OUTPUT || '/tmp/fragenfilter-e2e';
    fs.mkdirSync(out, {recursive: true});
    const browser = await chromium.launch({
        headless: true,
        ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE ? {executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE} : {}),
        args: ['--no-sandbox'],
    });
    const report = {url: base, token: fixture.token, cases: [], pageErrors: []};
    const contexts = [];
    const record = name => report.cases.push({name, status: 'passed'});
    const newVisitor = async () => {
        const context = await browser.newContext({viewport: {width: 1440, height: 1100}, acceptDownloads: true});
        contexts.push(context);
        context.on('page', page => page.on('pageerror', error => report.pageErrors.push(error.message)));
        const page = await context.newPage();
        await page.goto(base, {waitUntil: 'networkidle'});
        const block = page.locator('#region-main .qf-block');
        await expect(block).toBeVisible();
        await block.locator('input[id^="qf-search-"]').fill(fixture.token);
        await expect(block.locator('.qf-item')).toHaveCount(2, {timeout: 30000});
        return {context, page, block};
    };
    const ajax = (page, methodname, args) => page.evaluate(async ({methodname, args}) => {
        const result = await fetch(M.cfg.wwwroot + '/lib/ajax/service.php?sesskey=' + M.cfg.sesskey, {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify([{index: 0, methodname, args}]),
        });
        return (await result.json())[0];
    }, {methodname, args});
    try {
        const {context, page, block} = await newVisitor();
        await expect(block.locator('.qf-export:visible')).toHaveCount(0);
        expect(await block.evaluate(element => element.getBoundingClientRect().width)).toBeGreaterThan(500);
        expect(await page.evaluate(() => window.__qf_xss)).toBeUndefined();
        await expect(block.locator('[onfocus]')).toHaveCount(0);
        record('Public central search: two published questions, no export and no injected attributes');
        await block.screenshot({path: path.join(out, 'public-search.png')});

        const rejected = await context.request.post(new URL('/blocks/fragenfilter/guest_preview.php', base).href, {
            form: {qid: String(fixture.outside), sesskey: await page.evaluate(() => M.cfg.sesskey)},
        });
        const rejectedBody = await rejected.text();
        expect(rejectedBody).toMatch(/Question not found|Frage[^<]*nicht gefunden/i);
        expect(rejectedBody).not.toContain('qf_pool_login_done');
        const anonymous = await ajax(page, 'block_fragenfilter_check_capabilities', {contextid: fixture.contextid || 1});
        expect(anonymous.error).toBe(false);
        expect(anonymous.data.isloggedin).toBe(false);
        record('Private question ID cannot log an anonymous visitor into a pool account');

        const first = block.locator(`.qf-item[data-qid="${fixture.questions[0].id}"]`);
        await first.locator('.qf-chk').check();
        const popupEvent = page.waitForEvent('popup');
        await first.locator('.qf-preview-btn').click();
        const popup = await popupEvent;
        await popup.waitForURL(/\/question\/bank\/previewquestion\/preview.php\?.*cmid=/, {timeout: 30000});
        await expect(popup.locator('.que')).toContainText(fixture.token);
        await expect(popup.locator('.que')).toContainText('Zwei plus zwei ist vier.');
        await popup.screenshot({path: path.join(out, 'core-preview.png'), fullPage: true});
        await expect(block.locator('input[id^="qf-search-"]')).toHaveValue(fixture.token);
        await expect(block.locator('.qf-item')).toHaveCount(2);
        await expect(block.locator(`.qf-item[data-qid="${fixture.questions[0].id}"] .qf-chk`)).toBeChecked();
        await expect(block.locator('.qf-export[data-format="csv"]')).toBeVisible();
        await expect(block.locator('.qf-export[data-format="csv"]')).toBeEnabled();
        const capability = await ajax(page, 'block_fragenfilter_check_capabilities', {contextid: fixture.contextid || 1});
        expect(capability.data.canexport).toBe(true);
        record('Guest preview opens real core question with cmid; filter and selection survive pool login');

        const secondEvent = page.waitForEvent('popup');
        await block.locator(`.qf-item[data-qid="${fixture.questions[1].id}"] .qf-preview-btn`).click();
        const second = await secondEvent;
        await second.waitForURL(/\/question\/bank\/previewquestion\/preview.php\?.*cmid=/);
        await expect(second.locator('.que')).toContainText(fixture.token);
        record('A second preview works in the existing pool session');

        for (const format of ['xml', 'csv', 'gift']) {
            const downloaded = page.waitForEvent('download');
            await block.locator(`.qf-export[data-format="${format}"]`).click();
            const download = await downloaded;
            const file = path.join(out, `questions.${format}`);
            await download.saveAs(file);
            const content = fs.readFileSync(file, 'utf8');
            expect(content).toContain(fixture.token);
            if (format === 'xml') expect(content).toContain('<quiz>');
            if (format === 'gift') expect(content).toContain('{TRUE}');
        }
        record('XML, CSV and GIFT downloads contain the selected published question');

        const star = block.locator(`.qf-item[data-qid="${fixture.questions[0].id}"] .qf-star[data-star="4"]`);
        const ratingResponse = page.waitForResponse(async response => {
            return response.url().includes('/lib/ajax/service.php') &&
                (response.request().postData() || '').includes('block_fragenfilter_rate_question');
        });
        await star.click();
        const rating = (await (await ratingResponse).json())[0];
        expect(rating.error).toBe(false);
        expect(rating.data.myrating).toBe(4);
        const cookies = await context.cookies();
        const cookie = cookies.find(cookie => cookie.name === 'kf_rater_id');
        expect(cookie).toBeTruthy();
        expect(cookie.httpOnly).toBe(true);
        expect(cookie.secure).toBe(true);
        expect(cookie.sameSite).toBe('Lax');
        expect(await page.evaluate(() => document.cookie.includes('kf_rater_id='))).toBe(false);
        const repeated = await ajax(page, 'block_fragenfilter_rate_question', {
            questionid: fixture.questions[0].id, rating: 1, contextid: fixture.contextid || 1,
        });
        expect(repeated.data.myrating).toBe(4);
        expect(repeated.data.count).toBe(rating.data.count);
        record('Browser rating is cookie-bound, HttpOnly and Secure; repeated rating keeps first stars');

        const denied = await ajax(page, 'block_fragenfilter_export_questions', {
            questionids: String(fixture.outside), format: 'csv', contextid: fixture.contextid || 1,
        });
        expect(denied.error).toBe(true);
        expect(denied.exception.errorcode).toBe('invalidquestion');
        const guarded = await context.newPage();
        for (const route of ['/user/profile.php', '/user/preferences.php', '/login/change_password.php']) {
            await guarded.goto(new URL(route, base).href, {waitUntil: 'domcontentloaded'});
            expect(new URL(guarded.url()).pathname).toBe('/');
        }
        await guarded.close();
        record('Pool account is kept off profile/password pages; private-ID export is rejected');

        const another = await newVisitor();
        const anotherPopupEvent = another.page.waitForEvent('popup');
        await another.block.locator(`.qf-item[data-qid="${fixture.questions[0].id}"] .qf-preview-btn`).click();
        const anotherPopup = await anotherPopupEvent;
        await anotherPopup.waitForURL(/\/question\/bank\/previewquestion\/preview.php\?.*cmid=/);
        await expect(anotherPopup.locator('.que')).toContainText(fixture.token);
        record('Another browser behind the same address receives a working independent pool session');
        await block.screenshot({path: path.join(out, 'after-preview-export-rating.png')});
        expect(report.pageErrors).toEqual([]);
        report.status = 'passed';
    } catch (error) {
        report.status = 'failed';
        report.error = error.message;
        for (const [index, context] of contexts.entries()) {
            for (const [pageIndex, page] of context.pages().entries()) {
                await page.screenshot({path: path.join(out, `failure-${index}-${pageIndex}.png`), fullPage: true}).catch(() => {});
            }
        }
        throw error;
    } finally {
        fs.writeFileSync(path.join(out, 'report.json'), JSON.stringify(report, null, 2));
        for (const context of contexts) {
            for (const page of context.pages()) {
                const key = await page.evaluate(() => window.M?.cfg?.sesskey).catch(() => null);
                if (key) {
                    await context.request.get(new URL('/login/logout.php?sesskey=' + encodeURIComponent(key), base).href).catch(() => {});
                    break;
                }
            }
            await context.close();
        }
        await browser.close();
        console.log(JSON.stringify(report, null, 2));
    }
})().catch(error => {console.error(error); process.exit(1);});
