<?php

namespace QuadCompanies\QuadPayment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuadCompanies\QuadPayment\Security\Fingerprinter;

class FingerprinterTest extends TestCase
{
    private Fingerprinter $fp;

    protected function setUp(): void
    {
        $this->fp = new Fingerprinter('key');
    }

    public function test_email_variants_fold_together(): void
    {
        $this->assertSame($this->fp->emailHash('john.smith@gmail.com'), $this->fp->emailHash(' John.Smith+test1@GoogleMail.com '));
        $this->assertSame($this->fp->emailHash('a+x@corp.com'), $this->fp->emailHash('A@corp.com'));
        $this->assertNotSame($this->fp->emailHash('a.b@corp.com'), $this->fp->emailHash('ab@corp.com'));
    }

    public function test_subnets(): void
    {
        $this->assertSame('203.0.113.0/24', $this->fp->subnet('203.0.113.77'));
        $this->assertSame('2001:db8:1:2::/64', $this->fp->subnet('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
        $this->assertNull($this->fp->subnet('not-an-ip'));
    }

    public function test_card_hash_is_stable_and_keyed(): void
    {
        $this->assertSame($this->fp->cardHash('Visa', '4242', '12/30'), $this->fp->cardHash('visa', '4242', '1230'));
        $this->assertNotSame($this->fp->cardHash('visa', '4242', '12/30'), (new Fingerprinter('other'))->cardHash('visa', '4242', '12/30'));
        $this->assertNull($this->fp->cardHash('visa', null, '12/30'));
    }
}
