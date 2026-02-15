<?php
/**
 * Nextcloud - Ownpad
 *
 * This file is licensed under the Affero General Public License
 * version 3 or later. See the COPYING file.
 *
 * @author Olivier Tétard <olivier.tetard@miskin.fr>
 * @copyright Olivier Tétard <olivier.tetard@miskin.fr>, 2017
 */

namespace OCA\Ownpad\Controller;

use \OCP\AppFramework\Controller;
use \OCP\AppFramework\Http;
use \OCP\AppFramework\Http\JSONResponse;
use \OCP\IRequest;

use OCA\Ownpad\Service\OwnpadException;
use OCA\Ownpad\Service\OwnpadService;

class AjaxController extends Controller {

	/** @var OwnpadService */
	private $service;

	public function __construct($appName, IRequest $request, OwnpadService $service) {
		parent::__construct($appName, $request);
		$this->service = $service;
	}

	/**
	 * @NoAdminRequired
	 */
	public function getconfig() {
		$config = [];

		$appConfig = \OC::$server->getConfig();
		$config['ownpad_etherpad_enable'] = $appConfig->getAppValue('ownpad', 'ownpad_etherpad_enable', 'no');
		$config['ownpad_etherpad_public_enable'] = $appConfig->getAppValue('ownpad', 'ownpad_etherpad_public_enable', 'no');
		$config['ownpad_etherpad_useapi'] = $appConfig->getAppValue('ownpad', 'ownpad_etherpad_useapi', 'no');
		$config['ownpad_ethercalc_enable'] = $appConfig->getAppValue('ownpad', 'ownpad_ethercalc_enable', 'no');

		return new JSONResponse(["data" => $config]);
	}

	/**
	 * @NoAdminRequired
	 */
	public function newpad($dir, $padname, $type, $protected) {
		\OC_Util::setupFS();

		$dir = isset($dir) ? '/'.trim($dir, '/\\') : '';
		$padname = isset($padname) ? trim($padname, '/\\') : '';
		$type = isset($type) ? trim($type, '/\\') : '';

		try {
			$data = $this->service->create($dir, $padname, $type, $protected);
			return new JSONResponse([
				'data' => $data,
				'status' => 'success',
			]);
		} catch(OwnpadException $e) {
			$message = [
				'data' => ['message' => $e->getMessage()],
				'status' => 'error',
			];
			return new JSONResponse($message, Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function testetherpadtoken() {
		try {
			$this->service->testEtherpadToken();
			return new JSONResponse([
				'data' => null,
				'status' => 'success',
			]);
		} catch(OwnpadException $e) {
			$message = [
				'data' => ['message' => $e->getMessage()],
				'status' => 'error',
			];
			return new JSONResponse($message, Http::STATUS_FORBIDDEN);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function syncpad($file) {
		\OC_Util::setupFS();

		$file = isset($file) ? '/' . trim($file, '/\\') : '';
		if ($file === '' || substr($file, -4) !== '.pad') {
			$message = [
				'data' => ['message' => 'Invalid file path'],
				'status' => 'error',
			];
			return new JSONResponse($message, Http::STATUS_BAD_REQUEST);
		}

		try {
			$changed = $this->service->syncPadFile($file);
			return new JSONResponse([
				'data' => ['changed' => $changed],
				'status' => 'success',
			]);
		} catch(OwnpadException $e) {
			$message = [
				'data' => ['message' => $e->getMessage()],
				'status' => 'error',
			];
			return new JSONResponse($message, Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * @AdminRequired
	 */
	public function getsyncsettings() {
		$appConfig = \OC::$server->getConfig();
		$interval = (int)$appConfig->getAppValue('ownpad', 'ownpad_pad_sync_interval_seconds', '120');
		$enabled = $appConfig->getAppValue('ownpad', 'ownpad_pad_sync_enabled', 'yes');
		$indexContent = $appConfig->getAppValue('ownpad', 'ownpad_pad_sync_index_content', 'yes');

		return new JSONResponse([
			'data' => [
				'intervalSeconds' => max(30, $interval),
				'enabled' => $enabled === 'yes',
				'indexContent' => $indexContent === 'yes',
			],
		]);
	}

	/**
	 * @AdminRequired
	 */
	public function setsyncsettings($intervalSeconds, $enabled, $indexContent) {
		$appConfig = \OC::$server->getConfig();
		$interval = max(30, (int)$intervalSeconds);
		$enabledValue = $enabled ? 'yes' : 'no';
		$indexContentValue = $indexContent ? 'yes' : 'no';
		$appConfig->setAppValue('ownpad', 'ownpad_pad_sync_interval_seconds', (string)$interval);
		$appConfig->setAppValue('ownpad', 'ownpad_pad_sync_enabled', $enabledValue);
		$appConfig->setAppValue('ownpad', 'ownpad_pad_sync_index_content', $indexContentValue);

		return new JSONResponse([
			'data' => ['status' => 'ok'],
		]);
	}
}
