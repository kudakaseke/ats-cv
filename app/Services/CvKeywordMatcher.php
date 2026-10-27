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
        // These words distinguish real requirements (team management, technical
        // support, software development); dropping them creates false matches.
        foreach (['team', 'teams', 'support', 'development'] as $meaningful) unset($stopWords[$meaningful]);
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
            '/\bteam\s+(?:player|work)\b/iu' => 'teamwork',
            '/\bdetail.oriented\b/iu' => 'attention to detail',
            '/\bPython\s*\(Programming Language\)/iu' => 'Python',
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
                'teams' => 'team',
                'networks' => 'network',
                'servers' => 'server',
                'firewalls' => 'firewall',
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
        $similarity = $this->similarity($this->terms($description), $this->terms($resume));
        $parser = new CvJobRequirements;
        $requirements = $parser->extract($description, $this);
        $source = 'requirements';
        // Preserve concise skill-list input, but never use an entire unstructured
        // advert as a bag of keywords when no requirements were recognised.
        if (! $requirements && ! preg_match('/[\r\n]/', trim($description)) && mb_strlen($description) <= 180) {
            $terms = $this->tokens($description);
            $technical = '/^(?:PHP|HTML|CSS|JavaScript|TypeScript|SQL|MySQL|Python|Java|C#|C\+\+|\.NET|Excel|Word|AWS|Git|reporting|budgeting|REST|API|node\.js)$/i';
            $list = count($terms) && ! array_filter($terms, fn ($term) => ! preg_match($technical, $term));
            if ($list) {
                $requirements = array_map(fn ($term) => ['category' => 'skills', 'requirement' => $term, 'importance' => 'required', 'weight' => 2], array_values($terms));
                $source = 'explicit_skill_list';
            }
        }
        $result = $parser->compare($requirements, $resume, $this);
        return [...$result,
            'score' => (int) round($similarity * 100), 'similarity' => round($similarity, 8),
            'similarity_percent' => round($similarity * 100, 2), 'method' => 'tfidf-cosine-v1',
            'keyword_source' => $requirements ? $source : 'none',
            'suggestions' => array_map(fn ($phrase) => ['keyword' => $phrase, 'suggestion' => 'Show evidence of '.$phrase.' in the appropriate CV section where your background supports it.'], $result['missing']),
            'experience_check' => (new CvExperienceMatcher)->compare($description, $resume),
        ];
    }
}
