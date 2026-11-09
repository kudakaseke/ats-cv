// The mobile drawer is modal; desktop navigation remains ordinary navigation.
window.workspaceNavigation = () => ({
    sidebar: false,
    mobile: window.innerWidth <= 900,
    resize() {
        const wasMobile = this.mobile;
        this.mobile = window.innerWidth <= 900;
        if (!this.mobile) this.sidebar = false;
        if (!wasMobile && this.mobile && this.$refs.navigation.contains(document.activeElement)) {
            this.$nextTick(() => this.$refs.menuToggle.focus());
        }
    },
    focusable() {
        return [...this.$refs.navigation.querySelectorAll('a[href], button:not([disabled]), [tabindex="0"]')]
            .filter(element => element.getClientRects().length && !element.closest('[inert]'));
    },
    openSidebar() {
        this.sidebar = true;
        this.$nextTick(() => this.$refs.navigation.querySelector('nav a')?.focus());
    },
    closeSidebar() {
        const restore = this.mobile && this.sidebar;
        this.sidebar = false;
        if (restore) this.$nextTick(() => this.$refs.menuToggle.focus());
    },
    handleKey(event) {
        if (!this.mobile || !this.sidebar) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            this.closeSidebar();
        }
        if (event.key !== 'Tab') return;
        const items = this.focusable();
        const index = items.indexOf(document.activeElement);
        if (index < 0 || (event.shiftKey && index === 0) || (!event.shiftKey && index === items.length - 1)) {
            event.preventDefault();
            (event.shiftKey ? items.at(-1) : items[0])?.focus();
        }
    },
});
