<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../lib/WMPoint.class.php';
require_once __DIR__ . '/../lib/WMVector.class.php';
require_once __DIR__ . '/../lib/WMLine.class.php';
require_once __DIR__ . '/../lib/geometry.php';

class WMPointDistanceTest extends TestCase
{
    public function testDistanceToLineHorizontal()
    {
        $line = new WMLine(new WMPoint(0, 0), new WMVector(10, 0));
        $p = new WMPoint(0, 5);
        $this->assertEquals(5, $p->distanceToLine($line));
    }

    public function testDistanceToLineVertical()
    {
        $line = new WMLine(new WMPoint(2, 0), new WMVector(0, 5));
        $p = new WMPoint(5, 5);
        $this->assertEqualsWithDelta(3, $p->distanceToLine($line), 0.0001);
    }

    public function testDistanceToLineSegmentInside()
    {
        $seg = new WMLineSegment(new WMPoint(0, 0), new WMPoint(10, 0));
        $p = new WMPoint(5, 5);
        $this->assertEquals(5, $p->distanceToLineSegment($seg));
    }

    public function testDistanceToLineSegmentOutside()
    {
        $seg = new WMLineSegment(new WMPoint(0, 0), new WMPoint(10, 0));
        $p = new WMPoint(-5, 3);
        $expected = sqrt(34); // distance to point (0,0)
        $this->assertEqualsWithDelta($expected, $p->distanceToLineSegment($seg), 0.0001);
    }
}
