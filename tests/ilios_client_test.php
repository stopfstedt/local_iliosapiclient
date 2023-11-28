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

namespace local_iliosapiclient;

use basic_testcase;
use curl;
use DateTime;
use Firebase\JWT\JWT;
use moodle_exception;
use PHPUnit\Framework\Constraint\StringContains;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\TextUI\XmlConfiguration\PHPUnit;

/**
 * @package    local_iliosapiclient
 * @category   test
 * @coversDefaultClass ilios_client
 * @copyright  The Regents of the University of California
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ilios_client_test extends basic_testcase {
    public const ILIOS_BASE_URL = 'http://localhost';
    protected MockObject $curl_mock;
    protected ilios_client $ilios_client;

    protected function setUp(): void {
        parent::setUp();
        $this->curl_mock = $this->createMock(curl::class);
        $this->ilios_client = new ilios_client(self::ILIOS_BASE_URL, $this->curl_mock);
    }

    protected function tearDown(): void {
        unset($this->ilios_client);
        unset($this->curl_mock);
        parent::tearDown();
    }

    public function test_get_with_default_arguments(): void {
        $access_token = $this->create_access_token();
        $data = [['id' => 100, 'title' => 'lorem ipsum'], ['id' => 101, 'title' => 'foo bar']];
        $this->curl_mock->expects($this->once())->method('resetHeader');
        $this->curl_mock->expects($this->once())->method('setHeader')->with(['X-JWT-Authorization: Token ' . $access_token]);
        $this->curl_mock->expects($this->once())
                ->method('get')
                ->with(self::ILIOS_BASE_URL . '/api/v3/courses?limit=1000&offset=0')
                ->willReturn(json_encode(['courses' => $data]));
        $result = $this->ilios_client->get($access_token, 'courses');
        $this->assertCount(2, $result);
        $this->assertEquals(100, $result[0]->id);
        $this->assertEquals('lorem ipsum', $result[0]->title);
        $this->assertEquals(101, $result[1]->id);
        $this->assertEquals('foo bar', $result[1]->title);
    }

    public function test_get_with_non_default_arguments(): void {
        $access_token = $this->create_access_token();
        $data = [[]];
        $this->curl_mock->expects($this->once())
                ->method('get')
                ->with(
                        self::ILIOS_BASE_URL .
                        '/api/v3/courses?limit=3000&offset=0&filters[zip]=1&filters[zap][]=a&filters[zap][]=b&order_by[title]=DESC'
                )
                ->willReturn(json_encode(['courses' => $data]));
        $this->ilios_client->get($access_token, 'courses', ['zip' => '1', 'zap' => ['a', 'b']], ['title' => 'DESC'], 3000);
    }

    public function test_get_fails_on_garbled_response(): void {
        $access_token = $this->create_access_token();
        $data = 'g00bleG0bble';
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('Failed to decode response.');
        $this->curl_mock->expects($this->once())->method('get')->willReturn($data);
        $this->ilios_client->get($access_token, 'courses');
    }

    public function test_get_fails_on_empty_response(): void {
        $access_token = $this->create_access_token();
        $data = '';
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('Empty response.');
        $this->curl_mock->expects($this->once())->method('get')->willReturn($data);
        $this->ilios_client->get($access_token, 'courses');
    }

    public function test_get_fails_on_error_response(): void {
        $access_token = $this->create_access_token();
        $data = ['errors' => ['something went wrong']];
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('The API responded with the following error: something went wrong.');
        $this->curl_mock->expects($this->once())->method('get')->willReturn(json_encode($data));
        $this->ilios_client->get($access_token, 'courses');
    }

    public function test_get_fails_on_code_and_message_response(): void {
        $access_token = $this->create_access_token();
        $data = ['code' => 403, 'message' => 'VERBOTEN!'];
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('Request failed. The API responded with the code: 403 and message: VERBOTEN!.');
        $this->curl_mock->expects($this->once())->method('get')->willReturn(json_encode($data));
        $this->ilios_client->get($access_token, 'courses');
    }

    /**
     * @dataProvider expired_token_provider
     */
    public function test_get_fails_with_expired_token(string $access_token): void {
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('API token is expired.');
        $this->ilios_client->get($access_token, 'does_not_matter');
    }

    /**
     * @dataProvider empty_token_provider
     */
    public function test_get_fails_with_empty_token(string $access_token): void {
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('API token is empty.');
        $this->ilios_client->get($access_token, 'does_not_matter');
    }

    /**
     * @dataProvider corrupted_token_provider
     */
    public function test_get_fails_with_corrupted_token(string $access_token): void {
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('Failed to decode API token.');
        $this->ilios_client->get($access_token, 'does_not_matter');
    }

    /**
     * @dataProvider invalid_token_provider
     */
    public function test_get_fails_with_invalid_token(string $access_token): void {
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('API token has an incorrect number of segments.');
        $this->ilios_client->get($access_token, 'does_not_matter');
    }

    public function test_get_by_id(): void {
        $access_token = $this->create_access_token();
        $data = [['id' => 100, 'title' => 'lorem ipsum']];
        $this->curl_mock->expects($this->once())->method('resetHeader');
        $this->curl_mock->expects($this->once())->method('setHeader')->with(['X-JWT-Authorization: Token ' . $access_token]);
        $this->curl_mock->expects($this->once())
                ->method('get')
                ->with(self::ILIOS_BASE_URL . '/api/v3/courses?filters[id]=100')
                ->willReturn(json_encode(['courses' => $data]));
        $result = $this->ilios_client->get_by_id($access_token, 'courses', 100);
        $this->assertEquals(100, $result->id);
        $this->assertEquals('lorem ipsum', $result->title);
    }

    public function test_get_by_id_with_empty_results(): void {
        $access_token = $this->create_access_token();
        $data = [];
        $this->curl_mock->expects($this->once())->method('get')->willReturn(json_encode(['courses' => $data]));
        $result = $this->ilios_client->get_by_id($access_token, 'courses', 100);
        $this->assertNull($result);
    }

    public function test_get_by_id_with_non_numeric_id(): void {
        $result = $this->ilios_client->get_by_id('lorem_ipsum', 'does_not_matter', 'a');
        $this->assertNull($result);
    }

    public function test_get_by_id_fails_on_garbled_response(): void {
        $access_token = $this->create_access_token();
        $data = 'g00bleG0bble';
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('Failed to decode response.');
        $this->curl_mock->expects($this->once())->method('get')->willReturn($data);
        $this->ilios_client->get_by_id($access_token, 'courses', 100);
    }

    public function test_get_by_id_fails_on_empty_response(): void {
        $access_token = $this->create_access_token();
        $data = '';
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('Empty response.');
        $this->curl_mock->expects($this->once())->method('get')->willReturn($data);
        $this->ilios_client->get_by_id($access_token, 'courses', 100);
    }

    public function test_get_by_id_fails_on_error_response(): void {
        $access_token = $this->create_access_token();
        $data = ['errors' => ['something went wrong']];
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('The API responded with the following error: something went wrong.');
        $this->curl_mock->expects($this->once())->method('get')->willReturn(json_encode($data));
        $this->ilios_client->get_by_id($access_token, 'courses', 100);
    }

    public function test_get_by_id_fails_on_code_and_message_response(): void {
        $access_token = $this->create_access_token();
        $data = ['code' => 403, 'message' => 'VERBOTEN!'];
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('Request failed. The API responded with the code: 403 and message: VERBOTEN!.');
        $this->curl_mock->expects($this->once())->method('get')->willReturn(json_encode($data));
        $this->ilios_client->get_by_id($access_token, 'courses', 100);
    }

    /**
     * @dataProvider expired_token_provider
     */
    public function test_get_by_id_fails_with_expired_token(string $access_token): void {
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('API token is expired.');
        $this->ilios_client->get_by_id($access_token, 'does_not_matter', 100);
    }

    /**
     * @dataProvider empty_token_provider
     */
    public function test_get_by_id_fails_with_empty_token(string $access_token): void {
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('API token is empty.');
        $this->ilios_client->get_by_id($access_token, 'does_not_matter', 100);
    }

    /**
     * @dataProvider corrupted_token_provider
     */
    public function test_get_by_id_fails_with_corrupted_token(string $access_token): void {
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('Failed to decode API token.');
        $this->ilios_client->get_by_id($access_token, 'does_not_matter', 100);
    }

    /**
     * @dataProvider invalid_token_provider
     */
    public function test_get_by_id_fails_with_invalid_token(string $access_token): void {
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('API token has an incorrect number of segments.');
        $this->ilios_client->get_by_id($access_token, 'does_not_matter', 100);
    }

    public function test_get_by_ids(): void {
        $access_token = $this->create_access_token();
        $data = [['id' => 100, 'title' => 'lorem ipsum'], ['id' => 101, 'title' => 'foo bar']];
        $this->curl_mock->expects($this->once())->method('resetHeader');
        $this->curl_mock->expects($this->once())->method('setHeader')->with(['X-JWT-Authorization: Token ' . $access_token]);
        $this->curl_mock->expects($this->once())
                ->method('get')
                ->with(self::ILIOS_BASE_URL . '/api/v3/courses?filters[id]=100')
                ->willReturn(json_encode(['courses' => $data]));
        $result = $this->ilios_client->get_by_ids($access_token, 'courses', 100);
        $this->assertCount(2, $result);
        $this->assertEquals(100, $result[0]->id);
        $this->assertEquals('lorem ipsum', $result[0]->title);
        $this->assertEquals(101, $result[1]->id);
        $this->assertEquals('foo bar', $result[1]->title);
    }

    public function test_get_by_ids_in_batch_mode(): void {
        $access_token = $this->create_access_token();
        $ids = range(1, 120);
        $data1 = [['id' => 1, 'title' => 'foo']];
        $data2 = [['id' => 52, 'title' => 'bar'], ['id' => 55, 'title' => 'bier']];
        $data3 = [['id' => 111, 'title' => 'baz']];
        $this->curl_mock->expects($this->exactly(3))
                ->method('get')
                ->with(new StringContains('limit=50'))
                ->willReturn(
                        json_encode(['courses' => $data1]),
                        json_encode(['courses' => $data2]),
                        json_encode(['courses' => $data3]),
                );
        $result = $this->ilios_client->get_by_ids($access_token, 'courses', $ids, 50);
        $this->assertCount(4, $result);
        $this->assertEquals(1, $result[0]->id);
        $this->assertEquals(52, $result[1]->id);
        $this->assertEquals(55, $result[2]->id);
        $this->assertEquals(111, $result[3]->id);
    }

    public function test_get_by_ids_with_non_numeric_non_array_input(): void {
        $access_token = $this->create_access_token();
        $result = $this->ilios_client->get_by_ids($access_token, 'courses', 'abc');
        $this->assertEquals([], $result);
    }

    public function test_get_by_ids_with_empty_results(): void {
        $access_token = $this->create_access_token();
        $data = [];
        $this->curl_mock->expects($this->once())->method('get')->willReturn(json_encode(['courses' => $data]));
        $result = $this->ilios_client->get_by_ids($access_token, 'courses', [100]);
        $this->assertEquals([], $result);
    }

    public function test_get_by_ids_fails_on_garbled_response(): void {
        $access_token = $this->create_access_token();
        $data = 'g00bleG0bble';
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('Failed to decode response.');
        $this->curl_mock->expects($this->once())->method('get')->willReturn($data);
        $this->ilios_client->get_by_ids($access_token, 'courses', [100]);
    }

    public function test_get_by_ids_fails_on_empty_response(): void {
        $access_token = $this->create_access_token();
        $data = '';
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('Empty response.');
        $this->curl_mock->expects($this->once())->method('get')->willReturn($data);
        $this->ilios_client->get_by_ids($access_token, 'courses', [100]);
    }

    public function test_get_by_ids_fails_on_error_response(): void {
        $access_token = $this->create_access_token();
        $data = ['errors' => ['something went wrong']];
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('The API responded with the following error: something went wrong.');
        $this->curl_mock->expects($this->once())->method('get')->willReturn(json_encode($data));
        $this->ilios_client->get_by_ids($access_token, 'courses', [100]);
    }

    public function test_get_by_ids_fails_on_code_and_message_response(): void {
        $access_token = $this->create_access_token();
        $data = ['code' => 403, 'message' => 'VERBOTEN!'];
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('Request failed. The API responded with the code: 403 and message: VERBOTEN!.');
        $this->curl_mock->expects($this->once())->method('get')->willReturn(json_encode($data));
        $this->ilios_client->get_by_ids($access_token, 'courses', [100]);
    }

    /**
     * @dataProvider expired_token_provider
     */
    public function test_get_by_ids_fails_with_expired_token(string $access_token): void {
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('API token is expired.');
        $this->ilios_client->get_by_ids($access_token, 'does_not_matter', 100);
    }

    /**
     * @dataProvider empty_token_provider
     */
    public function test_get_by_ids_fails_with_empty_token(string $access_token): void {
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('API token is empty.');
        $this->ilios_client->get_by_ids($access_token, 'does_not_matter', 100);
    }

    /**
     * @dataProvider corrupted_token_provider
     */
    public function test_get_by_ids_fails_with_corrupted_token(string $access_token): void {
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('Failed to decode API token.');
        $this->ilios_client->get_by_ids($access_token, 'does_not_matter', 100);
    }

    /**
     * @dataProvider invalid_token_provider
     */
    public function test_get_by_ids_fails_with_invalid_token(string $access_token): void {
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('API token has an incorrect number of segments.');
        $this->ilios_client->get_by_ids($access_token, 'does_not_matter', 100);
    }

    public function empty_token_provider(): array {
        return [
                [''],
                ['   '],
        ];
    }

    public function corrupted_token_provider(): array {
        return [
                ['AAAAA.BBBBB.CCCCCC'], // has the right number of segments, but bunk payload
        ];
    }

    public function invalid_token_provider(): array {
        return [
                ['AAAA'], // not enough segments
                ['AAAA.BBBBB'], // still not enough
                ['AAAA.BBBBB.CCCCC.DDDDD'], // too many segments
        ];
    }

    public function expired_token_provider(): array {
        $key = 'doesnotmatterhere';
        $payload = ['exp' => (new DateTime('-2 days'))->getTimestamp()];
        $jwt = JWT::encode($payload, $key, 'HS256');
        return [
                [$jwt]
        ];
    }

    /**
     * Creates and returns an un-expired JWT, to be used as access token.
     * This token will pass client-side token validation.
     *
     * @return string
     */
    protected function create_access_token(): string {
        $key = 'doesnotmatterhere';
        $payload = ['exp' => (new DateTime('10 days'))->getTimestamp()];
        return JWT::encode($payload, $key, 'HS256');
    }
}
