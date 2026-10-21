<?php

namespace App\Services;

class CvKeywordMatcher
{
    private const STOP_WORDS = 'a an and are as at be been being but by can could did do does doing for from had has have having he her here hers herself him himself his how i if in into is it its itself may me might more most must my myself no nor not of on once only or other our ours ourselves out over own same shall she should so some such than that the their theirs them themselves then there these they this those through to too under until up us very was we were what when where which while who whom why will with would you your yours yourself yourselves about across after all also any applicants apply application applications based candidate candidates company currently description duties employer employment etc excellent experience experienced following good including include includes job jobs looking need needs new opportunity position preferred preferable qualification qualifications required require requires requirements responsibilities responsibility role seek seeking successful team teams work working works year years ability able strong using use used knowledge understanding please relevant skills skill minimum essential desirable welcome ideal join ensure demonstrate proven supporting support develop developing development responsible various within well least plus seeking hiring vacancy title location salary benefits offer offers receive equal';

    private const LABELS = ['php' => 'PHP', 'html' => 'HTML', 'css' => 'CSS', 'javascript' => 'JavaScript', 'typescript' => 'TypeScript', 'c#' => 'C#', 'c++' => 'C++', '.net' => '.NET', 'sql' => 'SQL', 'mysql' => 'MySQL', 'python' => 'Python', 'node.js' => 'Node.js', 'api' => 'API', 'rest' => 'REST', 'aws' => 'AWS', 'git' => 'Git'];

    public function terms(string $text): array
    {
        $aliases = ['js' => 'javascript', 'html5' => 'html', 'css3' => 'css', 'ts' => 'typescript', 'csharp' => 'c#', 'cplusplus' => 'c++', 'dotnet' => '.net', 'apis' => 'api', 'nodejs' => 'node.js'];
        preg_match_all('/\.[\p{L}]+|[\p{L}][\p{L}\p{N}]*(?:[.+][\p{L}\p{N}]+)*(?:\+\+|#)?/u', mb_strtolower($text), $matches);
        $terms = [];
        $stopWords = array_fill_keys(explode(' ', self::STOP_WORDS.' sound better best effectively effective efficiently efficient highly very ideally willing demonstrate demonstrated capabilities capability proficient proficiency basic advanced level levels'), true);
        foreach ($matches[0] as $word) {
            $word = $aliases[$word] ?? $word;
            if (isset($stopWords[$word]) || (mb_strlen($word) < 2 && ! in_array($word, ['c', 'r'], true))) {
                continue;
            }
            $terms[] = $word;
        }

        return $terms;
    }

    public function tokens(string $text): array
    {
        $evidence = [];
        if (preg_match('/\b(?:authored|wrote|developed|drafted)\b.{0,90}\b(?:manuals?|reports?|publications?|briefs?)\b/iu', $text)) {
            $evidence[] = 'Document drafting Document preparation Written communication';
        }
        if (preg_match('/\b(?:contributed?|contribute|published)\b.{0,80}\bpublications?\b/iu', $text)) {
            $evidence[] = 'Written communication';
        }
        if (preg_match('/\b(?:trained\s+\d+|delivered training|facilitated workshops|gave presentations)\b/iu', $text)) {
            $evidence[] = 'Oral communication';
        }
        if (preg_match('/\b(?:coordinated|coordinating)\b.{0,70}\b(?:NGOs?|CSOs?|partners|stakeholders|organizations|organisations)\b|stakeholder relationship management/iu', $text)) {
            $evidence[] = 'Stakeholder collaboration Interpersonal skills';
        }
        if (preg_match('/\bcommunity development\b/iu', $text)) {
            $evidence[] = 'Community engagement';
        }
        if (preg_match('/\b(?:programme?|project) management\b/iu', $text)) {
            $evidence[] = 'Planning organising';
        }
        $text .= ' '.implode(' ', $evidence);
        $phrases = [
            '/\bverbal\s+communications?\b/iu' => 'oral communication',
            '/\bbachelor[\x{0027}’]?s?\s+(?:degree\s+)?(?:of\s+|in\s+)?/iu' => 'bachelor degree ',
            '/\bbcom\b/iu' => 'bachelor degree commerce',
            '/\bcascading\s+style\s+sheets?\b/iu' => 'CSS',
            '/\bhypertext\s+markup\s+language\b/iu' => 'HTML',
            '/\bstructured\s+query\s+language\b/iu' => 'SQL',
            '/\b(?:microsoft|ms)\s+(excel|word|powerpoint|outlook)\b/iu' => '$1',
            '/\bcustomer\s+relationship\s+management\b/iu' => 'CRM',
            '/\bsearch\s+engine\s+optimisation\b|\bsearch\s+engine\s+optimization\b/iu' => 'SEO',
        ];
        $text = preg_replace(array_keys($phrases), array_values($phrases), $text);
        $words = [];
        foreach ($this->terms($text) as $word) {
            $word = match ($word) {
                'report', 'reports' => 'reporting',
                'budget', 'budgets' => 'budgeting',
                'communications' => 'communication',
                'identifying', 'identified', 'identifies' => 'identify',
                'opportunities' => 'opportunity',
                'adaptable', 'flexibility', 'flexible' => 'adaptability',
                'organizing', 'organization', 'organisation' => 'organising',
                'analyse', 'analyze', 'analyses' => 'analysis',
                default => $word,
            };
            $words[$word] = self::LABELS[$word] ?? $word;
        }

        return $words;
    }

