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
}
