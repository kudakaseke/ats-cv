<?php

namespace Tests\Unit;

use App\Services\CvProfessionalTitle;
use PHPUnit\Framework\TestCase;

class CvProfessionalTitleTest extends TestCase
{
    public function test_accounting_headline_is_preserved_instead_of_target_vacancy(): void
    {
        $source = "Alex Example\nAccountant\nSummary\nPrepared financial reports.\nExperience\nAccounts Clerk";
        $this->assertSame('Accountant', (new CvProfessionalTitle)->preserve($source, 'Procurement Officer'));
    }

    public function test_explicit_headline_takes_priority_over_an_older_position(): void
    {
        $source = "Alex Example\nProfessional title: Accountant\nExperience\nProcurement Officer";
        $this->assertSame('Accountant', (new CvProfessionalTitle)->preserve($source, 'Procurement Officer'));
    }

    public function test_genuine_procurement_title_is_retained(): void
    {
        $this->assertSame('Procurement Officer', (new CvProfessionalTitle)->preserve("Alex Example\nProcurement Officer\nExperience", 'Procurement Officer'));
    }

    public function test_incidental_duties_do_not_establish_a_professional_title(): void
    {
        $this->assertSame('', (new CvProfessionalTitle)->preserve("Alex Example\nSummary\nAssisted the Procurement Officer with invoices.", 'Procurement Officer'));
    }
}
