<?php
/**
 * BreweryMap SMS is an application that allows for US users to enter in a zip 
 * code and retrieve all breweries within a 15 mile radius.  This application
 * was built specifically on the Tropo engine, allowing for SMS, IM, or Voice
 * interaction.  
 * 
 * This application was created by PintLabs, L.L.C.  The PintLabs, BreweryMap
 * and BreweryDB names are property of PintLabs, L.L.C.
 * 
 * @author PintLabs, L.L.C. - http://pintlabs.com - @pintlabs - team@pintlabs.com
 * @see    http://www.tropo.com
 * 
 * 
 * 
 * 
 * This code is released under the following license:
 * 
 * ============================================================================
 * 
 * This license is governed by United States copyright law, and with respect to 
 * matters of tort, contract, and other causes of action it is governed by 
 * North Carolina law, without regard to North Carolina choice of law 
 * provisions.  The forum for any dispute resolution shall be in Wake County, 
 * North Carolina.
 * 
 * Redistribution and use in source and binary forms, with or without 
 * modification, are permitted provided that the following conditions are met:
 * 
 * 1. Redistributions of source code must retain the above copyright notice, 
 *    this list of conditions and the following disclaimer.
 * 
 * 2. Redistributions in binary form must reproduce the above copyright notice, 
 *    this list of conditions and the following disclaimer in the documentation 
 *    and/or other materials provided with the distribution.
 * 
 * 3. The name of the author may not be used to endorse or promote products 
 *    derived from this software without specific prior written permission.
 * 
 * THIS SOFTWARE IS PROVIDED BY THE AUTHOR "AS IS" AND ANY EXPRESS OR IMPLIED
 * WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF 
 * MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO 
 * EVENT SHALL THE AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, 
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, 
 * PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; 
 * OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, 
 * WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR 
 * OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF
 * ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 * 
 * ============================================================================
 * 
 */

/**
 * Defines the network that the current call is originating at
 */
define(INCOMING_NETWORK, $currentCall->network);

/**
 * API key acquired by visiting http://brewerydb.com/api/
 */
define(BREWERYDB_APIKEY, 'replace-with-your-key');

/**
 * API key acquired by visiting http://developer.yahoo.com
 */
define(YAHOO_APIKEY, 'replace-with-your-key');





// Bootstrap the app and get it started
BreweryMapUI::welcome();

/**
 * Contains all the UI for the BreweryMap Tropo application.  This app is
 * meant specifically for text or IM.
 *
 * Requires INCOMING_NETWORK to be set externally
 *
 * @see     http://tropo.com
 *
 */
class BreweryMapUI
{
    /**
     * Seconds to timeout each Tropo call
     */
    const MESSAGE_TIMEOUT = 20;

    /**
     * Interaction mode for the UI
     */
    const MODE = 'dtmf';

    /**
     * Number of attempts for each Tropo call
     */
    const ATTEMPTS = 1;

    /**
     * call session
     */
    protected $_session = null;

    /**
     * Welcome message and initialization of the app
     *
     */
    public static function welcome()
    {
        $initEvent = ask("", array("choices" => "[ANY]"));

        if (preg_match('/[0-9]{5}/i', $initEvent->value)) {
            BreweryMapUI::userZipEntry($initEvent);
            return;
        }

        $askOptions = array(
            'onChoice'    => 'BreweryMapUI::userZipEntry',
            'onBadChoice' => 'BreweryMapUI::userBadChoice',
            'choices'     => '[5 DIGITS]',
            'timeout'     => self::MESSAGE_TIMEOUT,
            'mode'        => self::MODE,
            'attempts'    => self::ATTEMPTS,
        );

        $prompt = 'Welcome to BreweryMap.com, powered by PintLabs. ';

        if (INCOMING_NETWORK == 'SMS') {
            $prompt .= 'To search breweries in an area, text back a zip code:';
        } else {
            $prompt .= 'To search breweries in an area, IM back a zip code:';
        }

        $event = ask($prompt, $askOptions);
    }

