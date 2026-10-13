<?php

namespace geoPHP\Tests\Unit\Adapter;

use PHPUnit\Framework\TestCase;
use geoPHP\Adapter\GPX;
use geoPHP\Geometry\LineString;
use geoPHP\Geometry\Point;

/**
 * Test cases for GPX adapter
 *
 * @coversDefaultClass geoPHP\Adapter\GPX
 *
 */
class GPXTest extends TestCase
{
    public function testWritingValidGPX(): void
    {
        $geometry = new LineString([new Point(22.2, 33.3, 44.4), new Point(22.3, 33.4, 44.5)]);
        $geometry->setSRID(4326);

        $gpx = (new GPX())->write($geometry);

        $this->assertEquals(<<<EOF
<?xml version="1.0" encoding="UTF-8"?>
<gpx creator="geoPHP" version="1.1"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xmlns="http://www.topografix.com/GPX/1/1"
  xsi:schemaLocation="http://www.topografix.com/GPX/1/1 http://www.topografix.com/GPX/1/1/gpx.xsd" >

<trk>
	<trkseg>
		<trkpt lat="33.3" lon="22.2">
			<ele>44.4</ele>
		</trkpt>
		<trkpt lat="33.4" lon="22.3">
			<ele>44.5</ele>
		</trkpt>
	</trkseg>
</trk>
</gpx>

EOF, $gpx);
    }
}
