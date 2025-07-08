<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../lib/Weathermap.class.php';

class MapTimeStoredTest extends TestCase
{
    public function testTimestampUsesMapTime()
    {
        $wm = new WeatherMap();
        $wm->stamptext = '%Y';
        $wm->map_time = 1270813792;
        $wm->datestamp = '';
        $im = imagecreatetruecolor(10, 10);
        $colour = imagecolorallocate($im, 0, 0, 0);
        $wm->DrawTimestamp($im, $wm->timefont, $colour);
        imagedestroy($im);
        $this->assertEquals('2010', $wm->datestamp);
    }
}
