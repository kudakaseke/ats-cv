<?php

namespace App\Services;

class CvWordWriter
{
    private float $scale = 1;

    private function xml(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function space(float $pixels): int
    {
        return max(0, (int) round($pixels * $this->scale * 15));
    }

    private function run(array $run): string
    {
        $style = $run['style'];
        $font = $this->xml($style['font'] ?? 'Arial');
        $size = max(2, (int) round(($style['size'] ?? 13) * $this->scale * 1.5));
        $properties = '<w:rFonts w:ascii="'.$font.'" w:hAnsi="'.$font.'"/><w:sz w:val="'.$size.'"/><w:szCs w:val="'.$size.'"/>';
        if (! empty($style['bold'])) {
            $properties .= '<w:b/>';
        }
        if (! empty($style['italic'])) {
            $properties .= '<w:i/>';
        }
        if (! empty($style['color'])) {
            $properties .= '<w:color w:val="'.$style['color'].'"/>';
        }
        $lines = preg_split('/\R/u', $run['text']);
        $text = implode('<w:br/>', array_map(fn ($line) => '<w:t xml:space="preserve">'.$this->xml($line).'</w:t>', $lines));

        return '<w:r><w:rPr>'.$properties.'</w:rPr>'.$text.'</w:r>';
    }

    private function paragraph(array $block): string
    {
        $style = $block['style'];
        $align = in_array($style['align'] ?? '', ['center', 'right', 'justify'], true) ? $style['align'] : 'left';
        if ($align === 'justify') {
            $align = 'both';
        }
        $properties = '<w:jc w:val="'.$align.'"/><w:widowControl w:val="0"/>';
        $properties .= '<w:spacing w:before="'.$this->space(max(0, $style['before'] ?? 0)).'" w:after="'.$this->space($style['after'] ?? 0).'" w:line="'.max(1, $this->space($style['line'] ?? 16)).'" w:lineRule="exact"/>';
        if (! empty($block['heading'])) {
            $properties .= '<w:keepNext/>';
        }
        if (! empty($style['border'])) {
            $properties .= '<w:pBdr><w:bottom w:val="single" w:sz="4" w:space="2" w:color="'.$style['border'].'"/></w:pBdr>';
        }
        $runs = '';
        if (! empty($block['bullet'])) {
            $properties .= '<w:ind w:left="180" w:hanging="140"/><w:tabs><w:tab w:val="left" w:pos="180"/></w:tabs>';
            $runs .= $this->run(['text' => "\u{2022} ", 'style' => $style]);
        }
        foreach ($block['runs'] as $run) {
            $runs .= $this->run($run);
        }

        return '<w:p><w:pPr>'.$properties.'</w:pPr>'.$runs.'</w:p>';
    }

    private function blocks(array $blocks, int $width): string
    {
        $xml = '';
        foreach ($blocks as $block) {
            if ($block['type'] === 'pageBreak') {
                $xml .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';

                continue;
            }
            if ($block['type'] === 'paragraph') {
                $xml .= $this->paragraph($block);

                continue;
            }
            $cells = $block['cells'];
            if (! $cells) {
                continue;
            }
            $total = array_sum(array_column($cells, 'width')) ?: 1;
            $tableWidth = isset($block['width']) ? min($width, max(1, $this->space($block['width']))) : $width;
            $widths = array_map(fn ($cell) => max(1, (int) floor($tableWidth * $cell['width'] / $total)), $cells);
            $xml .= '<w:tbl><w:tblPr><w:tblW w:w="'.$tableWidth.'" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders></w:tblPr><w:tblGrid>';
            foreach ($widths as $cellWidth) {
                $xml .= '<w:gridCol w:w="'.$cellWidth.'"/>';
            }
            $xml .= '</w:tblGrid><w:tr><w:trPr><w:cantSplit/></w:trPr>';
            foreach ($cells as $index => $cell) {
                $style = $cell['style'];
                $padding = $style['padding'] ?? [0, 0, 0, 0];
                $margins = '';
                foreach (['top' => 0, 'right' => 1, 'bottom' => 2, 'left' => 3] as $side => $offset) {
                    $margins .= '<w:'.$side.' w:w="'.$this->space($padding[$offset]).'" w:type="dxa"/>';
                }
                $shading = ! empty($style['background']) ? '<w:shd w:val="clear" w:fill="'.$style['background'].'"/>' : '';
                $cellWidth = $widths[$index];
                $innerWidth = max(100, $cellWidth - $this->space($padding[1] + $padding[3]));
                $content = $this->blocks($cell['blocks'], $innerWidth);
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="'.$cellWidth.'" w:type="dxa"/><w:vAlign w:val="top"/><w:tcMar>'.$margins.'</w:tcMar>'.$shading.'</w:tcPr>'.$content.'<w:p><w:pPr><w:spacing w:after="0" w:line="1" w:lineRule="exact"/></w:pPr></w:p></w:tc>';
            }
            $xml .= '</w:tr></w:tbl>';
        }

        return $xml;
    }

    public function write(array $document, string $path): void
    {
        // Leave a small reflow allowance for Word's font metrics.
        $this->scale = $document['scale'] * (! empty($document['paginated']) ? 1 : 0.94);
        $page = $document['page'];
        $width = (int) round($page['width'] * 1440 / 25.4);
        $height = (int) round($page['height'] * 1440 / 25.4);
        $padding = array_map(fn ($pixels) => (int) round($pixels * 15), $page['padding']);
        $body = $this->blocks($document['blocks'], $width - $padding[1] - $padding[3]);
        $section = '<w:sectPr><w:pgSz w:w="'.$width.'" w:h="'.$height.'"/><w:pgMar w:top="'.$padding[0].'" w:right="'.$padding[1].'" w:bottom="'.$padding[2].'" w:left="'.$padding[3].'" w:header="0" w:footer="0"/></w:sectPr>';
        $archivePath = $path.'.zip';
        try {
            $archive = [];
            $archive['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>';
            $archive['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>';
            $archive['word/document.xml'] = '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.$section.'</w:body></w:document>';
            $this->writeZip($archive, $archivePath);
            if (! rename($archivePath, $path)) {
                throw new \RuntimeException('The Word document could not be written.');
            }
        } finally {
            unset($archive);
            if (is_file($archivePath)) {
                unlink($archivePath);
            }
        }
    }

    private function writeZip(array $files, string $path): void
    {
        // Word rejects the Unix metadata extra fields emitted by PharData on
        // some Windows installations. Write a standard OPC ZIP without extras.
        $body = '';
        $directory = '';
        foreach ($files as $name => $content) {
            $offset = strlen($body);
            $size = strlen($content);
            $crc = crc32($content);
            $body .= pack('VvvvvvVVVvv', 0x04034B50, 20, 0, 0, 0, 33, $crc, $size, $size, strlen($name), 0).$name.$content;
            $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0, 0, 0, 33, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $offset).$name;
        }
        file_put_contents($path, $body.$directory.pack('VvvvvVVv', 0x06054B50, 0, 0, count($files), count($files), strlen($directory), strlen($body), 0));
    }
}
