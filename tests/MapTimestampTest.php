<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../lib/Weathermap.class.php';

class MapTimestampTest extends TestCase
{
    public function testStamptextFormat()
    {
        $wm = new WeatherMap();
        $wm->add_hint('testmode', 1);
        $wm->stamptext = '%Y';
        $wm->DrawMap('null');
        $this->assertEquals('2010', $wm->datestamp);
    }

    public function testManualDatestampUnchanged()
    {
        $wm = new WeatherMap();
        $image = imagecreatetruecolor(100, 100);
        $colour = imagecolorallocate($image, 0, 0, 0);
        $wm->datestamp = 'custom';
        $wm->DrawTimestamp($image, 2, $colour);
        $this->assertEquals('custom', $wm->datestamp);
    }
}
