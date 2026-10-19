<?php
declare(strict_types=1);

/**
 * محرك الأغلفة البصرية الذكية خفيفة الوزن للمنصة
 * يوفر أعلى جودة بصرية دون أي عبء تخزيني أو استهلاك لسيرفر المنصة
 */

function get_subject_cover_data(string $name): array
{
    $name = trim($name);
    if (preg_match('/(رياضيات|جبر|هندسة|حساب|تفاضل)/ui', $name)) {
        return [
            'theme' => 'math',
            'gradient' => 'linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%)',
            'icon' => '📐',
            'symbol' => 'π ∑ √',
            'accent' => '#60a5fa',
        ];
    }
    if (preg_match('/(فيزياء|طبيعة)/ui', $name)) {
        return [
            'theme' => 'physics',
            'gradient' => 'linear-gradient(135deg, #0f172a 0%, #1e40af 100%)',
            'icon' => '⚡',
            'symbol' => 'E=mc²',
            'accent' => '#93c5fd',
        ];
    }
    if (preg_match('/(كيمياء)/ui', $name)) {
        return [
            'theme' => 'chemistry',
            'gradient' => 'linear-gradient(135deg, #064e3b 0%, #059669 100%)',
            'icon' => '🧪',
            'symbol' => 'H₂O',
            'accent' => '#34d399',
        ];
    }
    if (preg_match('/(أحياء|بيولوجي|علوم)/ui', $name)) {
        return [
            'theme' => 'biology',
            'gradient' => 'linear-gradient(135deg, #14532d 0%, #16a34a 100%)',
            'icon' => '🧬',
            'symbol' => 'DNA',
            'accent' => '#4ade80',
        ];
    }
    if (preg_match('/(عربي|لغة عربية|بلاغة|نحو|أدب)/ui', $name)) {
        return [
            'theme' => 'arabic',
            'gradient' => 'linear-gradient(135deg, #78350f 0%, #d97706 100%)',
            'icon' => '📖',
            'symbol' => 'ض',
            'accent' => '#fcd34d',
        ];
    }
    if (preg_match('/(إنجليزي|انجليزي|english)/ui', $name)) {
        return [
            'theme' => 'english',
            'gradient' => 'linear-gradient(135deg, #831843 0%, #db2777 100%)',
            'icon' => '🔤',
            'symbol' => 'ABC',
            'accent' => '#f472b6',
        ];
    }
    if (preg_match('/(إسلامي|اسلامي|قرآن|فقه|توحيد|عقيدة|حديث)/ui', $name)) {
        return [
            'theme' => 'islamic',
            'gradient' => 'linear-gradient(135deg, #064e3b 0%, #047857 100%)',
            'icon' => '🕌',
            'symbol' => '۞',
            'accent' => '#6ee7b7',
        ];
    }
    if (preg_match('/(تاريخ|جغرافيا|دراسات)/ui', $name)) {
        return [
            'theme' => 'history',
            'gradient' => 'linear-gradient(135deg, #451a03 0%, #9a3412 100%)',
            'icon' => '🧭',
            'symbol' => '🗺️',
            'accent' => '#fdba74',
        ];
    }
    if (preg_match('/(حاسوب|كمبيوتر|تكنولوجيا|برمجة)/ui', $name)) {
        return [
            'theme' => 'computer',
            'gradient' => 'linear-gradient(135deg, #312e81 0%, #6366f1 100%)',
            'icon' => '💻',
            'symbol' => '</>',
            'accent' => '#a5b4fc',
        ];
    }
    return [
        'theme' => 'general',
        'gradient' => 'linear-gradient(135deg, #1e293b 0%, #3b82f6 100%)',
        'icon' => '📚',
        'symbol' => '★',
        'accent' => '#93c5fd',
    ];
}

function get_quiz_cover_data(string $title): array
{
    return [
        'theme' => 'quiz',
        'gradient' => 'linear-gradient(135deg, #3b0764 0%, #7c3aed 100%)',
        'icon' => '📝',
        'symbol' => '100%',
        'accent' => '#c084fc',
    ];
}

function get_resource_cover_data(string $title, string $mime = ''): array
{
    if (str_contains($mime, 'video')) {
        return [
            'theme' => 'video',
            'gradient' => 'linear-gradient(135deg, #09090b 0%, #1e1b4b 100%)',
            'icon' => '🎬',
            'symbol' => 'HD',
            'accent' => '#818cf8',
        ];
    }
    return [
        'theme' => 'pdf',
        'gradient' => 'linear-gradient(135deg, #7f1d1d 0%, #dc2626 100%)',
        'icon' => '📑',
        'symbol' => 'PDF',
        'accent' => '#f87171',
    ];
}

function get_announcement_cover_data(string $style, string $priority = 'NORMAL'): array
{
    if ($priority === 'URGENT') {
        return [
            'theme' => 'announcement-urgent',
            'gradient' => 'linear-gradient(135deg, #991b1b 0%, #ef4444 100%)',
            'icon' => '🚨',
            'badge' => 'عاجل وهام',
            'accent' => '#fca5a5',
        ];
    }
    if ($priority === 'IMPORTANT') {
        return [
            'theme' => 'announcement-important',
            'gradient' => 'linear-gradient(135deg, #92400e 0%, #f59e0b 100%)',
            'icon' => '⭐',
            'badge' => 'إعلان هام',
            'accent' => '#fde68a',
        ];
    }
    if ($style === 'gradient_emerald') {
        return [
            'theme' => 'announcement-emerald',
            'gradient' => 'linear-gradient(135deg, #065f46 0%, #10b981 100%)',
            'icon' => '🌿',
            'badge' => 'تنبيه أكاديمي',
            'accent' => '#a7f3d0',
        ];
    }
    if ($style === 'gradient_purple') {
        return [
            'theme' => 'announcement-purple',
            'gradient' => 'linear-gradient(135deg, #4c1d95 0%, #8b5cf6 100%)',
            'icon' => '💡',
            'badge' => 'إشعار المنصة',
            'accent' => '#ddd6fe',
        ];
    }
    if ($style === 'gradient_rose') {
        return [
            'theme' => 'announcement-rose',
            'gradient' => 'linear-gradient(135deg, #881337 0%, #f43f5e 100%)',
            'icon' => '🎉',
            'badge' => 'مناسبة مدرسية',
            'accent' => '#fecdd3',
        ];
    }
    return [
        'theme' => 'announcement-blue',
        'gradient' => 'linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%)',
        'icon' => '📢',
        'badge' => 'تنبيه أكاديمي',
        'accent' => '#bfdbfe',
    ];
}
