<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/Weathermap.class.php';

class InvalidLinkParsingTest extends TestCase
{
    public function testInvalidLinkSkipped()
    {
        $config = "NODE a\nNODE b\nLINK good\n    NODES a b\nLINK bad\n    NODES a c\nLINK good2\n    NODES a b\n";
        $tmp = tempnam(sys_get_temp_dir(), 'wm');
        file_put_contents($tmp, $config);

        $wm = new WeatherMap();
        $wm->ReadConfig($tmp);
        unlink($tmp);

        $this->assertArrayHasKey('good', $wm->links);
        $this->assertArrayHasKey('good2', $wm->links);
        $this->assertArrayNotHasKey('bad', $wm->links);
        $this->assertSame($wm->nodes['a'], $wm->links['good2']->a);
        $this->assertSame($wm->nodes['b'], $wm->links['good2']->b);
    }
}
