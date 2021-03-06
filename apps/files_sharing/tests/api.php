<?php
/**
 * ownCloud
 *
 * @author Bjoern Schiessle
 * @copyright 2013 Bjoern Schiessle <schiessle@owncloud.com>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

require_once __DIR__ . '/../../../lib/base.php';

use OCA\Files\Share;

/**
 * Class Test_Files_Sharing_Api
 */
class Test_Files_Sharing_Api extends \PHPUnit_Framework_TestCase {

	const TEST_FILES_SHARING_API_USER1 = "test-share-user1";
	const TEST_FILES_SHARING_API_USER2 = "test-share-user2";

	public $stateFilesEncryption;
	public $filename;
	public $data;
	/**
	 * @var OC_FilesystemView
	 */
	public $view;
	public $folder;

	public static function setUpBeforeClass() {
		// reset backend
		\OC_User::clearBackends();
		\OC_User::useBackend('database');

		// clear share hooks
		\OC_Hook::clear('OCP\\Share');
		\OC::registerShareHooks();
		\OCP\Util::connectHook('OC_Filesystem', 'setup', '\OC\Files\Storage\Shared', 'setup');

		// create users
		self::loginHelper(\Test_Files_Sharing_Api::TEST_FILES_SHARING_API_USER1, true);
		self::loginHelper(\Test_Files_Sharing_Api::TEST_FILES_SHARING_API_USER2, true);

	}

	function setUp() {
		$this->data = 'foobar';
		$this->view = new \OC_FilesystemView('/' . \Test_Files_Sharing_Api::TEST_FILES_SHARING_API_USER1 . '/files');

		$this->folder = '/folder_share_api_test';

		$this->filename = 'share-api-test.txt';

		// remember files_encryption state
		$this->stateFilesEncryption = \OC_App::isEnabled('files_encryption');

		 //we don't want to tests with app files_encryption enabled
		\OC_App::disable('files_encryption');


		$this->assertTrue(!\OC_App::isEnabled('files_encryption'));

		// save file with content
		$this->view->file_put_contents($this->filename, $this->data);
		$this->view->mkdir($this->folder);
		$this->view->file_put_contents($this->folder.'/'.$this->filename, $this->data);

	}

	function tearDown() {
		$this->view->unlink($this->filename);
		$this->view->deleteAll($this->folder);
		// reset app files_encryption
		if ($this->stateFilesEncryption) {
			\OC_App::enable('files_encryption');
		} else {
			\OC_App::disable('files_encryption');
		}
	}

	public static function tearDownAfterClass() {

		// cleanup users
		\OC_User::deleteUser(\Test_Files_Sharing_Api::TEST_FILES_SHARING_API_USER1);
		\OC_User::deleteUser(\Test_Files_Sharing_Api::TEST_FILES_SHARING_API_USER2);
	}

	/**
	 * @medium
	 */
	function testCreateShare() {

		//login as user1
		\Test_Files_Sharing_Api::loginHelper(\Test_Files_Sharing_Api::TEST_FILES_SHARING_API_USER1);

		// share to user

		// simulate a post request
		$_POST['path'] = $this->filename;
		$_POST['shareWith'] = \Test_Files_Sharing_Api::TEST_FILES_SHARING_API_USER2;
		$_POST['shareType'] = \OCP\Share::SHARE_TYPE_USER;

		$result = Share\Api::createShare(array());

		$this->assertTrue($result->succeeded());
		$data = $result->getData();

		$share = $this->getShareFromId($data['id']);

		$items = \OCP\Share::getItemShared('file', $share['item_source']);

		$this->assertTrue(!empty($items));

		// share link

		// simulate a post request
		$_POST['path'] = $this->folder;
		$_POST['shareType'] = \OCP\Share::SHARE_TYPE_LINK;

		$result = Share\Api::createShare(array());

		// check if API call was successful
		$this->assertTrue($result->succeeded());

		$data = $result->getData();

		// check if we have a token
		$this->assertTrue(is_string($data['token']));

		$share = $this->getShareFromId($data['id']);

		$items = \OCP\Share::getItemShared('file', $share['item_source']);

		$this->assertTrue(!empty($items));

		$fileinfo = $this->view->getFileInfo($this->filename);

		\OCP\Share::unshare('file', $fileinfo['fileid'], \OCP\Share::SHARE_TYPE_USER,
				\Test_Files_Sharing_Api::TEST_FILES_SHARING_API_USER2);

		$fileinfo = $this->view->getFileInfo($this->folder);

		\OCP\Share::unshare('folder', $fileinfo['fileid'], \OCP\Share::SHARE_TYPE_LINK, null);



	}

