<?php

namespace geoPHP\Adapter;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use geoPHP\Exception\FileFormatException;
use geoPHP\Exception\InvalidXmlException;
use geoPHP\Geometry\Collection;
use geoPHP\geoPHP;
use geoPHP\Geometry\Geometry;
use geoPHP\Geometry\GeometryCollection;
use geoPHP\Geometry\Point;
use geoPHP\Geometry\LineString;
use geoPHP\Geometry\MultiLineString;

/*
 * Copyright (c) Patrick Hayes
 *
 * This code is open-source and licenced under the Modified BSD License.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * PHP Geometry/GPX encoder/decoder
 */
class GPX implements GeoAdapter
{
    /** @var string $nss Name-space string. eg 'georss:' */
    protected $nss = '';

    /**
     * @var GpxTypes
     */
    protected $gpxTypes;

    /**
     * @var DOMXPath
     */
    protected $xpath;

    /**
     * @var boolean
     */
    protected $parseGarminRpt = false;

    /**
     * @var Point[]|null
     */
    protected $trackFromRoute = null;

    /**
     * Read GPX string into geometry object
     *
     * @param string $gpx A GPX string
     * @param array<string, array<mixed>>|null $allowedElements Which elements can be read from each GPX type.
     *                   If not specified, every element defined in the GPX specification can be read
     *                   Can be overwritten with an associative array, with type name in keys.
     *                   eg.: ['wptType' => ['ele', 'name'], 'trkptType' => ['ele'], 'metadataType' => null]
     * @return Geometry
     * @throws InvalidXmlException If GPX is not a valid XML
     * @throws FileFormatException If cannot parse GPX
     */
    public function read(string $gpx, ?array $allowedElements = null): Geometry
    {
        $this->gpxTypes = new GpxTypes($allowedElements);

        //libxml_use_internal_errors(true); // why?

        // Load into DOMDocument
        $xmlObject = new DOMDocument();
        $xmlObject->preserveWhiteSpace = false;
        $loadSuccess = @$xmlObject->loadXML($gpx);
        if (!$loadSuccess) {
            throw new InvalidXmlException();
        }

        $this->parseGarminRpt = strpos($gpx, 'gpxx:rpt') > 0;

        // Initialize XPath parser if needed (currently only for Garmin extensions)
        if ($this->parseGarminRpt) {
            $this->xpath = new DOMXPath($xmlObject);
            $this->xpath->registerNamespace('gpx', 'http://www.topografix.com/GPX/1/1');
            $this->xpath->registerNamespace('gpxx', 'http://www.garmin.com/xmlschemas/GpxExtensions/v3');
        }

        try {
            $geom = $this->geomFromXML($xmlObject);
            if ($geom->isEmpty()) {
                /* Geometry was empty but maybe because its tags was not lower cased.
                   We try to lower-case tags and try to run again, but just once.
                */
                $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
                $caller = $backtrace[1]['function'] ?? null;
                if ($caller && $caller !== __FUNCTION__) {
                    $gpx = preg_replace_callback(
                        "/(<\/?\w+)(.*?>)/",
                        function ($m) {
                            return strtolower($m[1]) . $m[2];
                        },
                        $gpx
                    );
                    $geom = $this->read($gpx, $allowedElements);
                }
            }
        } catch (\Exception $e) {
            throw new FileFormatException("Cannot Read Geometry From GPX. " . $e->getMessage(), null, 0, $e);
        }

        return $geom;
    }

    /**
     * Parses the GPX XML and returns a geometry
     * @param DOMDocument $xmlObject
     * @return Geometry Returns the geometry representation of the GPX (@see geoPHP::buildGeometry)
     */
    protected function geomFromXML(DOMDocument $xmlObject): Geometry
    {
        /** @var Geometry[] $geometries */
        $geometries = array_merge(
            $this->parseWaypoints($xmlObject),
            $this->parseTracks($xmlObject),
            $this->parseRoutes($xmlObject)
        );

        if (isset($this->trackFromRoute)) {
            $trackFromRouteGeom = new LineString($this->trackFromRoute);
            $trackFromRouteGeom->setData('gpxType', 'track');
            $trackFromRouteGeom->setData('type', 'planned route');
            $geometries[] = $trackFromRouteGeom;
        }

        $geometry = geoPHP::buildGeometry($geometries);

        if (
            in_array('metadata', $this->gpxTypes->get('gpxType'))
            && $xmlObject->getElementsByTagName('metadata')->length === 1
        ) {
            $metadata = self::parseNodeProperties(
                $xmlObject->getElementsByTagName('metadata')->item(0),
                $this->gpxTypes->get('metadataType')
            );
            if ($geometry->getData() !== null && $metadata !== null) {
                $geometry = new GeometryCollection([$geometry]);
            }
            $geometry->setData($metadata);
        }

        return $geometry;
    }

