<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../lib/WeatherMap.functions.php';

class FormatDateTokensTest extends TestCase
{
    private $ts;

    protected function setUp(): void
    {
        date_default_timezone_set('UTC');
        $this->ts = 1270813792; // same timestamp used in WeatherMap test mode
    }

    public function testTokenR()
    {
        $this->assertEquals('11:49:52 AM', wm_format_date('%r', $this->ts));
    }

    public function testTokenCapitalR()
    {
        $this->assertEquals('11:49', wm_format_date('%R', $this->ts));
    }

    public function testTokenT()
    {
        $this->assertEquals('11:49:52', wm_format_date('%T', $this->ts));
    }

    public function testTokenUpperX()
    {
        $this->assertEquals('11:49:52', wm_format_date('%X', $this->ts));
    }

    public function testTokenLowerX()
    {
        $this->assertEquals('04/09/10', wm_format_date('%x', $this->ts));
    }

    public function testTokenC()
    {
        $this->assertEquals('20', wm_format_date('%C', $this->ts));
    }

    public function testTokenD()
    {
        $this->assertEquals('04/09/10', wm_format_date('%D', $this->ts));
    }
}