	/**
	 * @medium
	 * @depends testCreateShare
	 */
	function testGetAllShares() {

		$fileinfo = $this->view->getFileInfo($this->filename);

		\OCP\Share::shareItem('file', $fileinfo['fileid'], \OCP\Share::SHARE_TYPE_USER,
		\Test_Files_Sharing_Api::TEST_FILES_SHARING_API_USER2, 31);

		$result = Share\Api::getAllShares(array());

		$this->assertTrue($result->succeeded());

        // test should return two shares created from testCreateShare()
		$this->assertTrue(count($result->getData()) === 1);

		\OCP\Share::unshare('file', $fileinfo['fileid'], \OCP\Share::SHARE_TYPE_USER,
				\Test_Files_Sharing_Api::TEST_FILES_SHARING_API_USER2);
	}

	/**
	 * @medium
	 * @depends testCreateShare
	 */
	function testGetShareFromSource() {

		$fileInfo = $this->view->getFileInfo($this->filename);

		\OCP\Share::shareItem('file', $fileInfo['fileid'], \OCP\Share::SHARE_TYPE_USER,
				\Test_Files_Sharing_Api::TEST_FILES_SHARING_API_USER2, 31);

		\OCP\Share::shareItem('file', $fileInfo['fileid'], \OCP\Share::SHARE_TYPE_LINK,
				null, 1);

		$params = array('itemSource' => $fileInfo['fileid']);

		$result = Share\Api::getShare($params);

		$this->assertTrue($result->succeeded());

        // test should return one share created from testCreateShare()
		$this->assertTrue(count($result->getData()) === 2);

		\OCP\Share::unshare('file', $fileInfo['fileid'], \OCP\Share::SHARE_TYPE_USER,
				\Test_Files_Sharing_Api::TEST_FILES_SHARING_API_USER2);

		\OCP\Share::unshare('file', $fileInfo['fileid'], \OCP\Share::SHARE_TYPE_LINK, null);

	}

	/**
	 * @medium
	 * @depends testCreateShare
	 */
	function testGetShareFromId() {

		$fileInfo = $this->view->getFileInfo($this->filename);

		$result = \OCP\Share::shareItem('file', $fileInfo['fileid'], \OCP\Share::SHARE_TYPE_USER,
			\Test_Files_Sharing_Api::TEST_FILES_SHARING_API_USER2, 31);

		// share was successful?
		$this->assertTrue($result);

		// get item to determine share ID
		$result = \OCP\Share::getItemShared('file', $fileInfo['fileid']);

		$this->assertEquals(1, count($result));

		// get first element
		$share = reset($result);

		// call getShare() with share ID
		$params = array('id' => $share['id']);
		$result = Share\Api::getShare($params);

		$this->assertTrue($result->succeeded());

		// test should return one share created from testCreateShare()
		$this->assertEquals(1, count($result->getData()));

		\OCP\Share::unshare('file', $fileInfo['fileid'], \OCP\Share::SHARE_TYPE_USER,
			\Test_Files_Sharing_Api::TEST_FILES_SHARING_API_USER2);

	}