    /**
     * @param DOMNode $xml
     * @param string $nodeName
     * @return DOMNode[]
     */
    protected function childElements(DOMNode $xml, string $nodeName = ''): array
    {
        $children = [];
        foreach ($xml->childNodes as $child) {
            if ($child->nodeName == $nodeName) {
                $children[] = $child;
            }
        }

        return $children;
    }

    /**
     * @param DOMElement $node
     * @return Point
     */
    protected function parsePoint(DOMElement $node): Point
    {
        $lat = (float) $node->attributes->getNamedItem("lat")->nodeValue;
        $lon = (float) $node->attributes->getNamedItem("lon")->nodeValue;
        $elevation = null;
        $ele = $node->getElementsByTagName('ele');
        if ($ele->length) {
            $elevation = (float) $ele->item(0)->nodeValue;
        }
        $point = new Point($lon, $lat, $elevation);
        $point->setData($this->parseNodeProperties($node, $this->gpxTypes->get($node->nodeName . 'Type')));
        if ($node->nodeName === 'rtept' && $this->parseGarminRpt) {
            foreach ($this->xpath->query('.//gpx:extensions/gpxx:RoutePointExtension/gpxx:rpt', $node) as $element) {
                $this->trackFromRoute[] = $this->parsePoint($element);
            }
        }
        return $point;
    }

    /**
     * @param DOMDocument $xmlObject
     * @return Point[]
     */
    protected function parseWaypoints(DOMDocument $xmlObject): array
    {
        if (!in_array('wpt', $this->gpxTypes->get('gpxType'))) {
            return [];
        }
        $points = [];
        $wptElements = $xmlObject->getElementsByTagName('wpt');
        foreach ($wptElements as $wpt) {
            $point = $this->parsePoint($wpt);
            $point->setData('gpxType', 'waypoint');
            $points[] = $point;
        }
        return $points;
    }

    /**
     * @param DOMDocument $xmlObject
     * @return LineString[]
     */
    protected function parseTracks(DOMDocument $xmlObject): array
    {
        if (!in_array('trk', $this->gpxTypes->get('gpxType'))) {
            return [];
        }
        $tracks = [];
        $trkElements = $xmlObject->getElementsByTagName('trk');
        foreach ($trkElements as $trk) {
            $segments = [];
            /** @noinspection SpellCheckingInspection */
            foreach ($this->childElements($trk, 'trkseg') as $trkseg) {
                $points = [];
                /** @noinspection SpellCheckingInspection */
                foreach ($this->childElements($trkseg, 'trkpt') as $trkpt) {
                    /** @var DomElement $trkpt */
                    $points[] = $this->parsePoint($trkpt);
                }
                // Avoids creating invalid LineString
                $segments[] = new LineString(count($points) <> 1 ? $points : []);
            }
            $track = count($segments) === 0
                    ? new LineString()
                    : (count($segments) === 1
                            ? $segments[0]
                            : new MultiLineString($segments));
            $track->setData($this->parseNodeProperties($trk, $this->gpxTypes->get('trkType')));
            $track->setData('gpxType', 'track');
            $tracks[] = $track;
        }
        return $tracks;
    }

    /**
     * @param DOMDocument $xmlObject
     * @return LineString[]
     */
    protected function parseRoutes(DOMDocument $xmlObject): array
    {
        if (!in_array('rte', $this->gpxTypes->get('gpxType'))) {
            return [];
        }
        $lines = [];
        $rteElements = $xmlObject->getElementsByTagName('rte');
        foreach ($rteElements as $rte) {
            $components = [];
            /** @noinspection SpellCheckingInspection */
            foreach ($this->childElements($rte, 'rtept') as $routePoint) {
                /** @var DomElement $routePoint */
                $components[] = $this->parsePoint($routePoint);
            }
            $line = new LineString($components);
            $line->setData($this->parseNodeProperties($rte, $this->gpxTypes->get('rteType')));
            $line->setData('gpxType', 'route');
            $lines[] = $line;
        }
        return $lines;
    }