    /**
     * Called when the user enters a zip code into the application.  Will
     * geolocate the zip and return breweries within a certain radius.  Takes
     * a Tropo event object returned from an ask() method.
     *
     * @param event $event
     */
    public static function userZipEntry($event)
    {
        $zip = $event->value;

        $bmt = new BreweryMapTropo();

        try {
            $location = $bmt->geocode($zip);
            $breweries = $bmt->searchBreweries($location['lat'], $location['lng']);


            $options = '';

            if (count($breweries) == 0) {
                say('No breweries found within ' . $radius . ' miles of ' . $zip);
            } else {
                if (INCOMING_NETWORK == 'SMS') {
                    $prompt = 'For brewery info, text back ';
                } else {
                    $prompt = 'For brewery info, IM back ';
                }

                foreach ($breweries as $key => $value) {
                    if ($options != '') {
                        $options .= ',';
                        $prompt .= ', ';
                    }

                    $options .= $value['id'] . '(' . (1 + $key) . ')';
                    $prompt .= (1 + $key) . ' for ' . self::truncate($value['name']);
                }

                $prompt .= ' or 0 to start over';

                _log('options are: ' . $options);

                $askOptions = array(
                    'choices'     => $options,
                    'timeout'     => self::MESSAGE_TIMEOUT,
                    'mode'        => self::MODE,
                    'onChoice'    => 'BreweryMapUI::userBrewerySelection',
                    'onBadChoice' => 'BreweryMapUI::userBadChoice',
                    'attempts'    => self::ATTEMPTS,
                );

                $event = ask($prompt, $askOptions);
            }

        } catch (Exception $e) {
            self::error($e->getMessage());
            self::userStartOver();
        }
    }

    /**
     * Called when the user selects a brewery from a list of returned
     * breweries.  Takes a tropo event object that is passed from an ask() call.
     *
     * @param event $event
     */
    public static function userBrewerySelection($event)
    {
        $breweryId = $event->value;

        if ($breweryId == 0) {
            self::userStartOver();
            return;
        }

        $bmt = new BreweryMapTropo();

        try {
            $brewery = $bmt->getBrewery($breweryId);

            say($brewery['name'] . ' :: ' . $brewery['address'] . ' :: ' . $brewery['phone'] . ' :: ' . $brewery['website']);

        } catch (Exception $e) {
            self::error($e->getMessage());
        }

        self::userStartOver();
    }

    /**
     * Called to reset the application to the base starting point, allowing a
     * user to enter in a zip code and retrieve a list of breweries.
     *
     */
    public static function userStartOver()
    {
        $askOptions = array(
            'choices'     => '[5 DIGITS]',
            'timeout'     => self::MESSAGE_TIMEOUT,
            'mode'        => self::MODE,
            'onChoice'    => 'BreweryMapUI::userZipEntry',
            'onBadChoice' => 'BreweryMapUI::userBadChoice',
            'attempts'    => self::ATTEMPTS,
        );

        if (INCOMING_NETWORK == 'SMS') {
            $prompt = 'Text us a zip code to find breweries:';
        } else {
            $prompt = 'IM us a zip code to find breweries:';
        }
        

        $event = ask($prompt, $askOptions);
    }

    /**
     * Called if a user makes a bad choice at any of the messaging
     * opportunities.
     *
     */
    public static function userBadChoice()
    {
        say('Invalid entry, please try again');
    }

    /**
     * Called when an error is generated from the application
     *
     * @param string $message
     */
    public static function error($message)
    {
        say('Error: ' . $message);
    }

    /**
     * Truncates a string on a word, then adds the $etc on the end.
     *
     * @param string $string
     * @param int $length
     * @param string $etc
     *
     * @return string
     */
    public static function truncate($string, $length = 20, $etc = '...')
    {
        if ($length == 0) {
            return '';
        }

        if (strlen($string) > $length) {

            $length -= min($length, strlen($etc));

            $string = preg_replace('/\s+?(\S+)?$/', '', substr($string, 0, $length+1));

            return substr($string, 0, $length) . $etc;

        } else {
            return $string;
        }
    }
}

