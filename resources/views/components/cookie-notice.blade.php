<section class="cookie-notice" aria-label="Cookie notice" x-cloak x-show="visible"
    x-data="{
        visible: false,
        init() {
            try { this.visible = localStorage.getItem('ats_cookie_notice_v1') !== 'seen'; }
            catch { this.visible = true; }
        },
        dismiss() {
            try { localStorage.setItem('ats_cookie_notice_v1', 'seen'); } catch {}
            this.visible = false;
        }
    }">
    <div>
        <h2>Cookies that keep things working</h2>
        <p>We use essential cookies to keep you signed in, protect forms and maintain your CV session. We do not use advertising cookies.</p>
    </div>
    <div class="cookie-notice-actions">
        <a href="{{ route('privacy') }}#cookies">About our cookies</a>
        <button type="button" @click="dismiss()">Got it</button>
    </div>
</section>
