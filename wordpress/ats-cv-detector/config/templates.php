<?php

$templates = [
    ['id' => 'double-column', 'name' => 'Double Column', 'layout' => 'double', 'accent' => '#2789ed', 'font' => 'sans', 'columns' => 2, 'category' => 'Modern'],
    ['id' => 'classic', 'name' => 'Classic', 'layout' => 'single', 'accent' => '#172b4d', 'font' => 'serif', 'columns' => 1, 'category' => 'Traditional'],
    ['id' => 'elegant', 'name' => 'Elegant', 'layout' => 'sidebar', 'accent' => '#233e56', 'font' => 'sans', 'columns' => 2, 'category' => 'Modern'],
    ['id' => 'crest', 'name' => 'Crest', 'layout' => 'centered', 'accent' => '#73634c', 'font' => 'serif', 'columns' => 1, 'category' => 'Traditional'],
    ['id' => 'minimal', 'name' => 'Minimal', 'layout' => 'single', 'accent' => '#384552', 'font' => 'sans', 'columns' => 1, 'category' => 'Simple'],
    ['id' => 'executive', 'name' => 'Executive', 'layout' => 'double', 'accent' => '#20375e', 'font' => 'serif', 'columns' => 2, 'category' => 'Traditional'],
    ['id' => 'studio', 'name' => 'Studio', 'layout' => 'banner', 'accent' => '#7455ba', 'font' => 'sans', 'columns' => 1, 'category' => 'Creative'],
    ['id' => 'student', 'name' => 'Student', 'layout' => 'single', 'accent' => '#346de1', 'font' => 'sans', 'columns' => 1, 'category' => 'Simple', 'section_order' => ['education', 'skills', 'experience', 'custom_sections', 'achievements', 'courses', 'languages']],
    ['id' => 'graduate', 'name' => 'Graduate', 'layout' => 'single', 'accent' => '#346de1', 'font' => 'sans', 'columns' => 1, 'category' => 'Simple'],
    ['id' => 'horizon', 'name' => 'Horizon', 'layout' => 'sidebar', 'accent' => '#2563a8', 'font' => 'sans', 'columns' => 2, 'category' => 'Modern'],
    ['id' => 'editorial', 'name' => 'Editorial', 'layout' => 'centered', 'accent' => '#983f48', 'font' => 'serif', 'columns' => 2, 'category' => 'Creative'],
    ['id' => 'precision', 'name' => 'Precision', 'layout' => 'double', 'accent' => '#283e74', 'font' => 'sans', 'columns' => 2, 'category' => 'Modern'],
    ['id' => 'heritage', 'name' => 'Heritage', 'layout' => 'single', 'accent' => '#654d40', 'font' => 'serif', 'columns' => 1, 'category' => 'Traditional'],
    ['id' => 'nordic', 'name' => 'Nordic', 'layout' => 'banner', 'accent' => '#46658c', 'font' => 'sans', 'columns' => 2, 'category' => 'Simple'],
    ['id' => 'momentum', 'name' => 'Momentum', 'layout' => 'double', 'accent' => '#b44f32', 'font' => 'sans', 'columns' => 2, 'category' => 'Creative'],
    ['id' => 'scholar', 'name' => 'Scholar', 'layout' => 'centered', 'accent' => '#313d59', 'font' => 'serif', 'columns' => 1, 'category' => 'Traditional'],
    ['id' => 'signal', 'name' => 'Signal', 'layout' => 'banner', 'accent' => '#226dab', 'font' => 'sans', 'columns' => 2, 'category' => 'Modern'],
    ['id' => 'linen', 'name' => 'Linen', 'layout' => 'centered', 'accent' => '#927556', 'font' => 'sans', 'columns' => 2, 'category' => 'Simple'],
    ['id' => 'portfolio', 'name' => 'Portfolio', 'layout' => 'sidebar', 'accent' => '#8b3d68', 'font' => 'sans', 'columns' => 2, 'category' => 'Creative'],
    ['id' => 'essential', 'name' => 'Essential', 'layout' => 'single', 'accent' => '#1e293b', 'font' => 'sans', 'columns' => 1, 'category' => 'Simple'],
    ['id' => 'ats-serif', 'name' => 'ATS Classic', 'layout' => 'ats-serif', 'accent' => '#111111', 'font' => 'serif', 'columns' => 1, 'category' => 'ATS Pass'],
    ['id' => 'ats-compact', 'name' => 'ATS Compact', 'layout' => 'ats-compact', 'accent' => '#111111', 'font' => 'sans', 'columns' => 1, 'category' => 'ATS Pass'],
    ['id' => 'ats-professional', 'name' => 'ATS Professional', 'layout' => 'ats-professional', 'accent' => '#111111', 'font' => 'sans', 'columns' => 1, 'category' => 'ATS Pass'],
    // Position-only layouts recreated from the visual structures of Jobscan examples.
    ['id' => 'position-classic', 'name' => 'Position Classic', 'layout' => 'position-classic', 'accent' => '#333333', 'font' => 'serif', 'columns' => 1, 'category' => 'By Position'],
    ['id' => 'position-academic', 'name' => 'Position Academic', 'layout' => 'position-academic', 'accent' => '#333333', 'font' => 'sans', 'columns' => 1, 'category' => 'By Position'],
    ['id' => 'position-centred', 'name' => 'Position Centred', 'layout' => 'position-centred', 'accent' => '#333333', 'font' => 'sans', 'columns' => 1, 'category' => 'By Position'],
    ['id' => 'position-modern', 'name' => 'Position Modern', 'layout' => 'position-modern', 'accent' => '#333333', 'font' => 'sans', 'columns' => 1, 'category' => 'By Position'],
    ['id' => 'position-minimal', 'name' => 'Position Minimal', 'layout' => 'position-minimal', 'accent' => '#333333', 'font' => 'sans', 'columns' => 1, 'category' => 'By Position'],
    ['id' => 'position-software-developer', 'name' => 'Software Developer', 'layout' => 'position-modern', 'accent' => '#333333', 'font' => 'sans', 'columns' => 1, 'category' => 'By Position', 'section_order' => ['experience', 'skills', 'education', 'courses'], 'hide_role' => false, 'uppercase_name' => false],
    ['id' => 'position-customer-service', 'name' => 'Customer Service', 'layout' => 'position-centred', 'accent' => '#333333', 'font' => 'sans', 'columns' => 1, 'category' => 'By Position', 'section_order' => ['experience', 'skills', 'education'], 'hide_role' => false, 'uppercase_name' => false],
    ['id' => 'position-network-engineer', 'name' => 'Network Engineer', 'layout' => 'position-academic', 'accent' => '#333333', 'font' => 'sans', 'columns' => 1, 'category' => 'By Position', 'section_order' => ['education', 'custom_sections', 'experience', 'skills'], 'hide_role' => true, 'uppercase_name' => false],
    ['id' => 'position-medical-assistant', 'name' => 'Medical Assistant', 'layout' => 'position-academic', 'accent' => '#333333', 'font' => 'sans', 'columns' => 1, 'category' => 'By Position', 'section_order' => ['education', 'experience', 'skills'], 'hide_role' => false, 'uppercase_name' => true],
    ['id' => 'position-human-resources', 'name' => 'Human Resources', 'layout' => 'position-minimal', 'accent' => '#333333', 'font' => 'sans', 'columns' => 1, 'category' => 'By Position', 'section_order' => ['experience', 'skills', 'education'], 'hide_role' => true, 'uppercase_name' => true],
    ['id' => 'position-product-manager', 'name' => 'Product Manager', 'layout' => 'position-modern', 'accent' => '#333333', 'font' => 'sans', 'columns' => 1, 'category' => 'By Position', 'section_order' => ['experience', 'education'], 'hide_role' => false, 'uppercase_name' => true],
    ['id' => 'position-nurse', 'name' => 'Nurse', 'layout' => 'position-modern', 'accent' => '#333333', 'font' => 'sans', 'columns' => 1, 'category' => 'By Position', 'section_order' => ['experience', 'skills', 'education'], 'hide_role' => false, 'uppercase_name' => true],
    ['id' => 'position-project-manager', 'name' => 'Project Manager', 'layout' => 'position-minimal', 'accent' => '#333333', 'font' => 'sans', 'columns' => 1, 'category' => 'By Position', 'section_order' => ['experience', 'skills', 'education', 'courses'], 'hide_role' => false, 'uppercase_name' => false],
];


$profiles = require __DIR__.'/cv_department_profiles.php';
foreach (require __DIR__.'/cv_departments.php' as $department) {
    $profile = $profiles[$department['id']];
    $templates[] = [
        'id' => $department['template'], 'name' => $department['title'],
        'category' => 'By Department', 'layout' => 'department-ats',
        'font' => 'sans', 'columns' => 1, 'accent' => '#111111',
        'section_order' => $profile['order'],
        'section_labels' => ['summary' => 'Professional Summary', 'experience' => 'Professional Experience', 'skills' => $profile['skills'], 'education' => 'Education', 'courses' => $profile['courses'], 'achievements' => $profile['achievements']],
        'department_profile' => $profile,
    ];
}

return $templates;
