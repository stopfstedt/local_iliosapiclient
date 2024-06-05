<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Ilios API client class.
 *
 * @package    local_iliosapiclient
 * @copyright  The Regents of the University of California
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_iliosapiclient;

use curl;
use Firebase\JWT\JWT;
use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/lib/filelib.php');

/**
 * Ilios API client for using JWT access tokens.
 *
 * @package    local_iliosapiclient
 * @copyright  The Regents of the University of California
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ilios_client {

    /**
     * @var int Default batch size ("limit") of records to pull per request from the API.
     */
    const DEFAULT_BATCH_SIZE = 1000;

    /**
     * @var string Path-prefix to API routes.
     */
    const API_URL = '/api/v3';

    /**
     * @var string The Ilios base URL.
     */
    protected string $iliosbaseurl;

    /**
     * @var curl The cURL client.
     */
    protected curl $curl;

    /**
     * Constructor.
     *
     * @param string $iliosbaseurl The Ilios base URL
     * @param curl $curl the cURL client
     */
    public function __construct(string $iliosbaseurl, curl $curl) {
        $this->iliosbaseurl = $iliosbaseurl;
        $this->curl = $curl;
    }

    /**
     * Returns the Ilios API base URL.
     *
     * @return string
     */
    protected function get_api_base_url(): string {
        return $this->iliosbaseurl . self::API_URL;
    }

    /**
     * Queries the Ilios API for data of a given entity type, with given filters, sort orders, and size limits.
     *
     * @param string $accesstoken the Ilios API access token
     * @param string $entitytype the entity type of data to retrieve
     * @param mixed $filters e.g. array('id' => 3)
     * @param mixed $sortorder e.g. array('title' => "ASC")
     * @param int $batchsize the maximum number of entities to retrieve per batch.
     * @return array
     * @throws moodle_exception
     */
    public function get(
        string $accesstoken,
        string $entitytype,
        mixed $filters = '',
        mixed $sortorder = '',
        int $batchsize = self::DEFAULT_BATCH_SIZE
    ): array {

        $this->validate_access_token($accesstoken);
        $this->curl->resetHeader();
        $this->curl->setHeader(['X-JWT-Authorization: Token ' . $accesstoken]);
        $url = $this->get_api_base_url() . '/' . strtolower($entitytype);
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

        $limit = $batchsize;
        $offset = 0;
        $retobj = [];

        do {
            $url .= "?limit=$limit&offset=$offset" . $filterstring;
            $results = $this->curl->get($url);
            $obj = $this->parse_result($results);

            if (isset($obj->$entitytype)) {
                if (!empty($obj->$entitytype)) {
                    $retobj = array_merge($retobj, $obj->$entitytype);
                    if (count($obj->$entitytype) < $limit) {
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
                    throw new moodle_exception('errorresponseentitynotfound', 'local_iliosapiclient', '', $entitytype);
                }
            }
        } while ($obj !== null);

        return $retobj;
    }

    /**
     * Retrieves an entity from the API by its ID and type.
     *
     * @param string $accesstoken the Ilios API access token
     * @param string $entitytype the entity type
     * @param mixed $id the entity ID
     * @return mixed
     * @throws moodle_exception
     */
    public function get_by_id(string $accesstoken, string $entitytype, mixed $id): mixed {
        if (is_numeric($id)) {
            $result = $this->get_by_ids($accesstoken, $entitytype, $id, 1);

            if (isset($result[0])) {
                return $result[0];
            }
        }
        return null;
    }

    /**
     * Retrieves entities from the API by their IDs and type.
     *
     * @param string $accesstoken the Ilios API access token
     * @param string $entitytype the entity type
     * @param mixed $ids e.g. a single entity ID, or an array of IDs
     * @param int $batchsize the maximum number of entities to retrieve per batch.
     * @return array
     * @throws moodle_exception
     */
    public function get_by_ids(
        string $accesstoken,
        string $entitytype,
        mixed $ids = '',
        int $batchsize = self::DEFAULT_BATCH_SIZE
    ): array {
        $this->validate_access_token($accesstoken);
        $this->curl->resetHeader();
        $this->curl->setHeader(['X-JWT-Authorization: Token ' . $accesstoken]);
        $url = $this->get_api_base_url() . '/' . strtolower($entitytype);

        $filterstrings = [];
        if (is_numeric($ids)) {
            $filterstrings[] = "?filters[id]=$ids";
        } else if (is_array($ids) && !empty($ids)) {
            $offset = 0;
            $length = $batchsize;
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

        $retobj = [];
        foreach ($filterstrings as $filterstr) {
            $results = $this->curl->get($url . $filterstr);
            $obj = $this->parse_result($results);

            if (isset($obj->$entitytype)) {
                if (!empty($obj->$entitytype)) {
                    $retobj = array_merge($retobj, $obj->$entitytype);
                }
            } else {
                if (isset($obj->code)) {
                    throw new moodle_exception('errorresponsewithcodeandmessage', 'local_iliosapiclient', '', $obj);
                } else {
                    throw new moodle_exception('errorresponseentitynotfound', 'local_iliosapiclient', '', $entitytype);
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
                (string) $result->errors[0],
            );
        }

        return $result;
    }

    /**
     * Validates the given access token.
     * Will throw an exception if the token is not valid - that happens if the token is not set, cannot be decoded, or is expired.
     *
     * @param string $accesstoken the Ilios API access token
     * @return void
     * @throws moodle_exception
     */
    protected function validate_access_token(string $accesstoken): void {
        // Check if token is blank.
        if ('' === trim($accesstoken)) {
            throw new moodle_exception('erroremptytoken', 'local_iliosapiclient');
        }

        // Decode token payload. will throw an exception if this fails.
        $tokenpayload = $this->get_access_token_payload($accesstoken);

        // Check if token is expired.
        if ($tokenpayload['exp'] < time()) {
            throw new moodle_exception('errortokenexpired', 'local_iliosapiclient');
        }
    }

    /**
     * Decodes and retrieves the payload of the given access token.
     *
     * @param string $accesstoken the Ilios API access token
     * @return array the token payload as key/value pairs.
     * @throws moodle_exception
     */
    protected function get_access_token_payload(string $accesstoken): array {
        $parts = explode('.', $accesstoken);
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
