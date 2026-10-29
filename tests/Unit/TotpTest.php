<?php

namespace Tests\Unit;

use App\Services\Totp;
use Spark\Testing\TestCase;

class TotpTest extends TestCase
{
    public function testRfc6238Sha1VectorsUsingSixDigits(): void
    {
        // RFC 6238 Appendix B uses eight digits; six digits are its last six.
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037', 20000000000 => '353130'] as $time => $code) {
            $counter = intdiv($time, 30);
            $this->assertSame($code, Totp::code($secret, $counter));
            $this->assertSame($counter, Totp::counter($secret, $code, -1, $time));
            $this->assertSame(null, Totp::counter($secret, $code, $counter, $time));
        }
        $this->assertSame(null, Totp::counter($secret, '12345'));
        $this->assertSame(32, strlen(Totp::secret()));
    }
}
