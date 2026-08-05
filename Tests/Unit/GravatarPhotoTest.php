<?php

namespace Modules\WooCommerceCustomerEnrichment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Modules\WooCommerceCustomerEnrichment\Services\GravatarPhoto;

require_once __DIR__.'/../../Services/GravatarPhoto.php';

class GravatarPhotoTest extends TestCase
{
    public function testUrlForCanonicalVector()
    {
        // Gravatar's documented example: trailing space + mixed case.
        $this->assertSame(
            'https://www.gravatar.com/avatar/0bc83cb571cd1c50ba6f3e8a78ef1346?s=200&d=404',
            GravatarPhoto::urlFor('MyEmailAddress@example.com ')
        );
    }

    public function testUrlForCleanInputStable()
    {
        $this->assertSame(
            GravatarPhoto::urlFor('kunde@example.org'),
            GravatarPhoto::urlFor('  KUNDE@example.org  ')
        );
    }
}
