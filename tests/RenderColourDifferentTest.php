<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../lib/WeatherMap.functions.php';

class RenderColourDifferentTest extends TestCase
{
    public function testDifferentValues()
    {
        $this->assertEquals('1 1 2', render_colour([1,1,2]));
    }
}
