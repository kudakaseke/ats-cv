<?php

namespace ATSCV;

use Dompdf\Dompdf;
use Dompdf\Options;
use Smalot\PdfParser\Parser;

final class Documents
{
    public static function extract(string $path, string $extension): string
    {
        if (filesize($path) > 5 * 1024 * 1024) {
            throw new \InvalidArgumentException('Document exceeds 5 MB.');
        }
        $prefix = file_get_contents($path, false, null, 0, 5);
        if ($extension === 'pdf' && $prefix === '%PDF-') {
            if (! class_exists('Smalot\\PdfParser\\Parser')) {
                throw new \RuntimeException('PDF import dependency is missing.');
            }

            return trim((new Parser)->parseFile($path)->getText());
        }
        if ($extension !== 'docx' || ! str_starts_with($prefix, 'PK')) {
            throw new \InvalidArgumentException('Only genuine PDF and DOCX documents are supported.');
        }
        $copy = tempnam(sys_get_temp_dir(), 'atscv-');
        if ($copy === false) {
            throw new \RuntimeException('Temporary storage unavailable.');
        }
        $archivePath = $copy.'.zip';
        try {
            if (! rename($copy, $archivePath) || ! copy($path, $archivePath)) {
                throw new \RuntimeException('Document could not be read.');
            }
            $archive = new \PharData($archivePath);
            $size = 0;
            $count = 0;
            foreach (new \RecursiveIteratorIterator($archive) as $entry) {
                $size += $entry->getSize();
                $count++;
                if ($size > 25 * 1024 * 1024 || $count > 1000) {
                    throw new \InvalidArgumentException('DOCX archive is too large.');
                }
                if (preg_match('/vbaProject|\.bin$/i', $entry->getFilename())) {
                    throw new \InvalidArgumentException('Embedded executable content is not supported.');
                }
            }
            if (! isset($archive['word/document.xml'])) {
                throw new \InvalidArgumentException('Not a Word document.');
            }
            $xml = $archive['word/document.xml']->getContent();
            if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
                throw new \InvalidArgumentException('Unsafe Word document.');
            }
            $doc = new \DOMDocument;
            $previous = libxml_use_internal_errors(true);
            try {
                if (! $doc->loadXML($xml, LIBXML_NONET)) {
                    throw new \InvalidArgumentException('Invalid Word content.');
                }
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            $xpath = new \DOMXPath($doc);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $lines = [];
            foreach ($xpath->query('//w:p') as $paragraph) {
                $line = '';
                foreach ($xpath->query('.//w:t|.//w:tab|.//w:br', $paragraph) as $node) {
                    $line .= $node->localName === 't' ? $node->textContent : ' ';
                }
                $lines[] = $line;
            }

            return trim(implode("\n", $lines));
        } finally {
            unset($archive);
            if (is_file($archivePath)) {
                unlink($archivePath);
            } if (is_file($copy)) {
                unlink($copy);
            }
        }
    }

    public static function sections(array $cv, array $template): array
    {
        $labels = ['summary' => 'Professional Summary', 'experience' => 'Professional Experience', 'skills' => 'Skills', 'core_strengths' => 'Strengths and Competencies', 'education' => 'Education', 'courses' => 'Certifications and Courses', 'achievements' => 'Achievements', 'languages' => 'Languages', 'references' => 'References', 'custom_sections' => 'Additional Information'];
        $labels = array_replace($labels, $template['section_labels'] ?? [], $cv['labels'] ?? []);
        $order = array_values(array_unique(array_merge($cv['section_order'] ?: ($template['section_order'] ?? []), array_keys($labels))));
        $sections = [];
        foreach ($order as $section) {
            if (empty($cv[$section])) {
                continue;
            }
            if (isset(Resume::ROWS[$section])) {
                $blocks = [];
                foreach ($cv[$section] as $row) {
                    $title = implode(' | ', array_filter(array_map(static fn ($key) => $row[$key] ?? '', match ($section) {
                        'experience' => ['title', 'company'], 'education' => ['degree', 'school'], 'courses' => ['title', 'institution'], 'languages' => ['name', 'level'], default => ['title']
                    })));
                    $details = implode("\n", array_filter(array_intersect_key($row, array_flip(['period', 'location', 'grade', 'description', 'content']))));
                    if ($title || $details) {
                        $blocks[] = ['title' => $title, 'text' => $details];
                    }
                }
                if ($blocks) {
                    $sections[] = ['heading' => $labels[$section], 'blocks' => $blocks];
                }
            } else {
                $sections[] = ['heading' => $labels[$section], 'blocks' => [['title' => '', 'text' => $cv[$section]]]];
            }
        }

        return $sections;
    }

