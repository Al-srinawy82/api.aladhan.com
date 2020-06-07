<?php
namespace AlAdhanApi\Model;

use AlAdhanApi\Helper\Config;
use AlAdhanApi\Helper\Cacher;
use AlAdhanApi\Helper\Generic;
use AlAdhanApi\Helper\Log;
use AlAdhanApi\Helper\GoogleMapsApi;
use AlAdhanApi\Helper\AskGeo;
use AlAdhanApi\Helper\Database;

/**
 * Class Locations
 * @package Model\Locations
 */
class Locations
{
    // Constants mapped to methods in Locations class.
    const ID_DB_CoOrdinatesAndTimezone = 1;
    const ID_DB_GoogleCoOrdinatesAndZone = 2;
    const ID_DB_checkGeolocateTable = 3;
    const ID_DB_checkIfGeoRecordExistsViaCo = 4;
    const ID_DB_checkQuery = 5;
    const ID_DB_checkAddressQuery = 6;
    const ID_DB_checkInvalidQuery = 7;
    const ID_DB_getAddressCoOrdinatesAndZone = 8;
    const ID_DB_getTimezoneByCoOrdinates = 9;
    const ID_DB_checkTimezoneQuery = 10;
    const ID_DB_checkInvalidCityCountryQuery = 11;

    /**
     * Constructor
     */
    public function __construct($logger = null)
    {
        $this->config = new Config();
        $this->cacher = new Cacher();
        if ($logger === null) {
            $this->logger = new Log();
        } else {
            $this->logger = $logger;
        }

        $this->google = new GoogleMapsApi($this->config, $this->logger);
        $this->askGeo = new AskGeo($this->config, $this->logger);
        $db = new Database();
        $this->db = $db->getConnection();
    }

    /**
     * @param  String $city    [description]
     * @param  String $country [description]
     * @param  String $state   [description]
     * @return Array          [description]
     */
    public function getCoOrdinatesAndTimezone($city, $country, $state = '')
    {
        $cacheKey = $this->cacher->generateKey(self::ID_DB_CoOrdinatesAndTimezone, [$city, $country, $state]);
        if ($this->cacher->check($cacheKey) !== false) {
            return $this->cacher->get($cacheKey);
        }

        if ($state == '') {
            $sql = 'SELECT city.latitude, city.longitude, city.timezone FROM city
                    LEFT JOIN country
                    ON country.iso = city.countryiso
                    WHERE
                    (country.printable_name = ? OR country.iso = ?)
                    AND city.name = ?';

            $stmnt = $this->db->executeQuery($sql, [$country, $country, $city]);

            $result = $stmnt->fetch();
        } else {
            $sql = 'SELECT city.latitude, city.longitude, city.timezone FROM city
                LEFT JOIN country
                ON country.iso = city.countryiso
                LEFT JOIN state
                ON country.iso = state.countryiso
                WHERE
                (country.printable_name)= ? OR country.iso = ?)
                AND
                (state.name = ? OR state.abbreviation = ?)
                AND
                city.name = ?';

            $stmnt = $this->db->executeQuery($sql, [strtolower($country), strtolower($country), strtolower($state), strtolower($state), strtolower($city)]);

            $result = $stmnt->fetch();
        }
        $this->cacher->set($cacheKey, $result);