    private function requirements(string $description): ?string
    {
        $collect = false;
        $lines = [];
        foreach (preg_split('/\R/u', $description) as $line) {
            $heading = trim($line, " \t#*");
            $heading = preg_replace('/^\d+[.)]\s*/u', '', $heading);
            if (preg_match('/^\s*(?:minimum requirements|key requirements|requirements|required qualifications|qualifications|qualifications and experience|experience and qualifications|skills and experience|person specification|essential criteria|required skills|essential skills|technical skills|what you bring|what we are looking for)\s*(?::\s*(.*))?$/iu', $heading, $match)) {
                $collect = true;
                if (trim($match[1] ?? '') !== '') {
                    $lines[] = $match[1];
                }
            } elseif ($collect && preg_match('/^\s*(?:benefits|about us|about the company|company overview|application process|equal opportunity|equal opportunities|diversity|how to apply|responsibilities|duties|salary|what we offer)\s*(?::.*)?$/iu', $heading)) {
                $collect = false;
            } elseif ($collect) {
                $lines[] = $line;
            }
        }
        $text = trim(implode("\n", $lines));

        return $text !== '' ? $text : null;
    }

    private function similarity(array $jobTerms, array $resumeTerms): float
    {
        $jobCounts = array_count_values($jobTerms);
        $resumeCounts = array_count_values($resumeTerms);
        $dot = 0.0;
        $jobNorm = 0.0;
        $resumeNorm = 0.0;
        foreach (array_unique([...array_keys($jobCounts), ...array_keys($resumeCounts)]) as $term) {
            $df = (int) isset($jobCounts[$term]) + (int) isset($resumeCounts[$term]);
            // Smoothed IDF and raw term frequency, equivalent to sklearn's default TF-IDF weighting.
            $idf = log(3 / (1 + $df)) + 1;
            $job = ($jobCounts[$term] ?? 0) * $idf;
            $resume = ($resumeCounts[$term] ?? 0) * $idf;
            $dot += $job * $resume;
            $jobNorm += $job * $job;
            $resumeNorm += $resume * $resume;
        }

        return $jobNorm > 0 && $resumeNorm > 0 ? max(0.0, min(1.0, $dot / sqrt($jobNorm * $resumeNorm))) : 0.0;
    }

    public function compare(string $description, string $resume): array
    {
        $requirements = $this->requirements($description);
        $keywords = $this->tokens($requirements ?? $description);
        $resumeWords = $this->tokens($resume);
        $matched = array_intersect_key($keywords, $resumeWords);
        $missing = array_diff_key($keywords, $resumeWords);
        $similarity = $this->similarity($this->terms($description), $this->terms($resume));
        $counts = array_count_values($this->terms($requirements ?? $description));
        uksort($missing, fn ($a, $b) => ($counts[$b] ?? 0) <=> ($counts[$a] ?? 0));
        $suggestions = [];
        foreach ($missing as $word) {
            $suggestions[] = ['keyword' => $word, 'suggestion' => 'Use '.$word.' in Skills, Summary or a responsibility only where your experience supports it; otherwise identify it as a skill gap.'];
        }

        $result = [
            'score' => (int) round($similarity * 100), 'similarity' => round($similarity, 8),
            'similarity_percent' => round($similarity * 100, 2), 'method' => 'tfidf-cosine-v1',
            'keyword_score' => count($keywords) ? (int) round(count($matched) / count($keywords) * 100) : 0,
            'keyword_source' => $requirements !== null ? 'requirements' : 'job_description',
            'total' => count($keywords), 'matched' => array_values($matched), 'missing' => array_values($missing),
            'suggestions' => $suggestions,
            'experience_check' => (new CvExperienceMatcher)->compare($description, $resume),
        ];
        $requirements = (new CvJobRequirements)->extract($description, $this);
        if ($requirements) {
            $result = [...$result, ...(new CvJobRequirements)->compare($requirements, $resume, $this)];
            $result['keyword_source'] = 'requirements';
            $result['suggestions'] = array_map(fn ($phrase) => ['keyword' => $phrase, 'suggestion' => 'Show evidence of '.$phrase.' in the appropriate CV section where your background supports it.'], $result['missing']);
        }

        return $result;
    }
}
