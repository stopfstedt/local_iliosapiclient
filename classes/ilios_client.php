<?php
/**
 * Ilios API client class.
 *
 * @package local_iliosapiclient
 */

namespace local_iliosapiclient;

use curl;
use Firebase\JWT\JWT;
use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

/* @global $CFG */
require_once($CFG->dirroot . '/lib/filelib.php');

/**
 * Ilios API 1.0 Client for using JWT access tokens.
 *
 * @package    local_iliosapiclient
 * @author     Carson Tam <carson.tam@ucsf.edu>
 * @author     Stefan Topfstedt <stefan.topfstedt@ucsf.edu>
 * @copyright  The Regents of the University of California
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ilios_client {

    /**
     * Default batch size ("limit") of records to pull per request from the API.
     *
     * @var int
     */
    const DEFAULT_BATCH_SIZE = 1000;

    /**
     * @var string Path-prefix to API routes.
     */
    const API_URL = '/api/v3';

    /**
     * @param string $ilios_base_url The Ilios base URL
     * @param curl $curl the cURL client
     */
    public function __construct(protected string $ilios_base_url, protected curl $curl) {
    }

    protected function get_api_base_url(): string {
        return $this->ilios_base_url . self::API_URL;
    }

    /**
     * Queries the Ilios API on a given endpoint, with given filters, sort orders, and size limits.
     *
     * @param string $access_token the Ilios API access token
     * @param string $object the API endpoint/entity name
     * @param array|string $filters e.g. array('id' => 3)
     * @param array|string $sortorder e.g. array('title' => "ASC")
     * @param int $batchSize Number of objects to retrieve per batch.
     * @return array a list of retrieved data points
     * @throws moodle_exception
     */
    public function get(string $access_token, string $object, mixed $filters = '', mixed $sortorder = '',
            int $batchSize = self::DEFAULT_BATCH_SIZE): array {

        $this->validate_access_token($access_token);
        $this->curl->resetHeader();
        $this->curl->setHeader(array('X-JWT-Authorization: Token ' . $access_token));
        $url = $this->get_api_base_url() . '/' . strtolower($object);
        $filterstring = '';
        if (is_array($filters)) {
            foreach ($filters as $param => $value) {
                if (is_array($value)) {
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
                $filterstring .= "&order_by[$param]=$value";
            }
        }

        $limit = $batchSize;
        $offset = 0;
        $retobj = array();
        $obj = null;

        do {
            $url .= "?limit=$limit&offset=$offset" . $filterstring;
            $results = $this->curl->get($url);
            $obj = $this->parse_result($results);

            if (isset($obj->$object)) {
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
                if (isset($obj->code)) {
                    throw new moodle_exception('errorresponsewithcodeandmessage', 'local_iliosapiclient', '', $obj);
                } else {
                    throw new moodle_exception('errorresponseentitynotfound', 'local_iliosapiclient', '', $object);
                }
            }
        } while ($obj !== null);

        return $retobj;
    }

    /**
     * @deprecated
     */
    public function getbyid(string $access_token, string $object, mixed $id): mixed {
        trigger_error('Method ' . __METHOD__ . ' is deprecated, use ilios_client::get_by_id() instead. ', E_USER_DEPRECATED);
        return $this->get_by_id($access_token, $object, $id);
    }

    /**
     * Get Ilios json object by ID and return PHP object.
     *
     * @param string $access_token the Ilios API access token
     * @param string $object API object name (camel case)
     * @param string|array $id e.g. array(1,2,3)
     * @return mixed
     * @throws moodle_exception
     */
    public function get_by_id(string $access_token, string $object, mixed $id): mixed {
        if (is_numeric($id)) {
            $result = $this->get_by_ids($access_token, $object, $id, 1);

            if (isset($result[0])) {
                return $result[0];
            }
        }
        return null;
    }

    /**
     * @deprecated
     */
    public function getbyids(string $access_token, string $object, mixed $ids = '', int $batchSize = self::DEFAULT_BATCH_SIZE): array {
        trigger_error('Method ' . __METHOD__ . ' is deprecated, use ilios_client::get_by_ids() instead. ', E_USER_DEPRECATED);
        return $this->get_by_ids($access_token, $object, $ids, $batchSize);
    }

    /**
     * Get Ilios json object by IDs and return PHP object.
     *
     * @param string $access_token the Ilios API access token
     * @param string $object API object name (camel case)
     * @param string|array $ids e.g. array(1,2,3)
     * @param int $batchSize
     * @return array
     * @throws moodle_exception
     */
    public function get_by_ids(string $access_token, string $object, mixed $ids = '', int $batchSize = self::DEFAULT_BATCH_SIZE): array {
        $this->validate_access_token($access_token);
        $this->curl->resetHeader();
        $this->curl->setHeader(array('X-JWT-Authorization: Token ' . $access_token));
        $url = $this->get_api_base_url() . '/' . strtolower($object);

        $filterstrings = array();
        if (is_numeric($ids)) {
            $filterstrings[] = "?filters[id]=$ids";
        } else if (is_array($ids) && !empty($ids)) {
            $offset = 0;
            $length = $batchSize;
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
            $results = $this->curl->get($url . $filterstr);
            $obj = $this->parse_result($results);

            // if ($obj !== null && isset($obj->$object) && !empty($obj->$object)) {
            //     $retobj = array_merge($retobj, $obj->$object);
            // }

            if (isset($obj->$object)) {
                if (!empty($obj->$object)) {
                    $retobj = array_merge($retobj, $obj->$object);
                }
            } else {
                if (isset($obj->code)) {
                    throw new moodle_exception('errorresponsewithcodeandmessage', 'local_iliosapiclient', '', $obj);
                } else {
                    throw new moodle_exception('errorresponseentitynotfound', 'local_iliosapiclient', '', $object);
                }
            }
        }
        return $retobj;
    }

    /**
     * Decodes and returns the given JSON-encoded input.
     *
     * @param string $str A JSON-encoded string
     * @return stdClass The JSON-decoded object representation of the given input.
     * @throws moodle_exception
     */
    protected function parse_result(string $str): stdClass {
        if (empty($str)) {
            throw new moodle_exception('erroremptyresponse', 'local_iliosapiclient');
        }
        $result = json_decode($str);

        if (empty($result)) {
            throw new moodle_exception('errordecodingresponse', 'local_iliosapiclient');
        }

        if (isset($result->errors)) {
            throw new moodle_exception(
                    'errorresponsewitherror',
                    'local_iliosapiclient',
                    '',
                    print_r($result->errors[0], true)
            );
        }

        return $result;
    }

    /**
     * Validates the given access token.
     * Will throw an exception if the token is not valid - that happens if the token is not set, cannot be decoded, or is expired.
     *
     * @param string $access_token the Ilios API access token
     * @return void
     * @throws moodle_exception
     */
    protected function validate_access_token(string $access_token): void {
        // check if token is blank
        if ('' === trim($access_token)) {
            throw new moodle_exception('erroremptytoken', 'local_iliosapiclient');
        }

        // decode token payload. will throw an exception if this fails.
        $token_payload = $this->get_access_token_payload($access_token);

        // check if token is expired
        if ($token_payload['exp'] < time()) {
            throw new moodle_exception('errortokenexpired', 'local_iliosapiclient');
        }
    }

    /**
     * Decodes and retrieves the payload of the given access token.
     *
     * @param string $access_token the Ilios API access token
     * @return array the token payload as key/value pairs.
     * @throws moodle_exception
     */
    protected function get_access_token_payload(string $access_token): array {
        $parts = explode('.', $access_token);
        if (count($parts) !== 3) {
            throw new moodle_exception('errorinvalidnumbertokensegments', 'local_iliosapiclient');
        }
        $payload = json_decode(JWT::urlsafeB64Decode($parts[1]), true);
        if (!$payload) {
            throw new moodle_exception('errordecodingtoken', 'local_iliosapiclient');
        }
        return $payload;
    }
}