        return $result;
    }

    /**
     * [createAddressString description]
     * @param  [type] $city    [description]
     * @param  [type] $state   [description]
     * @param  [type] $country [description]
     * @return [type]          [description]
     */
    private function createAddressString($city, $state, $country)
    {
        $string = $city;
        if ($state != '') {
            $string .= ', ' . $state;
        }
        $string .= ', ' . $country;

        return $string;
    }

    /**
     * @param  String $city    [description]
     * @param  String $country [description]
     * @param  String $state   [description]
     * @return Array          [description]
     */
    public function getGoogleCoOrdinatesAndZone($city, $country, $state = '')
    {
        $cityO = $city;
        $countryO = $country;
        $stateO = $state;

        if (!$this->citySanitizer($city, $country)) {
            return false;
        }

        $cacheKey = $this->cacher->generateKey(self::ID_DB_GoogleCoOrdinatesAndZone, [$city, $country, $state]);
        if ($this->cacher->check($cacheKey) !== false) {
            return $this->cacher->get($cacheKey);
        }

        $checkQuery = $this->checkQuery($cityO, $countryO, $stateO);

        if ($checkQuery) {
            return $checkQuery;
        }

        $local = $this->checkGeolocateTable($city, $country, $state);

        if ($local) {
            return $local;
        }

        if (Generic::isGoogleBot() || $this->checkInvalidCityCountryQuery($cityO, $stateO, $countryO)) {
            return false;
        }

        $ginfo = $this->google->getGeoCodeLocationAndTimeZone($this->createAddressString($city, $state, $country));
        // It may be that the user entered an unconventional format above, but if already have the latitude and longitude, don't re-create the record. We want 1 entry for each combination of co-ordinates.
        if ($ginfo && is_object($ginfo)) {
            if (!$this->checkIfGeoRecordExistsViaCo($city, $country, $state)) {
                // Update database
                $this->addGeoLocateRecord($ginfo);
            }

            $this->recordQuery($cityO, $stateO, $countryO, $ginfo->lat, $ginfo->lng, $ginfo->timezone);

            $result = [
                'latitude' => $ginfo->lat,
                'longitude' => $ginfo->lng,
                'timezone' => $ginfo->timezone
            ];

            $this->cacher->set($cacheKey, $result);

            return $result;
        } else {
            // It does not exist, record an invalid query.
            $this->recordInvalidCityCountryQuery($cityO, $stateO, $countryO);
        }

        return false;
    }

    /**
     * [addGeoLocateRecord description]
     * @param [type] $ginfo [description]
     */
    public function addGeoLocateRecord($ginfo)
    {
        return $this->db->insert('geolocate',
            [
                'countryiso' => $ginfo->countryiso,
                'country' => $ginfo->country,
                'state' => $ginfo->state,
                'stateabbr' => $ginfo->stateabbr,
                'city' => $ginfo->city,
                'cityabbr' => $ginfo->cityabbr,
                'latitude' => $ginfo->lat,
                'longitude' => $ginfo->lng,
                'timezone' => $ginfo->timezone,
                'timezonename' => $ginfo->timezonename
            ]
        );
    }

    /**
     * @param  String $city    [description]
     * @param  String $country [description]
     * @param  String $state   [description]
     * @return Array          [description]
     */
    public function checkGeolocateTable($city, $country, $state)
    {

        $cacheKey = $this->cacher->generateKey(self::ID_DB_checkGeolocateTable, [$city, $country, $state]);

        if ($this->cacher->check($cacheKey) !== false) {
            return $this->cacher->get($cacheKey);
        }


        if ($state == '') {
            $result = $this->db->fetchAssoc("SELECT latitude, longitude, timezone
                                FROM geolocate WHERE
                                (country = ? OR countryiso = ?)
                                AND
                                (
                                (city = ? OR cityabbr = ?)
                                )",
            [$country, $country, $city, $city]);
        } else {
            $result = $this->db->fetchAssoc("SELECT latitude, longitude, timezone
                                FROM geolocate WHERE
                                (country = ? OR countryiso = ?)
                                AND
                                (city = ? OR cityabbr = ?)
                                AND
                                (state = ? OR stateabbr = ?)",
            [$country, $country, $city, $city, $state, $state]);
        }

        $this->cacher->set($cacheKey, $result);

        return $result;
    }

    /**
     * @param  String $city    [description]
     * @param  String $country [description]
     * @param  String $state   [description]
     * @return Array          [description]
     */
    private function checkIfGeoRecordExistsViaCo($city, $country, $state)
    {

        $cacheKey = $this->cacher->generateKey(self::ID_DB_checkIfGeoRecordExistsViaCo, [$city, $country, $state]);

        if ($this->cacher->check($cacheKey) !== false) {
            return $this->cacher->get($cacheKey);
        }

        $result = $this->db->fetchAssoc("SELECT id
                                FROM geolocate WHERE
                                city = ? AND country = ? AND state = ?",
            [$city, $country, $state]);

        $this->cacher->set($cacheKey, $result);

        return $result;
    }

    /**
     * @param  String $city    [description]
     * @param  String $country [description]
     * @param  String $state   [description]
     * @return Array          [description]
     */
    public function citySanitizer($city, $country, $state = '')
    {
        $city = strtolower($city);
        $country = strtolower($country);

        if ($city == '' || $country == '' || $city == null || $city == 'null' || $country == 'strcountrycode' || $city == 'default_country' || $country == 'default_city' || $city == 'London\u03bf') {
            return false;
        }

        if ($city == 'ramallah' && $country == 'ps') {
            return false;
        }

        if ($city == 'london' && $country == 'sa') {
            return false;
        }

        if (strpos($city, '$') !== false ||
            strpos($city, '£') !== false ||
            strpos($city, '#') !== false ||
            strpos($city, 'quote') !== false ||
            strpos($city, '/') !== false ||
            strpos($city, '++') !== false
        ) {
            return false;
        }

        return true;
    }

    /**
     * [recordQuery description]
     * @param  [type] $city     [description]
     * @param  [type] $state    [description]
     * @param  [type] $country  [description]
     * @param  [type] $lat      [description]
     * @param  [type] $lng      [description]
     * @param  [type] $timezone [description]
     * @return [type]           [description]
     */
    public function recordQuery($city, $state, $country, $lat, $lng, $timezone)
    {

        return $this->db->insert('geolocate_queries',
                                 [
                                     'city' => $city,
                                     'state' => $state,
                                     'country' => $country,
                                     'latitude' => $lat,
                                     'longitude' => $lng,
                                     'timezone' => $timezone,
                                 ]
                                 );
    }

    /**
     * @param  String $address    [description]
     * @return
     */
    public function recordInvalidQuery($address)
    {
        return $this->db->insert('address_geolocate_invalid', ['query' => $address]);
    }

    /**
     * @param $city
     * @param $state
     * @param $country
     * @return mixed
     */
    public function recordInvalidCityCountryQuery($city, $state, $country)
    {
        return $this->db->insert('geolocate_queries_invalid',
            [
                'city' => $city,
                'state' => $state,
                'country' => $country
            ]
        );
    }

    /**
     * @param  String $address    [description]
     * @return Mixed          [description]
     */
    public function checkInvalidCityCountryQuery($city, $state, $country)
    {

        //$cacheKey = $this->cacher->generateKey(self::ID_DB_checkInvalidCityCountryQuery, [$city, $state, $country]);

        //if ($this->cacher->check($cacheKey) !== false) {
        //    return $this->cacher->get($cacheKey);
        //}

        if ($state == '') {
            $result = $this->db->fetchAssoc(
                "SELECT id
                FROM geolocate_queries_invalid WHERE
                city = ? AND country = ?
                ",
                [$city, $country]);
        } else {
            $result = $this->db->fetchAssoc(
                "SELECT id
                FROM geolocate_queries_invalid WHERE
                city = ? AND state = ? AND country = ?
                ",
                [$city, $state, $country]);
        }

        //if ($result) {
        //    $this->cacher->set($cacheKey, $result);
        //}

        return $result;
    }

    /**
     * @param  String $city    [description]
     * @param  String $country [description]
     * @param  String $state   [description]
     * @return Mixed          [description]
     */
    public function checkQuery($city, $country, $state)
    {
        $cacheKey = $this->cacher->generateKey(self::ID_DB_checkQuery, [$city, $country, $state]);

        if ($this->cacher->check($cacheKey) !== false) {
            return $this->cacher->get($cacheKey);
        }

        if ($state == '') {
            $result = $this->db->fetchAssoc(
                "SELECT latitude, longitude, timezone
                FROM geolocate_queries WHERE
                country = ?
                AND
                city = ?
                ",
                [$country, $city]);
        } else {
            $result = $this->db->fetchAssoc(
                "SELECT latitude, longitude, timezone
                FROM geolocate_queries WHERE
                country = ?
                AND
                city = ?
                AND
                state = ?
                ",
                [$country, $city, $state]);
        }

        if ($result) {
            $this->cacher->set($cacheKey, $result);
        }

        return $result;
    }

    /**
     * @param  String $address    [description]
     * @return Mixed          [description]
     */
    public function checkAddressQuery($address)
    {
        $cacheKey = $this->cacher->generateKey(self::ID_DB_checkAddressQuery, [$address]);

        if ($this->cacher->check($cacheKey) !== false) {
            return $this->cacher->get($cacheKey);
        }

        $result = $this->db->fetchAssoc(
                "SELECT latitude, longitude, timezone
                FROM address_geolocate_queries WHERE
                address = ?
                ",
                [$address]) ;

        if ($result) {
            $this->cacher->set($cacheKey, $result);
        }

        return $result;
    }

    /**
     * @param  String $address    [description]
     * @return Mixed          [description]
     */
    public function checkInvalidQuery($address)
    {

        $cacheKey = $this->cacher->generateKey(self::ID_DB_checkInvalidQuery, [$address]);

        if ($this->cacher->check($cacheKey) !== false) {
            return $this->cacher->get($cacheKey);
        }

        $result = $this->db->fetchAssoc(
                "SELECT id
                FROM address_geolocate_invalid WHERE
                query = ?
                ",
                [$address]);

        if ($result) {
            $this->cacher->set($cacheKey, $result);
        }

        return $result;
    }

    /**
     * @param  String $address    [description]
     * @return Mixed          [description]
     */
    public function getAddressCoOrdinatesAndZone($address)
    {
        if ($address == '' || $address == null) {
            return false;
        }
        $address = (string) $address;


        $cacheKey = $this->cacher->generateKey(self::ID_DB_getAddressCoOrdinatesAndZone, [$address]);

        if ($this->cacher->check($cacheKey) !== false) {
            return $this->cacher->get($cacheKey);
        }

        $checkAddress = $this->checkAddressQuery($address);

        if ($checkAddress) {
            return $checkAddress;
        }

        $checkInvalidQuery = $this->checkInvalidQuery($address);

        if ($checkInvalidQuery) {
            return false;
        }

        // If Google Bot is querying, return false or it's a self propelling cycle of Google making money!
        if (Generic::isGoogleBot()) {
            return false;
        }

        $ginfo = $this->google->getGeoCodeLocationAndTimeZone($address);
        if ($ginfo && is_object($ginfo)) {
            // Update datbase
            $insert = $this->db->insert('address_geolocate_queries',
                 [
                     'address' => $address,
                     'latitude' => $ginfo->lat,
                     'longitude' => $ginfo->lng,
                     'timezone' => $ginfo->timezone
                 ]
             );
            $result = [
                'latitude' => $ginfo->lat,
                'longitude' => $ginfo->lng,
                'timezone' => $ginfo->timezone
            ];

            $this->cacher->set($cacheKey, $result);

            return $result;
        } else {
            $this->recordInvalidQuery($address);

            return false;
        }
    }

    /**
     * [getTimezoneByCoOrdinates description]
     * @param  [type] $lat [description]
     * @param  [type] $lng [description]
     * @return [type]      [description]
     */
    public function getTimezoneByCoOrdinates($lat, $lng)
    {
        $x = $this->checkTimezoneQuery($lat, $lng);
        if ($x) {
            return $x['timezone'];
        }
        $cacheKey = $this->cacher->generateKey(self::ID_DB_getTimezoneByCoOrdinates, [$lat, $lng]);

        if ($this->cacher->check($cacheKey) !== false) {
            return $this->cacher->get($cacheKey);
        }

        if (Generic::isGoogleBot()) {
            return false;
        }

        // If we're here, go to Google.
        //$timezone = $this->google->getTimezoneByCoOrdinates($lat, $lng);
        // Switching to AskGeo
        $timezone = $this->askGeo->getTimezoneByCoOrdinates($lat, $lng);

        $this->addTimezone($lat, $lng, $timezone);

        $this->cacher->set($cacheKey, $timezone);

        return $timezone;
    }

    /**
     * [checkTimezoneQuery description]
     * @param  [type] $lat [description]
     * @param  [type] $lng [description]
     * @return [type]      [description]
     */
    public function checkTimezoneQuery($lat, $lng)
    {
        $lat = (string) $lat;
        $lng = (string) $lng;

        $cacheKey = $this->cacher->generateKey(self::ID_DB_checkTimezoneQuery, [$lat, $lng]);

        if ($this->cacher->check($cacheKey) !== false) {
            return $this->cacher->get($cacheKey);
        }

        $result = $this->db->fetchAssoc(
                "SELECT timezone
                FROM timezone WHERE
                (latitude = ? AND longitude = ?)
                ",
                [$lat, $lng]);

        if ($result) {
            $this->cacher->set($cacheKey, $result);
        }

        return $result;
    }

    public function addTimezone($lat, $lng, $timezone)
    {
        $insert = $this->db->insert('timezone',
             [
                 'latitude' => $lat,
                 'longitude' => $lng,
                 'timezone' => $timezone
             ]
         );

         return $insert;
    }

}