    public static function html(array $cv, string $templateId): string
    {
        $templates = array_column(Resume::templates(), null, 'id');
        $template = $templates[$templateId] ?? reset($templates);
        $accent = preg_match('/^#[a-f0-9]{6}$/i', $template['accent'] ?? '') ? $template['accent'] : '#173f53';
        $serif = ($template['font'] ?? '') === 'serif';
        $html = '<!doctype html><html><head><meta charset="utf-8"><style>@page{margin:18mm}body{font-family:'.($serif ? 'DejaVu Serif' : 'DejaVu Sans').';font-size:10.5pt;color:#172b3a;line-height:1.45}h1{font-size:24pt;margin:0 0 4pt;color:'.$accent.'}h2{font-size:12pt;color:'.$accent.';border-bottom:1pt solid '.$accent.';margin:16pt 0 6pt;padding-bottom:3pt;page-break-after:avoid}h3{font-size:10.5pt;margin:10pt 0 3pt;page-break-after:avoid}p{margin:0 0 7pt}header{border-bottom:2pt solid '.$accent.';padding-bottom:10pt;margin-bottom:12pt}a{color:'.$accent.'}.raw{white-space:pre-wrap}</style></head><body>';
        if (! empty($cv['raw_text'])) {
            return $html.'<div class="raw">'.nl2br(esc_html($cv['raw_text'])).'</div></body></html>';
        }
        $html .= '<header><h1>'.esc_html($cv['name']).'</h1><p>'.esc_html($cv['role']).'</p><p>'.esc_html(implode(' | ', array_filter(array_intersect_key($cv, array_flip(['email', 'phone', 'location', 'website']))))).'</p></header>';
        foreach (self::sections($cv, $template) as $section) {
            $html .= '<section><h2>'.esc_html($section['heading']).'</h2>';
            foreach ($section['blocks'] as $block) {
                $html .= ($block['title'] ? '<h3>'.esc_html($block['title']).'</h3>' : '').'<p>'.nl2br(esc_html($block['text'])).'</p>';
            }
            $html .= '</section>';
        }

        return $html.'</body></html>';
    }

    public static function pdf(array $cv, string $template): string
    {
        if (! class_exists('Dompdf\\Dompdf')) {
            throw new \RuntimeException('PDF export dependency is missing.');
        }
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot(ATSCV_DIR);
        $options->setDefaultFont('DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml(self::html($cv, $template), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return $pdf->output();
    }

    public static function zip(array $files, bool $compress = false): string
    {
        $body = '';
        $directory = '';
        foreach ($files as $name => $content) {
            $offset = strlen($body);
            $size = strlen($content);
            $crc = crc32($content);
            $payload = $compress ? gzdeflate($content, 6) : $content;
            if ($payload === false) {
                throw new \RuntimeException('Archive compression failed.');
            }
            $method = $compress ? 8 : 0;
            $compressedSize = strlen($payload);
            $body .= pack('VvvvvvVVVvv', 0x04034B50, 20, 0, $method, 0, 33, $crc, $compressedSize, $size, strlen($name), 0).$name.$payload;
            $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0, $method, 0, 33, $crc, $compressedSize, $size, strlen($name), 0, 0, 0, 0, 0, $offset).$name;
        }

        return $body.$directory.pack('VvvvvVVv', 0x06054B50, 0, 0, count($files), count($files), strlen($directory), strlen($body), 0);
    }

    public static function docx(array $cv, string $templateId): string
    {
        $templates = array_column(Resume::templates(), null, 'id');
        $template = $templates[$templateId] ?? reset($templates);
        $paragraph = static function (string $text, bool $bold = false, int $size = 22): string {
            $runs = [];
            foreach (preg_split('/\R/u', $text) as $line) {
                $runs[] = '<w:r><w:rPr>'.($bold ? '<w:b/>' : '').'<w:sz w:val="'.$size.'"/></w:rPr><w:t xml:space="preserve">'.htmlspecialchars($line, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</w:t></w:r>';
            }

            return '<w:p>'.implode('<w:r><w:br/></w:r>', $runs).'</w:p>';
        };
        if (! empty($cv['raw_text'])) {
            $body = $paragraph($cv['raw_text']);
        } else {
            $body = $paragraph($cv['name'], true, 40).$paragraph($cv['role']).$paragraph(implode(' | ', array_filter(array_intersect_key($cv, array_flip(['email', 'phone', 'location', 'website'])))));
            foreach (self::sections($cv, $template) as $section) {
                $body .= $paragraph($section['heading'], true, 26);
                foreach ($section['blocks'] as $block) {
                    $body .= ($block['title'] ? $paragraph($block['title'], true) : '').$paragraph($block['text']);
                }
            }
        }
        $body .= '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1020" w:right="1020" w:bottom="1020" w:left="1020"/></w:sectPr>';

        return self::zip([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>',
        ]);
    }
}