	/**
	 * @medium
	 */
	function testGetShareFromFolder() {

		$fileInfo1 = $this->view->getFileInfo($this->filename);
		$fileInfo2 = $this->view->getFileInfo($this->folder.'/'.$this->filename);

		$result = \OCP\Share::shareItem('file', $fileInfo1['fileid'], \OCP\Share::SHARE_TYPE_USER,
				\Test_Files_Sharing_Api::TEST_FILES_SHARING_API_USER2, 31);

		// share was successful?
		$this->assertTrue($result);

		$result = \OCP\Share::shareItem('folder', $fileInfo2['fileid'], \OCP\Share::SHARE_TYPE_LINK,
				null, 1);

		// share was successful?
		$this->assertTrue(is_string($result));

		$_GET['path'] = $this->folder;
		$_GET['subfiles'] = 'true';

		$result = Share\Api::getAllShares(array());

		$this->assertTrue($result->succeeded());

        // test should return one share within $this->folder
		$this->assertTrue(count($result->getData()) === 1);

		\OCP\Share::unshare('file', $fileInfo1['fileid'], \OCP\Share::SHARE_TYPE_USER,
				\Test_Files_Sharing_Api::TEST_FILES_SHARING_API_USER2);

		\OCP\Share::unshare('folder', $fileInfo2['fileid'], \OCP\Share::SHARE_TYPE_LINK, null);

	}

	/**
	 * @medium
	 */
	function testGetShareFromUnknownId() {

		$params = array('id' => 0);

		$result = Share\Api::getShare($params);

		$this->assertEquals(404, $result->getStatusCode());
        $meta = $result->getMeta();
		$this->assertEquals('share doesn\'t exist', $meta['message']);

	}

	/**
	 * @medium
	 * @depends testCreateShare
	 */
	function testUpdateShare() {

		$fileInfo = $this->view->getFileInfo($this->filename);

		$result = \OCP\Share::shareItem('file', $fileInfo['fileid'], \OCP\Share::SHARE_TYPE_USER,
				\Test_Files_Sharing_Api::TEST_FILES_SHARING_API_USER2, 31);

		// share was successful?
		$this->assertTrue($result);

		$result = \OCP\Share::shareItem('file', $fileInfo['fileid'], \OCP\Share::SHARE_TYPE_LINK,
				null, 1);

		// share was successful?
		$this->assertTrue(is_string($result));

		$items = \OCP\Share::getItemShared('file', null);

		// make sure that we found a link share and a user share
		$this->assertEquals(count($items), 2);

		$linkShare = null;
		$userShare = null;

		foreach ($items as $item) {
			if ($item['share_type'] === \OCP\Share::SHARE_TYPE_LINK) {
				$linkShare = $item;
			}
			if ($item['share_type'] === \OCP\Share::SHARE_TYPE_USER) {
				$userShare = $item;
			}
		}

		// make sure that we found a link share and a user share
		$this->assertTrue(is_array($linkShare));
		$this->assertTrue(is_array($userShare));

		// update permissions

		$this->assertEquals('31', $userShare['permissions']);

		$params = array();
		$params['id'] = $userShare['id'];
		$params['_put'] = array();
		$params['_put']['permissions'] = 1;

		$result = Share\Api::updateShare($params);

        $meta = $result->getMeta();
		$this->assertTrue($result->succeeded(), $meta['message']);

		$items = \OCP\Share::getItemShared('file', $userShare['file_source']);

		$newUserShare = null;
		foreach ($items as $item) {
			if ($item['share_with'] === \Test_Files_Sharing_Api::TEST_FILES_SHARING_API_USER2) {
				$newUserShare = $item;
				break;
			}
		}

		$this->assertTrue(is_array($newUserShare));

		$this->assertEquals('1', $newUserShare['permissions']);

		// update password for link share

		$this->assertTrue(empty($linkShare['share_with']));

		$params = array();
		$params['id'] = $linkShare['id'];
		$params['_put'] = array();
		$params['_put']['password'] = 'foo';

		$result = Share\Api::updateShare($params);

		$this->assertTrue($result->succeeded());

		$items = \OCP\Share::getItemShared('file', $linkShare['file_source']);

		$newLinkShare = null;
		foreach ($items as $item) {
			if ($item['share_type'] === \OCP\Share::SHARE_TYPE_LINK) {
				$newLinkShare = $item;
				break;
			}
		}

		$this->assertTrue(is_array($newLinkShare));
		$this->assertTrue(!empty($newLinkShare['share_with']));

		\OCP\Share::unshare('file', $fileInfo['fileid'], \OCP\Share::SHARE_TYPE_USER,
				\Test_Files_Sharing_Api::TEST_FILES_SHARING_API_USER2);

		\OCP\Share::unshare('file', $fileInfo['fileid'], \OCP\Share::SHARE_TYPE_LINK, null);

	}

