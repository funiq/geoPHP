<?php

namespace geoPHP\Adapter;

/**
 * Class GpxTypes
 * Defines the available GPX types and their allowed elements following the GPX specification
 *
 * @see http://www.topografix.com/gpx/1/1/
 * @package geoPHP\Adapter
 */
class GpxTypes
{
    /**
     * @var string[] Allowed elements in <gpx>
     * @see http://www.topografix.com/gpx/1/1/#type_gpxType
     */
    public static $gpxTypeElements = [
        'metadata', 'wpt', 'rte', 'trk'
    ];

    /**
     * @var string[] Allowed elements in <trk>
     * @see http://www.topografix.com/gpx/1/1/#type_trkType
     */
    public static $trkTypeElements = [
        'name', 'cmt', 'desc', 'src', 'link', 'number', 'type'
    ];

    /**
     * same as trkTypeElements
     * @var string[] Allowed elements in <rte>
     * @see http://www.topografix.com/gpx/1/1/#type_rteType
     */
    public static $rteTypeElements = [
        'name', 'cmt', 'desc', 'src', 'link', 'number', 'type'
    ];

    /**
     * @var string[] Allowed elements in <wpt>
     * @see http://www.topografix.com/gpx/1/1/#type_wptType
     */
    public static $wptTypeElements = [
        'ele', 'time', 'magvar', 'geoidheight', 'name', 'cmt', 'desc', 'src', 'link', 'sym', 'type',
        'fix', 'sat', 'hdop', 'vdop', 'pdop', 'ageofdgpsdata', 'dgpsid'
    ];

    /**
     * @var string[] Same as wptType
     */
    public static $trkptTypeElements = [    // same as wptTypeElements
        'ele', 'time', 'magvar', 'geoidheight', 'name', 'cmt', 'desc', 'src', 'link', 'sym', 'type',
        'fix', 'sat', 'hdop', 'vdop', 'pdop', 'ageofdgpsdata', 'dgpsid'
    ];

    /**
     * @var string[] Same as wptType
     */
    public static $rteptTypeElements = [    // same as wptTypeElements
        'ele', 'time', 'magvar', 'geoidheight', 'name', 'cmt', 'desc', 'src', 'link', 'sym', 'type',
        'fix', 'sat', 'hdop', 'vdop', 'pdop', 'ageofdgpsdata', 'dgpsid'
    ];

    /**
     * @var string[] Allowed elements in <metadata>
     * @see http://www.topografix.com/gpx/1/1/#type_metadataType
     */
    public static $metadataTypeElements = [
        'name', 'desc', 'author', 'copyright', 'link', 'time', 'keywords', 'bounds'
    ];

    /**
     * @var string[] Allowed elements in <gpx>
     * @see http://www.topografix.com/gpx/1/1/#type_gpxType
     */
    protected $allowedGpxTypeElements;

    /**
     * @var string[] Allowed elements in <trk>
     * @see http://www.topografix.com/gpx/1/1/#type_trkType
     */
    protected $allowedTrkTypeElements;


    /**
     * same as trkTypeElements
     * @var string[] Allowed elements in <rte>
     * @see http://www.topografix.com/gpx/1/1/#type_rteType
     */
    protected $allowedRteTypeElements = [];

    /**
     * @var string[] Same as wptType
     */
    protected $allowedWptTypeElements = [];

    /**
     * @var string[] Same as wptType
     */
    protected $allowedTrkptTypeElements = [];

    /**
     * @var string[] Same as wptType
     */
    protected $allowedRteptTypeElements = [];

    /**
     * @var string[] Allowed elements in <metadata>
     * @see http://www.topografix.com/gpx/1/1/#type_metadataType
     */
    protected $allowedMetadataTypeElements = [];

    /**
     * GpxTypes constructor.
     *
     * @param array<string, ?array<string>>|null $allowedElements Which elements can be used in each GPX type.
     *                   If not specified, every element defined in the GPX specification can be used.
     *                   Can be overwritten with an associative array, with type name in keys.
     *                   eg.: ['wptType' => ['ele', 'name'], 'trkptType' => ['ele'], 'metadataType' => null]
     */
    public function __construct(?array $allowedElements = null)
    {
        $this->allowedGpxTypeElements = self::$gpxTypeElements;
        $this->allowedTrkTypeElements = self::$trkTypeElements;
        $this->allowedRteTypeElements = self::$rteTypeElements;
        $this->allowedWptTypeElements = self::$wptTypeElements;
        $this->allowedTrkptTypeElements = self::$trkptTypeElements;
        $this->allowedRteptTypeElements = self::$rteptTypeElements;
        $this->allowedMetadataTypeElements = self::$metadataTypeElements;

        foreach ($allowedElements ?: [] as $type => $elements) {
            $elements = is_array($elements) ? $elements : [$elements];
            $this->{'allowed' . ucfirst($type) . 'Elements'} = [];
            foreach ($this::${$type . 'Elements'} as $availableType) {
                if (in_array($availableType, $elements)) {
                    $this->{'allowed' . ucfirst($type) . 'Elements'}[] = $availableType;
                }
            }
        }
    }

    /**
     * Returns an array of allowed elements for the given GPX type
     * eg. "gpxType" returns ['metadata', 'wpt', 'rte', 'trk']
     *
     * @param string $type One of the following GPX types:
     *                     gpxType, trkType, rteType, wptType, trkptType, rteptType, metadataType
     * @return string[]
     */
    public function get(string $type): array
    {
        $propertyName = 'allowed' . ucfirst($type) . 'Elements';
        if (isset($this->{$propertyName})) {
            return $this->{$propertyName};
        }
        return [];
    }
}
