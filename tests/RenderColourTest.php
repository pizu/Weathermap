<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../lib/WeatherMap.functions.php';

class RenderColourTest extends TestCase
{
    public function testNone()
    {
        $this->assertEquals('none', render_colour([-1,-1,-1]));
    }

    public function testCopy()
    {
        $this->assertEquals('copy', render_colour([-2,-2,-2]));
    }

    public function testContrast()
    {
        $this->assertEquals('contrast', render_colour([-3,-3,-3]));
    }

    public function testOther()
    {
        $this->assertEquals('1 2 3', render_colour([1,2,3]));
    }
    
    public function testNotNoneWhenOnlyTwoMinusOne()
    {
        $this->assertEquals('-1 -1 0', render_colour([-1,-1,0]));
    }
}