	/**
	 * @medium
	 */
	function testUpdateShareUpload() {

		$fileInfo = $this->view->getFileInfo($this->folder);

		$result = \OCP\Share::shareItem('folder', $fileInfo['fileid'], \OCP\Share::SHARE_TYPE_LINK,
				null, 1);

		// share was successful?
		$this->assertTrue(is_string($result));

		$items = \OCP\Share::getItemShared('file', null);

		// make sure that we found a link share and a user share
		$this->assertEquals(count($items), 1);

		$linkShare = null;

		foreach ($items as $item) {
			if ($item['share_type'] === \OCP\Share::SHARE_TYPE_LINK) {
				$linkShare = $item;
			}
		}

		// make sure that we found a link share
		$this->assertTrue(is_array($linkShare));

		// update public upload

		$params = array();
		$params['id'] = $linkShare['id'];
		$params['_put'] = array();
		$params['_put']['publicUpload'] = 'true';

		$result = Share\Api::updateShare($params);

		$this->assertTrue($result->succeeded());

		$items = \OCP\Share::getItemShared('file', $linkShare['file_source']);

		$updatedLinkShare = null;
		foreach ($items as $item) {
			if ($item['share_type'] === \OCP\Share::SHARE_TYPE_LINK) {
				$updatedLinkShare = $item;
				break;
			}
		}

		$this->assertTrue(is_array($updatedLinkShare));
		$this->assertEquals(7, $updatedLinkShare['permissions']);

		// cleanup

		\OCP\Share::unshare('file', $fileInfo['fileid'], \OCP\Share::SHARE_TYPE_LINK, null);

	}

	/**
	 * @medium
	 * @depends testCreateShare
	 */
	function testDeleteShare() {

		$fileInfo = $this->view->getFileInfo($this->filename);

		\OCP\Share::shareItem('file', $fileInfo['fileid'], \OCP\Share::SHARE_TYPE_USER,
				\Test_Files_Sharing_Api::TEST_FILES_SHARING_API_USER2, 31);

		\OCP\Share::shareItem('file', $fileInfo['fileid'], \OCP\Share::SHARE_TYPE_LINK,
				null, 1);

		$items = \OCP\Share::getItemShared('file', null);

		$this->assertEquals(2, count($items));

		foreach ($items as $item) {
			$result = Share\Api::deleteShare(array('id' => $item['id']));

			$this->assertTrue($result->succeeded());
		}

		$itemsAfterDelete = \OCP\Share::getItemShared('file', null);

		$this->assertTrue(empty($itemsAfterDelete));

	}

	/**
	 * @param $user
	 * @param bool $create
	 * @param bool $password
	 */
	private static function loginHelper($user, $create = false, $password = false) {
		if ($create) {
			\OC_User::createUser($user, $user);
		}

		if ($password === false) {
			$password = $user;
		}

		\OC_Util::tearDownFS();
		\OC_User::setUserId('');
		\OC\Files\Filesystem::tearDown();
		\OC_Util::setupFS($user);
		\OC_User::setUserId($user);

		$params['uid'] = $user;
		$params['password'] = $password;
	}

	/**
	 * @brief get some information from a given share
	 * @param int $shareID
	 * @return array with: item_source, share_type, share_with, item_type, permissions
	 */
	private function getShareFromId($shareID) {
		$sql = 'SELECT `item_source`, `share_type`, `share_with`, `item_type`, `permissions` FROM `*PREFIX*share` WHERE `id` = ?';
		$args = array($shareID);
		$query = \OCP\DB::prepare($sql);
		$result = $query->execute($args);

		$share = Null;

		if ($result && $result->numRows() > 0) {
				$share = $result->fetchRow();
		}

		return $share;

	}

}
