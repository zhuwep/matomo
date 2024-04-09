<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\SitesManager\tests\Unit\SiteContentDetection;

use Piwik\Plugins\SitesManager\SiteContentDetection\WordPress;

/**
 * @group SitesManager
 * @group SiteContentDetection
 * @group Plugins
 */
class WordpressTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @dataProvider responseProvider
     */
    public function testdetectByContent($expected, $data, $headers)
    {
        $detection = new WordPress();
        self::assertSame($expected, $detection->isDetected($data, $headers));
    }

    public function responseProvider()
    {
        yield 'no content at all' => [
            false,
            '',
            []
        ];

        yield 'no wordpress content' => [
            false,
            "<html lang=\"en\"><head><title>A site</title><script>console.log('abc');</script></head><body>A site</body></html>",
            []
        ];

        yield '/wp-content is found' => [
            true,
            "<html lang='en'><head><title>A site</title></head><script src='/wp-content/foo.cs'></script><body>A site<img src='/wp-content/plugins/foo'></body></html>",
            []
        ];
    }
}
