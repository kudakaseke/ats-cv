import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate } from 'k6/metrics';
import { SharedArray } from 'k6/data';

if (__ENV.ALLOW_LOAD_TEST !== 'yes' || !__ENV.TARGET_URL) {
    throw new Error('Set ALLOW_LOAD_TEST=yes and TARGET_URL to your authorised staging environment.');
}
const target = __ENV.TARGET_URL.replace(/\/$/, '');
const peak = Math.max(1, Math.min(2000, Number(__ENV.PEAK_VUS || 1000)));
const sessions = new SharedArray('staging-sessions', () => __ENV.SESSIONS_FILE ? JSON.parse(open(__ENV.SESSIONS_FILE)) : []);
if (sessions.length && (sessions.length < peak || new Set(Array.from(sessions)).size !== sessions.length)) {
    throw new Error('Authenticated runs need at least PEAK_VUS distinct, pre-authorised staging Cookie strings.');
}
const throttled = new Rate('rate_limited');
export const options = {
    stages: [
        { duration: '30s', target: Math.min(50, peak) },
        { duration: '1m', target: Math.min(200, peak) },
        { duration: '1m', target: Math.min(500, peak) },
        { duration: '2m', target: peak },
        { duration: '30s', target: 0 },
    ],
    thresholds: {
        http_req_failed: [{ threshold: 'rate<0.01', abortOnFail: true, delayAbortEval: '30s' }],
        http_req_duration: ['p(95)<1500', 'p(99)<3000'],
        rate_limited: ['rate<0.01'],
        checks: ['rate>0.99'],
    },
};

export default function () {
    const authenticated = sessions.length > 0;
    const paths = authenticated
        ? ['/dashboard', '/workspace/resumes', '/subscription', '/workspace/checker']
        : ['/', '/features', '/pricing', '/about'];
    const path = paths[__ITER % paths.length];
    const response = http.get(target + path, {
        redirects: 0, timeout: '15s', tags: { page: path, authenticated: String(authenticated) },
        headers: authenticated ? { Cookie: sessions[__VU - 1] } : {},
    });
    throttled.add(response.status === 429);
    check(response, { 'page loads without login redirects or server errors': result => result.status === 200 });
    sleep(5 + Math.random() * 10);
}