    /**
     * Parses a DOMNode and returns its content in a multidimensional associative array
     * eg: <wpt><name>Test</name><link href="example.com"><text>Example</text></link></wpt>
     * to: ['name' => 'Test', 'link' => ['text'] => 'Example', '@attributes' => ['href' => 'example.com']]
     *
     * @param DOMNode $node
     * @param string[]|null $tagList
     * @return string | array<string, string> | array<string, array<string>>
     */
    protected static function parseNodeProperties(DOMNode $node, ?array $tagList = null)
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return $node->nodeValue;
        }
        $result = [];
        foreach ($node->childNodes as $childNode) {
            /** @var \DOMNode $childNode */
            if ($childNode->hasChildNodes()) {
                if ($tagList === null || in_array($childNode->nodeName, $tagList ?: [])) {
                    if ($node->firstChild->nodeName == $node->lastChild->nodeName && $node->childNodes->length > 1) {
                        $result[$childNode->nodeName][] = self::parseNodeProperties($childNode);
                    } else {
                        $result[$childNode->nodeName] = self::parseNodeProperties($childNode);
                    }
                }
            } elseif ($childNode->nodeType === 1 && in_array($childNode->nodeName, $tagList ?: [])) {
                $result[$childNode->nodeName] = self::parseNodeProperties($childNode);
            } elseif ($childNode->nodeType === 3) {
                $result = $childNode->nodeValue;
            }
        }
        if ($node->hasAttributes()) {
            if (is_string($result)) {
                // As of the GPX specification text node cannot have attributes, thus this never happens
                $result = ['#text' => $result];
            }
            $attributes = [];
            foreach ($node->attributes as $attribute) {
                if ($attribute->name !== 'lat' && $attribute->name !== 'lon' && trim($attribute->value) !== '') {
                    $attributes[$attribute->name] = trim($attribute->value);
                }
            }
            if (count($attributes)) {
                $result['@attributes'] = $attributes;
            }
        }
        return $result;
    }


    /**
     * Serialize geometries into a GPX string.
     *
     * @param Geometry $geometry
     * @param string|null $namespace
     * @param array<mixed>|null $allowedElements Which elements can be added to each GPX type
     *                   If not specified, every element defined in the GPX specification can be added
     *                   Can be overwritten with an associative array, with type name in keys.
     *                   eg.: ['wptType' => ['ele', 'name'], 'trkptType' => ['ele'], 'metadataType' => null]
     * @return string The GPX string representation of the input geometries
     */
    public function write(Geometry $geometry, ?string $namespace = null, ?array $allowedElements = null): string
    {
        if ($namespace) {
            $this->nss = $namespace . ':';
        }
        $this->gpxTypes = new GpxTypes($allowedElements);

        return
        '<?xml version="1.0" encoding="UTF-8"?>
<' . $this->nss . 'gpx creator="geoPHP" version="1.1"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xmlns="http://www.topografix.com/GPX/1/1"
  xsi:schemaLocation="http://www.topografix.com/GPX/1/1 http://www.topografix.com/GPX/1/1/gpx.xsd" >

' . $this->geometryToGPX($geometry) .
        '</' . $this->nss . 'gpx>
';
    }

    /**
     * @param Geometry|Collection $geometry
     * @return string
     */
    protected function geometryToGPX($geometry)
    {
        switch ($geometry->geometryType()) {
            case Geometry::POINT:
                /** @var Point $geometry */
                return $this->pointToGPX($geometry);
            case Geometry::LINE_STRING:
            case Geometry::MULTI_LINE_STRING:
                /** @var LineString $geometry */
                return $this->linestringToGPX($geometry);
            case Geometry::POLYGON:
            case Geometry::MULTI_POINT:
            case Geometry::MULTI_POLYGON:
            case Geometry::GEOMETRY_COLLECTION:
                /** @var Collection $geometry */
                return $this->collectionToGPX($geometry);
        }
        return '';
    }

    /**
     * @param Point $geom
     * @param string $tag Can be "wpt", "trkpt" or "rtept"
     * @return string
     */
    private function pointToGPX($geom, $tag = 'wpt')
    {
        if ($geom->isEmpty() || ($tag === 'wpt' && !in_array($tag, $this->gpxTypes->get('gpxType')))) {
            return '';
        }
        $indent = $tag === 'trkpt' ? "\t\t" : ($tag === 'rtept' ? "\t" : '');

        if ($geom->is3D() || $geom->getData() !== null) {
            $node = $indent . "<" . $this->nss . $tag
                    . " lat=\"" . $geom->y() . "\" lon=\"" . $geom->x() . "\">\n";
            if ($geom->is3D()) {
                $geom->setData('ele', $geom->z());
            }
            $node .= self::processGeometryData($geom, $this->gpxTypes->get($tag . 'Type'), $indent . "\t") .
                    $indent . "</" . $this->nss . $tag . ">\n";
            if ($geom->is3D()) {
                $geom->setData('ele', null);
            }
            return $node;
        }
        return $indent . "<" . $this->nss . $tag . " lat=\"" . $geom->y() . "\" lon=\"" . $geom->x() . "\" />\n";
    }

    /**
     * Writes a LineString or MultiLineString to the GPX
     *
     * The (Multi)LineString will be included in a <trk></trk> block
     * The LineString or each LineString of the MultiLineString will be in <trkseg> </trkseg> inside the <trk>
     *
     * @param LineString|MultiLineString $geom
     * @return string
     */
    private function linestringToGPX($geom)
    {
        $isTrack = $geom->getData('gpxType') === 'route' ? false : true;
        if ($geom->isEmpty() || !in_array($isTrack ? 'trk' : 'rte', $this->gpxTypes->get('gpxType'))) {
            return '';
        }

        if ($isTrack) { // write as <trk>

            /** @noinspection SpellCheckingInspection */
            $gpx = "<" . $this->nss . "trk>\n" . self::processGeometryData($geom, $this->gpxTypes->get('trkType'));
            $components = $geom->geometryType() === 'LineString' ? [$geom] : $geom->getComponents();
            foreach ($components as $lineString) {
                $gpx .= "\t<" . $this->nss . "trkseg>\n";
                foreach ($lineString->getPoints() as $point) {
                    $gpx .= $this->pointToGPX($point, 'trkpt');
                }
                $gpx .= "\t</" . $this->nss . "trkseg>\n";
            }
            /** @noinspection SpellCheckingInspection */
            $gpx .= "</" . $this->nss . "trk>\n";
        } else {    // write as <rte>

            /** @noinspection SpellCheckingInspection */
            $gpx = "<" . $this->nss . "rte>\n" . self::processGeometryData($geom, $this->gpxTypes->get('rteType'));
            foreach ($geom->getPoints() as $point) {
                $gpx .= $this->pointToGPX($point, 'rtept');
            }
            /** @noinspection SpellCheckingInspection */
            $gpx .= "</" . $this->nss . "rte>\n";
        }

        return $gpx;
    }

    /**
     * @param Collection $geometry
     * @return string
     */
    public function collectionToGPX(Collection $geometry): string
    {
        $metadata = self::processGeometryData($geometry, $this->gpxTypes->get('metadataType'));
        $metadata = empty($metadata) || !in_array('metadata', $this->gpxTypes->get('gpxType'))
                ? ''
                : "<metadata>\n{$metadata}</metadata>\n\n";
        $wayPoints = $routes = $tracks = "";

        foreach ($geometry->getComponents() as $component) {
            $type = $component->geometryType();
            if (strpos($type, 'Point') !== false) {
                $wayPoints .= $this->geometryToGPX($component);
            }
            if (strpos($type, 'LineString') !== false && $component->getData('gpxType') === 'route') {
                $routes .= $this->geometryToGPX($component);
            }
            if (strpos($type, 'LineString') !== false && $component->getData('gpxType') !== 'route') {
                $tracks .= $this->geometryToGPX($component);
            }
            if (strpos($type, 'Point') === false && strpos($type, 'LineString') === false) {
                return $this->geometryToGPX($component);
            }
        }

        return $metadata . $wayPoints . $routes . $tracks;
    }

    /**
     * @param Geometry $geometry
     * @param string[] $tagList Allowed tags
     * @param string $indent
     * @return string
     */
    protected static function processGeometryData(Geometry $geometry, array $tagList, string $indent = "\t"): string
    {
        $tags = '';
        if ($geometry->getData() !== null) {
            foreach ($tagList as $tagName) {
                if ($geometry->hasDataProperty($tagName)) {
                    $tags .= self::createNodes($tagName, $geometry->getData($tagName), $indent) . "\n";
                }
            }
        }
        return $tags;
    }

    /**
     * @param string $tagName
     * @param string|array<string, mixed> $value
     * @param string $indent
     * @return string
     */
    protected static function createNodes(string $tagName, $value, string $indent): string
    {
        $attributes = '';
        if (!is_array($value)) {
            $returnValue = $value;
        } else {
            $returnValue = '';
            if (array_key_exists('@attributes', $value)) {
                $attributes = '';
                foreach ($value['@attributes'] as $attributeName => $attributeValue) {
                    $attributes .= ' ' . $attributeName . '="' . $attributeValue . '"';
                }
                unset($value['@attributes']);
            }
            foreach ($value as $subKey => $subValue) {
                $returnValue .= "\n" . self::createNodes($subKey, $subValue, $indent . "\t") . "\n" . $indent;
            }
        }
        return $indent . "<{$tagName}{$attributes}>{$returnValue}</{$tagName}>";
    }
}