/**
 * Class to interact with the various web services that make up BreweryMap
 * and BreweryDB.
 *
 * Requires BREWERYDB_APIKEY and YAHOO_APIKEY constants to be set externally.
 *
 * @see http://brewerydb.com
 * @see http://where.yahooapis.com
 */
class BreweryMapTropo
{
    /**
     * Search radius, in miles
     */
    const RADIUS = 10;

    /**
     * Takes a zip code and uses the Yahoo geocode API to determine a location.
     *
     * @param string $zip
     * @return array('lat' => <float>, 'lng' => <float>)
     */
    public function geocode($zip)
    {
        $args = array(
            'postal'  => $zip,
            'flags'   => 'PC',
            'appId'   => YAHOO_APIKEY,
            'count'   => 1,
            'locale'  => 'en_US',
            'country' => 'USA'
        );

        $url = 'http://where.yahooapis.com/geocode?' . http_build_query($args);
        
        $geoData = unserialize($this->_getCurl($url));

        _log('Yahoo API: ' . $url);

        if ($geoData['ResultSet']['Error'] != 0 || $geoData['ResultSet']['Found'] == 0) {
            throw new Exception('Could not determine location.  Please try again.');
        }

        $data = array(
            'lat' => $geoData['ResultSet']['Result'][0]['latitude'],
            'lng' => $geoData['ResultSet']['Result'][0]['longitude']
        );

        _log('Found location at lat: ' . $data['lat'] . ', lng: ' . $data['lng']);

        return $data;
    }

    /**
     * Searches the BreweryDB for the correct breweries near the given
     * latitude and longitude coords
     *
     * @param float $lat
     * @param float $lng
     * @return array('id' => <int>, 'name' => <string>)
     */
    public function searchBreweries($lat, $lng)
    {

        $args = array(
            'apikey'   => BREWERYDB_APIKEY,
            'geo'      => 1,
            'lat'      => $lat,
            'lng'      => $lng,
            'radius'   => self::RADIUS,
            'format'   => 'json',
            'metadata' => 0,
            'units'    => 'miles'
        );

        $url = 'http://www.brewerydb.com/api/breweries?' . http_build_query($args);

        $results = json_decode($this->_getCurl($url), true);

        _log('BreweryDB API: ' . $url);

        if (isset($results['error'])) {
            throw new Exception($results['error']);
        }

        if (!isset($results['breweries']['brewery'][0])) {
            $results['breweries']['brewery'] = array($results['breweries']['brewery']);
        }

        $breweries = array();

        foreach ($results['breweries']['brewery'] as $r) {

            $breweries[] = array(
                'id'   => $r['id'],
                'name' => $r['name']
            );
        }

        return $breweries;
    }

    /**
     * Gets information about a given brewery.
     *
     * @param int $breweryId
     * @return array(
     *     'name'    => <string>
     *     'address' => <string>
     *     'phone'   => <string>
     *     'website' => <string>
     * )
     *
     */
    public function getBrewery($breweryId)
    {
        $args = array(
            'apikey'   => BREWERYDB_APIKEY,
            'metadata' => 1,
            'format'   => 'json',
        );

        $url = 'http://www.brewerydb.com/api/breweries/' . $breweryId . '?' . http_build_query($args);

        $results = json_decode($this->_getCurl($url), true);

        _log('BreweryDB API: ' . $url);

        if (isset($results['error'])) {
            throw new Exception($results['error']);
        }

        $brewery = $results['breweries']['brewery'];


        $data = array(
            'name'    => $brewery['name'],
            'address' => $brewery['address']['street_address'] . ' ' . $brewery['address']['extended-address'] . ', ' . $brewery['address']['locality'] . ' ' . $brewery['address']['region'] . ' ' . $brewery['address']['postal_code'],
            'phone'   => $brewery['phone'],
            'website' => $brewery['website'],
        );

        return $data;
    }

    /**
     * Makes a CURL call to a given URL
     *
     * @param string $url
     * @return string
     */
    protected function _getCurl($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $res = curl_exec ($ch);
        curl_close ($ch);

        return $res;
    }
}
?>