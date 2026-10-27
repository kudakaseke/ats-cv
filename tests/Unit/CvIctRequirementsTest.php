<?php

namespace Tests\Unit;

use App\Services\CvKeywordMatcher;
use App\Services\CvCompatibilityScore;
use PHPUnit\Framework\TestCase;

class CvIctRequirementsTest extends TestCase
{
    public function test_ict_advert_scores_concrete_skills_instead_of_company_background(): void
    {
        $job = "About the role\nThe commission is the independent mechanism required by section 210 of the Constitution.\nIts core values are Independence, Justice and Integrity.\nPurpose of the position\nAdminister the commission infrastructure.\nKey performance areas\nInfrastructure and Network Administration: configure, maintain and monitor servers, LAN/WAN, firewalls and switches to ensure availability.\nEnd-User Support and ICT Assets: provide helpdesk support, resolve hardware incidents and monitor software licence compliance.\nRequirements\nQualifications and experience\nBachelor's degree in Computer Science or Information Systems or a recognised equivalent.\nProfessional certifications such as CCNA or ITIL are an added advantage.\nMinimum of two (2) years of hands-on experience managing enterprise networks and server infrastructure.";
        $matcher = new CvKeywordMatcher;
        $result = $matcher->compare($job, "Education\nBachelor of Computer Science\nSkills\nNetwork\nServer\nFirewall\nHelpdesk\nHardware\nCertifications\nCCNA");
        $this->assertGreaterThan(0, $result['keyword_score']);
        foreach (array_merge($result['matched'], $result['missing']) as $requirement) {
            $this->assertStringNotContainsString('Constitution', $requirement);
            $this->assertStringNotContainsString('core values', $requirement);
            $this->assertStringNotContainsString('two (2)', $requirement);
            $this->assertStringNotContainsString('software licence compliance', $requirement);
        }
        $this->assertContains('Networks', $result['matched']);
        $this->assertContains('Helpdesk', $result['matched']);
        $unrelated = $matcher->compare($job, 'Retail sales and customer service');
        $this->assertSame(0, $unrelated['keyword_score']);
        $scores = new CvCompatibilityScore;
        $this->assertGreaterThan($scores->calculate(50, $unrelated['keyword_score']), $scores->calculate(50, $result['keyword_score']));
    }
}
