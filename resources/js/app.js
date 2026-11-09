import './workspace-navigation';
import './bootstrap';

import Alpine from 'alpinejs';
import './cv-builder';
import './cv-checker';
import {
    createIcons, Asterisk, ArrowUpRight, ArrowRight, ArrowLeft, ShieldCheck,
    Sparkles, FileText, Check, FileCheck2, Download, Upload, Text,
    ChartNoAxesCombined, ScanText, GitCompareArrows, WandSparkles,
    FilePenLine, LayoutTemplate, FolderOpen, History, Info, Zap, Leaf,
    Plus, Menu, House, LayoutDashboard, FilePlus2, Gem, CreditCard, UserRound, LogOut,
    CircleHelp, ChevronDown, ChevronRight, ArrowDownRight, Target, Files, Maximize2, Eye, Pencil,
} from 'lucide';

const icons = { Asterisk, ArrowUpRight, ArrowRight, ArrowLeft, ShieldCheck,
    Sparkles, FileText, Check, FileCheck2, Download, Upload, Text,
    ChartNoAxesCombined, ScanText, GitCompareArrows, WandSparkles,
    FilePenLine, LayoutTemplate, FolderOpen, History, Info, Zap, Leaf,
    Plus, Menu, House, LayoutDashboard, FilePlus2, Gem, CreditCard, UserRound, LogOut,
    CircleHelp, ChevronDown, ChevronRight, ArrowDownRight, Target, Files, Maximize2, Eye, Pencil };

const renderIcons = () => createIcons({ icons });
document.addEventListener('DOMContentLoaded', renderIcons);
document.addEventListener('livewire:navigated', renderIcons);
document.addEventListener('livewire:initialized', () => {
    window.Livewire.hook('morph.updated', () => queueMicrotask(renderIcons));
});

// Livewire supplies Alpine on workspace pages; public and auth pages use this instance.
if (!document.querySelector('body[data-workspace]')) {
    window.Alpine = Alpine;
    Alpine.start();
}


// Shared navigation works on full-page and Livewire-rendered screens.
document.addEventListener('click', event => {
    const back = event.target.closest('[data-site-back]');
    if (!back || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    let sameSiteReferrer = false;
    try { sameSiteReferrer = new URL(document.referrer).origin === location.origin; } catch { /* Use the page's fallback link. */ }
    if (sameSiteReferrer && history.length > 1) {
        event.preventDefault();
        history.back();
    }
});

// Protect gallery sample text while leaving the user's editable CV accessible.
const isTemplatePreview = target => target instanceof Element && target.closest('.template-preview');
for (const eventName of ['copy', 'cut', 'contextmenu', 'dragstart']) {
    document.addEventListener(eventName, event => {
        const selection = window.getSelection();
        const anchor = selection?.anchorNode;
        if (isTemplatePreview(event.target) || ((eventName === 'copy' || eventName === 'cut') && isTemplatePreview(anchor instanceof Element ? anchor : anchor?.parentElement))) {
            event.preventDefault();
        }
    });
}
