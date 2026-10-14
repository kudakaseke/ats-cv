import './bootstrap';

import Alpine from 'alpinejs';
import {
    createIcons, Asterisk, ArrowUpRight, ArrowRight, ArrowLeft, ShieldCheck,
    Sparkles, FileText, Check, FileCheck2, Download, Upload, Text,
    ChartNoAxesCombined, ScanText, GitCompareArrows, WandSparkles,
    FilePenLine, LayoutTemplate, FolderOpen, History, Info, Zap, Leaf,
    Plus, Menu, LayoutDashboard, FilePlus2, Gem, UserRound, LogOut,
    CircleHelp, ChevronDown, ChevronRight, ArrowDownRight, Target, Files,
} from 'lucide';

const icons = { Asterisk, ArrowUpRight, ArrowRight, ArrowLeft, ShieldCheck,
    Sparkles, FileText, Check, FileCheck2, Download, Upload, Text,
    ChartNoAxesCombined, ScanText, GitCompareArrows, WandSparkles,
    FilePenLine, LayoutTemplate, FolderOpen, History, Info, Zap, Leaf,
    Plus, Menu, LayoutDashboard, FilePlus2, Gem, UserRound, LogOut,
    CircleHelp, ChevronDown, ChevronRight, ArrowDownRight, Target, Files };

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
