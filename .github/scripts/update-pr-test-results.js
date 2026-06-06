// Parse the JUnit XML artifacts and upsert a "Test results" block into the PR
// description. Invoked from ci.yml via actions/github-script.
//
// Expects these env vars: PHPUNIT_REPORT_URL, E2E_BOOKING_REPORT_URL,
// TESTS_RESULT, QUALITY_GATE_RESULT, SECURITY_GATE_RESULT.
const fs = require('fs');

function parseJunitXml(filePath) {
    if (!fs.existsSync(filePath)) return null;
    const xml = fs.readFileSync(filePath, 'utf8');
    const tests = (xml.match(/<testcase[\s>]/g) || []).length;
    const failures = (xml.match(/<failure[\s>]/g) || []).length
        + (xml.match(/<error[\s>]/g) || []).length;
    const skipped = (xml.match(/<skipped[\s>/]/g) || []).length;
    let suites = 0, time = 0;
    for (const m of xml.matchAll(/<testsuite[^>]*>/g)) {
        const t = m[0].match(/\btests="([1-9]\d*)"/);
        if (t) suites++;
        const tm = m[0].match(/\btime="([^"]+)"/);
        if (tm) time += parseFloat(tm[1]);
    }
    return tests > 0 ? { tests, failures, skipped, suites, time } : null;
}

function formatTime(seconds) {
    if (seconds < 1) return `${Math.round(seconds * 1000)}ms`;
    if (seconds < 60) return `${Math.round(seconds)}s`;
    return `${Math.floor(seconds / 60)}m ${Math.round(seconds % 60)}s`;
}

module.exports = async ({ github, context, core }) => {
    const owner = context.repo.owner;
    const repo = context.repo.repo;
    const prNumber = context.payload.pull_request.number;

    const types = [
        {
            key: 'phpunit',
            label: 'PHPUnit',
            file: 'junit-results/phpunit-fib-booking-system.xml',
            urlEnv: 'PHPUNIT_REPORT_URL',
        },
        {
            key: 'phpunit_demodata',
            label: 'PHPUnit — DemoData',
            file: 'junit-results/phpunit-fib-booking-demo-data.xml',
            urlEnv: 'PHPUNIT_DEMODATA_REPORT_URL',
        },
        {
            key: 'e2e_booking',
            label: 'Playwright — Booking',
            file: 'junit-results/playwright-booking.xml',
            urlEnv: 'E2E_BOOKING_REPORT_URL',
        },
    ];

    const sections = [];
    for (const t of types) {
        const r = parseJunitXml(t.file);
        const url = process.env[t.urlEnv] || '';
        const reportLink = url ? ` ([report](${url}))` : '';

        if (!r) {
            sections.push(`⚪ **${t.label}**${reportLink}\n no results`);
            continue;
        }

        const status = r.failures > 0 ? '❌' : '✅';
        const passed = r.tests - r.failures - r.skipped;
        sections.push([
            `${status} **${t.label}**${reportLink}`,
            ` ${r.suites} suites   ${formatTime(r.time)} ⏱️`,
            ` ${r.tests} tests   ${passed} ✅   ${r.skipped} 💤   ${r.failures} ❌`,
        ].join('\n'));
    }

    const start = '<!-- test-results:start -->';
    const end = '<!-- test-results:end -->';

    const testsRan = ['success', 'failure'].includes(process.env.TESTS_RESULT);
    let body_section;
    if (testsRan) {
        body_section = sections.join('\n\n');
    } else {
        const blocked = [];
        if (process.env.QUALITY_GATE_RESULT !== 'success') blocked.push('quality gate');
        if (process.env.SECURITY_GATE_RESULT !== 'success') blocked.push('security gate');
        const reason = blocked.length ? blocked.join(' + ') : 'an upstream gate';
        body_section = `⚠️ Tests not run — blocked by the ${reason}. Fix the gate(s) above, then the suite runs.`;
    }
    const block = `${start}\n\n---\n\n### 🧪 Test results\n\n${body_section}\n\n${end}`;

    const { data: pr } = await github.rest.pulls.get({
        owner, repo, pull_number: prNumber,
    });

    let body = pr.body || '';
    if (body.includes(start) && body.includes(end)) {
        body = body.replace(new RegExp(`${start}[\\s\\S]*?${end}`), block);
    } else {
        body = `${body.trimEnd()}\n\n${block}\n`;
    }

    await github.rest.pulls.update({
        owner, repo, pull_number: prNumber, body,
    });
    core.info(`Updated PR #${prNumber} with test results.`);
};
