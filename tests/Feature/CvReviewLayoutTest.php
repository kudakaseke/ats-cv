<?php
namespace Tests\Feature;

use Tests\TestCase;

class CvReviewLayoutTest extends TestCase
{
    public function test_review_sections_have_the_requested_order_and_keep_gaps_separate(): void
    {
        $comparison = [
            'changes' => ['Clarified bookkeeping duties.'], 'added_keywords' => ["\u{F06C} Bookkeeping"],
            'original_text' => 'Original <script>alert(1)</script>', 'revised_text' => 'Tailored bookkeeping CV',
            'warnings' => ['Employment months need confirmation.'],
            'assessment' => ['requirements' => [
                ['requirement' => 'Bookkeeping', 'revamped_status' => 'matched'],
                ['requirement' => 'Accounting software', 'revamped_status' => 'partially_matched'],
                ['requirement' => 'Professional registration', 'revamped_status' => 'not_evidenced'],
            ], 'recommendations' => ['Provide a genuine software example.'], 'warnings' => [
                ['severity' => 'high', 'requirement' => 'Professional registration', 'explanation' => 'Not evidenced.', 'recommended_action' => 'Confirm if held.'],
            ]],
        ];
        $html = view('workspace.partials.revamp-review', compact('comparison'))->render();
        $dom = new \DOMDocument; @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $order = [];
        foreach ($xpath->query('//details[@data-review-section]') as $node) $order[] = $node->getAttribute('data-review-section');
        $this->assertSame(['changes', 'recommendations', 'met', 'missing', 'comparison', 'warnings'], $order);
        $met = $xpath->query('//details[@data-review-section="met"]')->item(0)->textContent;
        $missing = $xpath->query('//details[@data-review-section="missing"]')->item(0)->textContent;
        $this->assertStringContainsString('Bookkeeping', $met);
        $this->assertStringNotContainsString('Accounting software', $met);
        $this->assertStringContainsString('Requirements still missing (2)', $missing);
        $this->assertStringContainsString('Accounting software', $missing);
        $this->assertStringNotContainsString('Employment months', $missing);
        $this->assertStringContainsString('Employment months', $xpath->query('//details[@data-review-section="warnings"]')->item(0)->textContent);
        $this->assertStringContainsString('Provide a genuine software example.', $html);
        $this->assertStringNotContainsString("\u{F06C}", $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_older_drafts_without_an_ai_assessment_still_show_all_sections(): void
    {
        $html = view('workspace.partials.revamp-review', ['comparison' => ['remaining_keywords' => ['SQL'], 'added_keywords' => ['PHP']]])->render();
        $this->assertStringContainsString('Requirements still missing (1)', $html);
        $this->assertStringContainsString('No detailed change report', $html);
        $this->assertStringContainsString('No additional recommendations', $html);
        $this->assertStringContainsString('No warnings were recorded', $html);
        $this->assertSame(6, substr_count($html, 'data-review-section='));
    }
}
