// The mobile drawer is modal; desktop navigation can be collapsed independently.
window.workspaceNavigation = () => ({
    sidebar: false,
    desktopCollapsed: false,
    mobile: window.innerWidth <= 900,
    init() {
        try { this.desktopCollapsed = localStorage.getItem('workspace-sidebar-collapsed') === 'true'; } catch {}
    },
    toggleDesktopSidebar() {
        this.desktopCollapsed = !this.desktopCollapsed;
        try { localStorage.setItem('workspace-sidebar-collapsed', String(this.desktopCollapsed)); } catch {}
    },
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
