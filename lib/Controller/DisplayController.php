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

use OCA\Ownpad\Service\OwnpadException;
use OCA\Ownpad\Service\OwnpadService;

use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

class DisplayController extends Controller {

	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var IAppManager */
	private $appManager;

	/** @var OwnpadService */
	private $ownpadService;

	/**
	 * @param string $AppName
	 * @param IRequest $request
	 * @param IURLGenerator $urlGenerator
	 */
	public function __construct(
		$AppName,
		IRequest $request,
		IURLGenerator $urlGenerator,
		IAppManager $appManager,
		OwnpadService $ownpadService
	) {
		parent::__construct($AppName, $request);
		$this->urlGenerator = $urlGenerator;
		$this->appManager = $appManager;
		$this->ownpadService = $ownpadService;
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @return TemplateResponse
	 */
	public function showPad($file): TemplateResponse {
		\OC_Util::setupFS();

		/* Retrieve file content to find pad’s URL */
		$content = \OC\Files\Filesystem::file_get_contents($file);

		$params = [
			'urlGenerator' => $this->urlGenerator,
			'ownpad_version' => $this->appManager->getAppVersion('ownpad'),
			'title' => $file,
		];
		$config = \OC::$server->getConfig();
		$syncEnabled = $this->isPadSyncActiveForFile($file, $config);
		if ($syncEnabled) {
			$params['file'] = $file;
			$params['syncUrl'] = $this->urlGenerator->linkToRoute('ownpad.ajax.syncpad');
			$params['syncIntervalSeconds'] = max(30, (int)$config->getAppValue('ownpad', 'ownpad_pad_sync_interval_seconds', '120'));
		}

		try {
			$params['url'] = $this->ownpadService->parseOwnpadContent($file, $content);
			return new TemplateResponse($this->appName, 'viewer', $params, 'blank');
		} catch(OwnpadException $e) {
			$params["error"] = $e->getMessage();
			return new TemplateResponse($this->appName, 'noviewer', $params, 'blank');
		}
	}

	private function isPadSyncActiveForFile(string $file, \OCP\IConfig $config): bool {
		if (substr($file, -4) !== '.pad') {
			return false;
		}

		if ($config->getAppValue('ownpad', 'ownpad_pad_sync_enabled', 'yes') !== 'yes') {
			return false;
		}

		if ($config->getAppValue('ownpad', 'ownpad_pad_sync_index_content', 'yes') !== 'yes') {
			return false;
		}

		return $config->getAppValue('ownpad', 'ownpad_etherpad_enable', 'no') !== 'no'
			&& $config->getAppValue('ownpad', 'ownpad_etherpad_useapi', 'no') !== 'no';
	}
}
