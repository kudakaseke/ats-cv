# Navigation and authentication responsiveness ? 30 October 2026

Public pages and authentication screens now load a separate JavaScript entry without the CV editor, scanner or Axios. The workspace/builder entry still registers its components before Alpine starts; Livewire retains control of Alpine in the workspace.

The public JavaScript build is 67.47 kB (24.10 kB gzip), compared with the previous shared 183.04 kB entry. This reduces public-page JavaScript by approximately 63%; it is not a claim of a 63% reduction in total page latency.

Plus Jakarta Sans is self-hosted as a 60.43 kB variable WOFF2 with font-display:swap. Its source is the Google Fonts ofl/plusjakartasans family; the SIL Open Font License is included beside the font. All four layouts use the local font and no longer request the external render-blocking Google Fonts stylesheet.

Normal link navigation and auth-form submission show immediate pending feedback. Google sign-in labels change while its redirect starts. Modifier clicks, downloads, external links and in-page anchors retain normal browser behaviour. Back/forward restoration clears the pending state. No page HTML, CSRF token, OAuth state, token or profile is prefetched or shared-cached. Google's callback retains state/email verification and its existing 15-second per-request total timeout, with a 5-second connection timeout for failed connections. Google account selection and approval remain external.

Laravel routes and Blade views were cached locally. Configuration was not cached, so .env remains editable and test database overrides remain effective. Rebuild these caches after route/view deployment changes; use php artisan route:clear and php artisan view:clear when needed.

Verification: 48 relevant PHP/authentication/CV regression tests passed (270 assertions), 18 JavaScript tests passed, and the production Vite build succeeded. Browser checks passed for public/auth loads, no external font requests, mobile menu, navigation feedback, Back recovery and mocked Google handoff. A separate authenticated browser check passed for pasted CV -> mocked scan/rewrite -> real editor handoff. No paid AI requests were made.

One local Chromium run measured DOM/Alpine readiness at Home 1,056 ms, About 712 ms, Sign in 681 ms and Sign up 589 ms. These are development-machine observations, not production guarantees or a controlled before/after speed comparison. The Windows artisan development server can still queue navigation behind another long-running request; production should use a concurrent PHP web server.

Run npm.cmd run test:navigation and npm.cmd run test:navigation-browser (APP_TEST_URL may override the browser check's default local port 8001).
