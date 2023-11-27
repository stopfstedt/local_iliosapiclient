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
     * @param string $access_token the Ilios API access token
     * @param curl $curl the cURL client
     */
    public function __construct(protected string $ilios_base_url, protected string $access_token, protected curl $curl) {
    }

    protected function get_api_base_url(): string {
        return $this->ilios_base_url . self::API_URL;
    }

    /**
     * Queries the Ilios API on a given endpoint, with given filters, sort orders, and size limits.
     *
     * @param string $object the API endpoint/entity name
     * @param array|string $filters e.g. array('id' => 3)
     * @param array|string $sortorder e.g. array('title' => "ASC")
     * @param int $batchSize Number of objects to retrieve per batch.
     * @return array a list of retrieved data points
     * @throws moodle_exception
     */
    public function get(string $object, mixed $filters = '', mixed $sortorder = '',
            int $batchSize = self::DEFAULT_BATCH_SIZE): array {

        $this->validate_access_token();
        $this->curl->resetHeader();
        $this->curl->setHeader(array('X-JWT-Authorization: Token ' . $this->access_token));
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
                    throw new moodle_exception('Error ' . $obj->code . ': ' . $obj->message);
                } else {
                    throw new moodle_exception(print_r($obj, true));
                }
            }
        } while ($obj !== null);

        return $retobj;
    }

    /**
     * @deprecated
     */
    public function getbyid(string $object, mixed $id): mixed {
        trigger_error('Method ' . __METHOD__ . ' is deprecated, use ilios_client::get_by_id() instead. ', E_USER_DEPRECATED);
        return $this->get_by_id($object, $id);
    }

    /**
     * Get Ilios json object by ID and return PHP object.
     *
     * @param string $object API object name (camel case)
     * @param string|array $id e.g. array(1,2,3)
     * @return mixed
     * @throws moodle_exception
     */
    public function get_by_id(string $object, mixed $id): mixed {
        if (is_numeric($id)) {
            $result = $this->get_by_ids($object, $id, 1);

            if (isset($result[0])) {
                return $result[0];
            }
        }
        return null;
    }

    /**
     * @deprecated
     */
    public function getbyids(string $object, mixed $ids = '', int $batchSize = self::DEFAULT_BATCH_SIZE): array {
        trigger_error('Method ' . __METHOD__ . ' is deprecated, use ilios_client::get_by_ids() instead. ', E_USER_DEPRECATED);
        return $this->get_by_ids($object, $ids, $batchSize);
    }

    /**
     * Get Ilios json object by IDs and return PHP object.
     *
     * @param string $object API object name (camel case)
     * @param string|array $ids e.g. array(1,2,3)
     * @param int $batchSize
     * @return array
     * @throws moodle_exception
     */
    public function get_by_ids(string $object, mixed $ids = '', int $batchSize = self::DEFAULT_BATCH_SIZE): array {
        $this->validate_access_token();
        $this->curl->resetHeader();
        $this->curl->setHeader(array('X-JWT-Authorization: Token ' . $this->access_token));
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

            if ($obj !== null && isset($obj->$object)) {
                if (!empty($obj->$object)) {
                    $retobj = array_merge($retobj, $obj->$object);
                }
            } else {
                if ($obj !== null && isset($obj->code)) {
                    throw new moodle_exception('Error ' . $obj->code . ': ' . $obj->message);
                } else {
                    throw new moodle_exception("Cannot find $object object in " . print_r($obj, true));
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
            throw new moodle_exception('empty response');
        }
        $result = json_decode($str);

        if (empty($result)) {
            throw new moodle_exception('failed to decode response');
        }

        if (isset($result->errors)) {
            throw new moodle_exception(print_r($result->errors[0], true));
        }

        return $result;
    }

    /**
     * Validates the given access token.
     * Will throw an exception if the token is not valid - that happens if the token is not set, cannot be decoded, or is expired.
     *
     * @return void
     * @throws moodle_exception
     */
    protected function validate_access_token(): void {
        // check if token is empty
        if (empty($this->access_token)) {
            throw new moodle_exception('access token is not set');
        }

        // decode token payload. will throw an exception if this fails.
        $token_payload = $this->get_values_from_access_token($this->access_token);

        // check if token is expired
        if ($token_payload['exp'] < time()) {
            throw new moodle_exception('token is expired.');
        }

        // @todo check if token is service-account based - the `tid` attribute must be present.
    }

    /**
     * Decodes and retrieves the payload of the given access token.
     *
     * @param string $jwt the token
     * @return array the token payload as key/value pairs.
     * @throws moodle_exception
     */
    protected function get_values_from_access_token(string $jwt): array {
        $parts = explode('.', $jwt);
        $payload = json_decode(JWT::urlsafeB64Decode($parts[1]), true);
        if (!$payload) {
            throw new moodle_exception('failed to decode token');
        }
        return $payload;
    }
}


