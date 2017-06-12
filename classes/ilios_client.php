<?php
/**
 * Ilios API client class.
 *
 * @package local_iliosapiclient
 */
namespace local_iliosapiclient;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/lib/filelib.php');

/**
 * Ilios API 1.0 Client for using JWT access tokens.
 *
 * @package    local_iliosapiclient
 * @author     Carson Tam <carson.tam@ucsf.edu>
 * @copyright  2017 The Regents of the University of California
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ilios_client extends \curl {

    /**
     * @var string Path-prefix to API routes.
     */
    const API_URL = '/api/v1';

    /**
     * @var string Authentication route path.
     */
    const AUTH_URL = '/auth';

    /**
     * @var int The token refresh interval.
     */
    const TOKEN_REFRESH_RATE = 86400; // 24 * 60 * 60 = 24 hours

    /**
     * @var string ISO 8601 formatted TTL for auth token.
     */
    const TOKEN_TTL = 'P7D'; // 7 days

    /**
     * @var string ilios hostname
     */
    private $_hostname = '';

    /**
     * @var string API base URL
     */
    private $_apibaseurl = '';

    /**
     * @var string The client ID.
     */
    private $_clientid = '';

    /**
     * @var string The client secret.
     */
    private $_clientsecret = '';

    /**
     * @var string JWT token
     */
    private $_accesstoken = null;

    /**
     * Constructor.
     * @param string    $hostname
     * @param string    $clientid
     * @param string    $clientsecret
     * @param \stdClass $accesstoken
     */
    public function __construct($hostname, $clientid = '', $clientsecret = '', $accesstoken = null) {
        parent::__construct();
        $this->_hostname = $hostname;
        $this->_apibaseurl = $this->_hostname . self::API_URL;
        $this->_clientid = $clientid;
        $this->_clientsecret = $clientsecret;

        if (empty($accesstoken)) {
            $this->_accesstoken = $this->get_new_token();
        } else {
            $this->_accesstoken = $accesstoken;
        }
    }

    /**
     * Get Ilios json object and return PHP object
     *
     * @param string       $object API object name (camel case)
     * @param array|string $filters   e.g. array('id' => 3)
     * @param array|string $sortorder e.g. array('title' => "ASC")
     * @return array
     * @throws \moodle_exception
     */
    public function get($object, $filters='', $sortorder='') {

        if (empty($this->_accesstoken)) {
            throw new \moodle_exception( 'Error: client token is not set.' );
        }

        if (empty($this->_accesstoken->expires) || (time() > $this->_accesstoken->expires)) {
            $this->_accesstoken = $this->get_new_token();

            if (empty($this->_accesstoken)) {
                throw new \moodle_exception( 'Error: unable to renew access token.' );
            }
        }

        $token = $this->_accesstoken->token;
        $this->resetHeader();
        $this->setHeader(array('X-JWT-Authorization: Token ' . $token));
        $url = $this->_apibaseurl . '/' . strtolower($object);
        $filterstring = '';
        if (is_array($filters)) {
            foreach ($filters as $param => $value) {
                if (is_array( $value )) {
                    foreach ($value as $val) {
                        $filterstring .= "&filters[$param][]=$val";
                    }
                } else {
                    $filterstring .= "&filters[$param]=$value";
                }
            }
        }

        if (is_array($sortorder)) {
            foreach ($sortorder as $param => $value) {
                $filterstring .="&order_by[$param]=$value";
            }
        }

        $limit = 50;
        $offset = 0;
        $retobj = array();
        $obj = null;

        do {
            $url .= "?limit=$limit&offset=$offset".$filterstring;
            $results = parent::get($url);
            $obj = $this->parse_result($results);

            if ($obj !== null && isset($obj->$object)) {
                if (!empty($obj->$object)) {
                    $retobj = array_merge($retobj, $obj->$object);
                    if (count($obj->$object) < $limit) {
                        $obj = null;
                    } else {
                        $offset += $limit;
                    }
                } else {
                    $obj = null;
                }
            } else {
                if ($obj !== null && isset($obj->code)) {
                    throw new \moodle_exception( 'Error '.$obj->code.': '.$obj->message );
                } else {
                    throw new \moodle_exception( print_r($obj, true) );
                }
            }
        } while ($obj !== null);

        return $retobj;
    }


    /**
     * Get Ilios json object by ID and return PHP object.
     *
     * @param string       $object API object name (camel case)
     * @param string|array $id e.g. array(1,2,3)
     * @return array|null
     */
    public function getbyid($object, $id) {
        if (is_numeric($id)) {
            $result = $this->getbyids($object, $id);

            if (isset($result[0])) {
                return $result[0];
            }
        }
        return null;
    }

    /**
     * Get Ilios json object by IDs and return PHP object.
     *
     * @param string       $object API object name (camel case)
     * @param string|array $ids e.g. array(1,2,3)
     * @return array
     * @throws \moodle_exception
     */
    public function getbyids($object, $ids='') {
        if (empty($this->_accesstoken)) {
            throw new \moodle_exception( 'Error' );
        }

        if (empty($this->_accesstoken->expires) || (time() > $this->_accesstoken->expires)) {
            $this->_accesstoken = $this->get_new_token();

            if (empty($this->_accesstoken)) {
                throw new \moodle_exception( 'Error' );
            }
        }

        $token = $this->_accesstoken->token;
        $this->resetHeader();
        $this->setHeader(array('X-JWT-Authorization: Token ' . $token));
        $url = $this->_apibaseurl . '/' . strtolower($object);

        $filterstrings = array();
        if (is_numeric($ids)) {
            $filterstrings[] = "?filters[id]=$ids";
        } elseif (is_array($ids)) {
            // fetch 10 at a time
            $offset  = 0;
            $length  = 10;
            $remains = count($ids);
            do {
                $slicedids = array_slice($ids, $offset, $length);
                $offset += $length;
                $remains -= count($slicedids);

                $filterstr = "?limit=$length";
                foreach ($slicedids as $id) {
                    $filterstr .= "&filters[id][]=$id";
                }
                $filterstrings[] = $filterstr;
            } while ($remains > 0);
        }

        $retobj = array();
        foreach ($filterstrings as $filterstr) {
            $results = parent::get($url.$filterstr);
            $obj = $this->parse_result($results);

            // if ($obj !== null && isset($obj->$object) && !empty($obj->$object)) {
            //     $retobj = array_merge($retobj, $obj->$object);
            // }

            if ($obj !== null && isset($obj->$object)) {
                if (!empty($obj->$object)) {
                    $retobj = array_merge($retobj, $obj->$object);
                }
            } else {
                if ($obj !== null && isset($obj->code)) {
                    throw new \moodle_exception( 'Error '.$obj->code.': '.$obj->message);
                } else {
                    throw new \moodle_exception( "Cannot find $object object in ".print_r($obj, true) );
                }
            }
        }
        return $retobj;
    }

    /**
     * Get new auth token.
     * @return \stdClass
     */
    protected function get_new_token() {
        $atoken = null;

        // Try refresh the current token first if it is set
        if (!empty($this->_accesstoken) && !empty($this->_accesstoken->token)) {
            $this->resetHeader();
            $this->setHeader(array('X-JWT-Authorization: Token ' . $this->_accesstoken->token));

            $result = parent::get($this->_hostname.self::AUTH_URL.'/token'.'?ttl='.self::TOKEN_TTL);
            $parsed_result = $this->parse_result($result);

            if (!empty($parsed_result->jwt)) {
                $atoken = new \stdClass();
                $atoken->token = $parsed_result->jwt;
                $atoken->expires = time() + self::TOKEN_REFRESH_RATE;
            }
        }

        // If token failed to refresh, use clientid and secret
        if (empty($atoken) && !empty($this->_clientid)) {
            $params = array('password' => $this->_clientsecret, 'username' => $this->_clientid);
            $result = parent::post($this->_hostname . self::AUTH_URL . '/login', $params);
            $parsed_result = $this->parse_result($result);

            if (!empty($parsed_result->jwt)) {
                $atoken = new \stdClass();
                $atoken->token = $parsed_result->jwt;
                $atoken->expires = time() + self::TOKEN_REFRESH_RATE;
            }
        }

        // If we still could not get a new token, just return the current one (or should we return null?)
        if (empty($atoken)) {
            return $this->_accesstoken;
        } else {
            return $atoken;
        }
    }

    /**
     * Decodes and returns the given JSON-encoded input.
     *
     * @param string $str A JSON-encoded string
     * @return \stdClass The JSON-decoded object representation of the given input.
     * @throws \moodle_exception
     */
    protected function parse_result($str) {
        if (empty($str)) {
            throw new \moodle_exception('error');
        }
        $result = json_decode($str);

        if (empty($result)) {
            throw new \moodle_exception('error');
        }

        if (isset($result->errors)) {
            throw new \moodle_exception(print_r($result->errors[0],true));
        }

        return $result;
    }

    /**
     * A method that returns the current access token.
     * @return \stdClass $accesstoken
     */
    public function getAccessToken() {
        return $this->_accesstoken;
    }
}


