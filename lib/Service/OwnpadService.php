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

namespace OCA\Ownpad\Service;

use Exception;

use OCP\Files\File;
use OCP\IConfig;
use OCP\IUserSession;

class OwnpadService {
	private $eplHost;
	private $eplHostApi;

	private $eplApiKey = "";
	private $eplEnableOIDC = false;
	private $eplClientId = "";
	private $eplClientSecret = "";

	public const EPL_API_VERSION = '1.2.11';
	public const EPL_CODE_OK = 0;
	public const EPL_CODE_INVALID_PARAMETERS = 1;
	public const EPL_CODE_INTERNAL_ERROR = 2;
	public const EPL_CODE_INVALID_FUNCTION = 3;
	public const EPL_CODE_INVALID_API_KEY = 4;
	private const RESTORE_HTML_BEGIN_MARKER = '; ownpad_restore_html_begin';
	private const RESTORE_HTML_END_MARKER = '; ownpad_restore_html_end';

	public function __construct(
		private IConfig $config,
		private IUserSession $userSession
	) {
		$this->config = $config;
		$this->userSession = $userSession;

		if($this->config->getAppValue('ownpad', 'ownpad_etherpad_enable', 'no') !== 'no' and
		   $this->config->getAppValue('ownpad', 'ownpad_etherpad_useapi', 'no') !== 'no') {
			$this->eplHost = $this->config->getAppValue('ownpad', 'ownpad_etherpad_host', '');
			$this->eplHostApi = $this->eplHost . "/api";

			if ($this->config->getAppValue('ownpad', 'ownpad_etherpad_enable_oauth', 'no') === 'no') {
				$this->eplApiKey = $this->config->getAppValue('ownpad', 'ownpad_etherpad_apikey', '');
			} else {
				$this->eplEnableOIDC = true;
				$this->eplClientId = $this->config->getAppValue('ownpad', 'ownpad_etherpad_client_id', '');
				$this->eplClientSecret = $this->config->getAppValue('ownpad', 'ownpad_etherpad_client_secret', '');
			}
		}
	}

	public function create($dir, $padname, $type, $protected) {
		// Generate a random pad name
		$token = \OC::$server->getSecureRandom()->generate(rand(32, 50), \OCP\Security\ISecureRandom::CHAR_LOWER.\OCP\Security\ISecureRandom::CHAR_DIGITS);

		$l10n = \OC::$server->getL10N('ownpad');
		$l10n_files = \OC::$server->getL10N('files');

		if($type === "ethercalc") {
			$ext = "calc";
			$host = \OC::$server->getConfig()->getAppValue('ownpad', 'ownpad_ethercalc_host', false);

			/*
			 * Prepend the calc’s name with a `=` to enable multisheet
			 * support.
			 *
			 * More info:
			 *   – https://github.com/audreyt/ethercalc/issues/138
			 *   – https://github.com/otetard/ownpad/issues/26
			 */
			$url = sprintf("%s/=%s", rtrim($host, "/"), $token);
		} elseif($type === "etherpad") {
			$padID = $token;

			$config = \OC::$server->getConfig();
			if($config->getAppValue('ownpad', 'ownpad_etherpad_enable', 'no') !== 'no' and $config->getAppValue('ownpad', 'ownpad_etherpad_useapi', 'no') !== 'no') {
				try {
					if($protected === true) {
						// Create a protected (group) pad via API
						$group = $this->etherpadCallApi('createGroup');
						$groupPad = $this->etherpadCallApi('createGroupPad', ["groupID" => $group->groupID, "padName" => $token]);
						$padID = $groupPad->padID;
					} else {
						// Create a public pad via API
						$this->etherpadCallApi("createPad", ["padID" => $token]);
					}
				} catch(Exception $e) {
					throw new OwnpadException($l10n->t('Unable to communicate with Etherpad API due to the following error: “%s”.', [$e->getMessage()]));
				}
			}

			$ext = "pad";
			$host = \OC::$server->getConfig()->getAppValue('ownpad', 'ownpad_etherpad_host', false);
			$url = sprintf("%s/p/%s", rtrim($host, "/"), $padID);
		}

		if($padname === '' || $padname === '.' || $padname === '..') {
			throw new OwnpadException($l10n->t('Incorrect padname.'));
		}

		try {
			$view = new \OC\Files\View();
			$view->verifyPath($dir, $padname);
		} catch(\OCP\Files\InvalidPathException $ex) {
			throw new OwnpadException($l10n_files->t("Invalid name, '\\', '/', '<', '>', ':', '\"', '|', '?' and '*' are not allowed."));
		}

		if(!\OC\Files\Filesystem::file_exists($dir . '/')) {
			throw new OwnpadException($l10n_files->t('The target folder has been moved or deleted.'));
		}

		// Add the extension only if padname doesn’t contain it
		if(substr($padname, -strlen(".$ext")) !== ".$ext") {
			$filename = "$padname.$ext";
		} else {
			$filename = $padname;
		}

		$target = $dir . "/" . $filename;

		if(\OC\Files\Filesystem::file_exists($target)) {
			throw new OwnpadException($l10n_files->t('The name %s is already used in the folder %s. Please choose a different name.', [$filename, $dir]));
		}

		$content = sprintf("[InternetShortcut]\nURL=%s", $url);

		if(\OC\Files\Filesystem::file_put_contents($target, $content)) {
			$meta = \OC\Files\Filesystem::getFileInfo($target);
			if ($meta) {
				$this->storePadUrlForFileId((int)$meta->getId(), $url);
			}
			return \OCA\Files\Helper::formatFileInfo($meta);
		}

		throw new OwnpadException($l10n_files->t('Error when creating the file'));
	}

	public function parseOwnpadContent($file, $content, bool $publicMode = false, string $publicShareToken = '', bool $readOnly = false) {
		$l10n = \OC::$server->getL10N('ownpad');

		$url = $this->extractUrlFromContent($content);
		$decodedUrl = urldecode($url);

		$eplHostApi = $this->config->getAppValue('ownpad', 'ownpad_etherpad_host', '');
		$eplHostApi = rtrim($eplHostApi, '/');
		$protectedPadRegex = sprintf('/%s\/p\/(g\.\w{16})\\$([^\/]+)$/', preg_quote($eplHostApi, '/'));
		$match = preg_match($protectedPadRegex, $decodedUrl, $matches);

		/*
		 * We are facing a “protected” pad. Call for Etherpad API to
		 * create the session and then properly configure the cookie.
		 */
		if($match) {
			$groupID = $matches[1];
			$padID = $matches[1] . '$' . $matches[2];

			if($publicMode === true) {
				if ($this->config->getAppValue('ownpad', 'ownpad_etherpad_public_enable', 'no') === 'no') {
					throw new OwnpadException($l10n->t('You are not allowed to open this pad.'));
				}

				if ($publicShareToken === '') {
					throw new OwnpadException($l10n->t('You are not allowed to open this pad.'));
				}

				$authorMapper = 'public:' . hash('sha256', $publicShareToken . '|' . $padID);
				$authorName = $l10n->t('Public share guest');
				$sessionValidUntil = time() + 900;
			} else {
				$username = $this->userSession->getUser()->getUID();
				$displayName = $this->userSession->getUser()->getDisplayName();
				$authorMapper = $username;
				$authorName = $displayName;
				$sessionValidUntil = time() + 3600;
			}

			$author = $this->etherpadCallApi("createAuthorIfNotExistsFor", ["authorMapper" => $authorMapper, "name" => $authorName]);
			$session = $this->etherpadCallApi('createSession', ["groupID" => $groupID, "authorID" => $author->authorID, "validUntil" => $sessionValidUntil]);

			$cookieDomain = $this->config->getAppValue('ownpad', 'ownpad_etherpad_cookie_domain', '');
			setcookie('sessionID', $session->sessionID, 0, '/', $cookieDomain, true, false);

			if ($readOnly) {
				$url = $this->getReadOnlyPadUrl($padID);
			}
		} elseif ($readOnly) {
			$padRegex = sprintf('/^%s\/p\/([^\/]+)$/', preg_quote($eplHostApi, '/'));
			if (preg_match($padRegex, $decodedUrl, $matches) === 1 && isset($matches[1])) {
				$url = $this->getReadOnlyPadUrl($matches[1]);
			}
		}

		$url = $this->normalizeUrl($url);

		// Check for valid URL
		// Get File-Ending
		$split = explode(".", $file);
		$fileending = $split[count($split) - 1];

		// Get Host-URL
		if($fileending === "calc") {
			$host = \OC::$server->getConfig()->getAppValue('ownpad', 'ownpad_ethercalc_host', false);
		} elseif($fileending === "pad") {
			$host = \OC::$server->getConfig()->getAppValue('ownpad', 'ownpad_etherpad_host', false);
		}

		if(substr($host, -1, 1) !== '/') {
			$host .= '/';
		}

		// Escape all RegEx-Characters
		$hostreg = preg_quote($host);
		// Escape all Slashes in URL to use in RegEx
		$hostreg = preg_replace("/\//", "\/", $host);

		// Final Regex-String
		if($fileending === "calc") {
			/*
			 * Ethercalc documents with “multisheet” support starts
			 * with a `=`.
			 */
			$regex = "/^".$hostreg."=?[^\/]+$/";
		} elseif($fileending === "pad") {
			/*
			 * Etherpad documents can contain special characters, for
			 * “protected pads” for example.
			 */
			$regex = "/^".$hostreg."p\/[^\/]+$/";
		}

		if (preg_match($regex, $url) !== 1) {
			throw new OwnpadException($l10n->t('URL in your Etherpad/Ethercalc document does not match the allowed server'));
		}

		return $url;
	}

	public function extractUrlFromContent(string $content): string {
		$l10n = \OC::$server->getL10N('ownpad');
		$normalizedContent = str_replace("\r\n", "\n", $content);
		$lines = explode("\n", $normalizedContent);
		$maxHeaderLines = 25;

		/*
		 * Parse URL only from the file header at the top to avoid matching
		 * `URL=` that could appear inside synced pad content.
		 */
		foreach ($lines as $index => $line) {
			if ($index >= $maxHeaderLines) {
				break;
			}

			if ($index > 0 && trim($line) === '') {
				break;
			}

			if (preg_match('/^URL=(.*)$/', $line, $matches)) {
				return urldecode($matches[1]);
			}
		}

		throw new OwnpadException($l10n->t('URL in your Etherpad/Ethercalc document does not match the allowed server'));
	}

	public function getPadIdFromUrl(string $url): string {
		$host = $this->config->getAppValue('ownpad', 'ownpad_etherpad_host', false);
		$host = rtrim($host, '/');
		$url = $this->normalizeUrl($url);

		$regex = sprintf('#^%s/p/([^/]+)$#', preg_quote($host, '#'));
		if (!preg_match($regex, $url, $matches)) {
			$l10n = \OC::$server->getL10N('ownpad');
			throw new OwnpadException($l10n->t('URL in your Etherpad/Ethercalc document does not match the allowed server'));
		}

		return $matches[1];
	}

	public function getPadText(string $padId): string {
		$padId = urldecode($padId);
		$data = $this->etherpadCallApi('getText', ['padID' => $padId]);
		return $data->text ?? '';
	}

	public function getPadHtml(string $padId): string {
		$padId = urldecode($padId);
		$data = $this->etherpadCallApi('getHTML', ['padID' => $padId]);
		return $data->html ?? '';
	}

	public function getPadRevisionsCount(string $padId): int {
		$padId = urldecode($padId);
		$data = $this->etherpadCallApi('getRevisionsCount', ['padID' => $padId]);
		return (int)($data->revisions ?? 0);
	}

	public function syncPadFile(string $file): bool {
		$l10n = \OC::$server->getL10N('ownpad');

		if ($this->config->getAppValue('ownpad', 'ownpad_etherpad_enable', 'no') === 'no' ||
			$this->config->getAppValue('ownpad', 'ownpad_etherpad_useapi', 'no') === 'no') {
			throw new OwnpadException($l10n->t('Etherpad API is disabled.'));
		}
		if ($this->config->getAppValue('ownpad', 'ownpad_pad_sync_enabled', 'yes') === 'no') {
			return false;
		}

		if (!\OC\Files\Filesystem::file_exists($file)) {
			throw new OwnpadException($l10n->t('File not found.'));
		}

		$content = \OC\Files\Filesystem::file_get_contents($file);
		$url = $this->extractUrlFromContent($content);
		$padId = $this->getPadIdFromUrl($url);

		$indexContent = $this->config->getAppValue('ownpad', 'ownpad_pad_sync_index_content', 'yes') === 'yes';
		if (!$indexContent) {
			return false;
		}

		$lastRevision = $this->extractLastRevisionFromContent($content);
		$currentRevision = $this->getPadRevisionsCount($padId);

		if ($lastRevision !== null && $currentRevision === $lastRevision) {
			return false;
		}

		$format = $this->getPadSyncFormat();
		$syncContent = $this->getPadSyncContent($padId, $format);
		$newContent = $this->buildSyncedContent($url, $syncContent, $currentRevision, $format);

		if ($newContent === $content) {
			return false;
		}

		try {
			return \OC\Files\Filesystem::file_put_contents($file, $newContent) !== false;
		} catch (\OCP\Lock\LockedException $e) {
			return false;
		}
	}

	private function normalizeUrl(string $url): string {
		/*
		 * Not totally sure that this is the right way to proceed…
		 *
		 * First we decode the URL (to avoid double encode), then we
		 * replace spaces with underscore (as they are converted as
		 * such by Etherpad), then we encode the URL properly (and we
		 * avoid to urlencode() the protocol scheme).
		 *
		 * Magic urlencode() function was stolen from this answer on
		 * StackOverflow: <http://stackoverflow.com/a/7974253>.
		 */
		$url = urldecode($url);
		$url = str_replace(' ', '_', $url);
		$url = preg_replace_callback('#://([^/]+)/(=)?([^?]+)#', function ($match) {
			return '://' . $match[1] . '/' . $match[2] . join('/', array_map('rawurlencode', explode('/', $match[3])));
		}, $url);

		return $url;
	}

	private function buildSyncedContent(string $url, string $syncContent, int $revision, string $format): string {
		$normalizedContent = str_replace("\r\n", "\n", $syncContent);
		return "[InternetShortcut]\nURL={$url}\n; ownpad_last_rev={$revision}\n; ownpad_sync_format={$format}\n\n; Ownpad full-text index (auto-generated). Do not edit.\n" . $normalizedContent;
	}

	private function extractLastRevisionFromContent(string $content): ?int {
		if (preg_match('/^; ownpad_last_rev=(\\d+)$/m', $content, $matches)) {
			return (int)$matches[1];
		}
		return null;
	}

	private function getPadSyncFormat(): string {
		$format = strtolower(trim($this->config->getAppValue('ownpad', 'ownpad_pad_sync_format', 'plain')));
		$allowedFormats = ['plain', 'html', 'markdown'];
		if (!in_array($format, $allowedFormats, true)) {
			return 'plain';
		}
		return $format;
	}

	private function getPadSyncContent(string $padId, string $format): string {
		if ($format === 'html') {
			try {
				return $this->getPadHtml($padId);
			} catch (Exception) {
				return $this->getPadText($padId);
			}
		}

		if ($format === 'markdown') {
			try {
				$html = $this->getPadHtml($padId);
				if ($html !== '') {
					$markdown = $this->convertHtmlToMarkdown($html);
					if (trim($markdown) !== '') {
						return $markdown;
					}
				}
			} catch (Exception) {
				// Fallback to plain text below.
			}
			return $this->getPadText($padId);
		}

		return $this->getPadText($padId);
	}

	/**
	 * Convert Etherpad HTML to markdown using a simple best-effort mapping.
	 */
	private function convertHtmlToMarkdown(string $html): string {
		$markdown = preg_replace('/<\\/?(?:html|head|body|div)[^>]*>/i', '', $html);
		$markdown = preg_replace('/<br\\s*\\/?>/i', "\n", $markdown);
		$markdown = preg_replace('/<\\/(?:p|h[1-6]|li|ul|ol|blockquote)>/i', "\n", $markdown);

		$markdown = preg_replace_callback('/<h([1-6])[^>]*>(.*?)<\\/h\\1>/is', function ($matches) {
			$level = max(1, min(6, (int)$matches[1]));
			$text = trim(strip_tags($matches[2]));
			return str_repeat('#', $level) . ' ' . $text . "\n";
		}, $markdown);

		$markdown = preg_replace('/<(?:strong|b)>(.*?)<\\/(?:strong|b)>/is', '**$1**', $markdown);
		$markdown = preg_replace('/<(?:em|i)>(.*?)<\\/(?:em|i)>/is', '*$1*', $markdown);
		$markdown = preg_replace('/<li[^>]*>(.*?)$/im', '- $1', $markdown);

		$markdown = preg_replace_callback('/<a[^>]+href=(["\\\'])(.*?)\\1[^>]*>(.*?)<\\/a>/is', function ($matches) {
			$href = trim($matches[2]);
			$text = trim(strip_tags($matches[3]));
			if ($text === '') {
				$text = $href;
			}
			return '[' . $text . '](' . $href . ')';
		}, $markdown);

		$markdown = html_entity_decode(strip_tags($markdown), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$markdown = preg_replace("/\n{3,}/", "\n\n", $markdown);
		return trim($markdown) . "\n";
	}

	private function getReadOnlyPadUrl(string $padID): string {
		$l10n = \OC::$server->getL10N('ownpad');
		$host = rtrim($this->config->getAppValue('ownpad', 'ownpad_etherpad_host', ''), '/');

		// Already a read-only pad ID: no API conversion needed.
		if (strpos($padID, 'r.') === 0) {
			return sprintf('%s/p/%s', $host, $padID);
		}

		if ($this->config->getAppValue('ownpad', 'ownpad_etherpad_useapi', 'no') === 'no') {
			throw new OwnpadException($l10n->t('Read-only share mode requires Etherpad API support.'));
		}

		try {
			$readOnly = $this->etherpadCallApi('getReadOnlyID', ['padID' => $padID]);
		} catch (Exception $e) {
			throw new OwnpadException($l10n->t('Unable to switch to read-only mode due to the following error: “%s”.', [$e->getMessage()]));
		}

		if (!isset($readOnly->readOnlyID) || !is_string($readOnly->readOnlyID) || $readOnly->readOnlyID === '') {
			throw new OwnpadException($l10n->t('Unable to switch to read-only mode because Etherpad did not return a read-only ID.'));
		}

		return sprintf('%s/p/%s', $host, $readOnly->readOnlyID);
	}

	public function testEtherpadToken() {
		try {
			return $this->etherpadCallApi('checkToken');
		} catch(Exception) {
			$l10n = \OC::$server->getL10N('ownpad');
			throw new OwnpadException($l10n->t('Invalid authentication credentials'));
		}
	}

	public function deletePadFromUrl(string $url): void {
		if (!$this->isDeleteOnTrashEnabled()) {
			return;
		}
		if ($this->config->getAppValue('ownpad', 'ownpad_etherpad_enable', 'no') === 'no') {
			return;
		}
		if ($this->config->getAppValue('ownpad', 'ownpad_etherpad_useapi', 'no') === 'no') {
			return;
		}

		$host = rtrim($this->config->getAppValue('ownpad', 'ownpad_etherpad_host', ''), '/');
		if ($host === '') {
			return;
		}

		$url = trim($url);
		$pattern = '#^' . preg_quote($host, '#') . '/p/([^/]+)$#';
		if (!preg_match($pattern, $url, $matches)) {
			return;
		}

		$padId = $matches[1];
		try {
			$this->etherpadCallApi('deletePad', ['padID' => $padId]);
		} catch (Exception) {
			// Best-effort cleanup. If it fails, do not block trash deletion.
		}
	}

	public function snapshotPadHtmlInFileForRestore(File $file, string $url): void {
		if ($this->config->getAppValue('ownpad', 'ownpad_etherpad_enable', 'no') === 'no') {
			return;
		}
		if ($this->config->getAppValue('ownpad', 'ownpad_etherpad_useapi', 'no') === 'no') {
			return;
		}

		$padId = $this->extractPadIdFromUrl($url);
		if ($padId === null) {
			return;
		}

		try {
			$html = $this->getPadHtml($padId);
		} catch (Exception) {
			return;
		}

		$currentContent = '';
		try {
			$currentContent = (string)$file->getContent();
		} catch (\Throwable) {
			$currentContent = '';
		}
		if ($currentContent === '') {
			$currentContent = sprintf("[InternetShortcut]\nURL=%s\n", $url);
		}

		$newContent = $this->injectRestoreHtmlPayload($currentContent, $html);
		if ($newContent === $currentContent) {
			return;
		}

		try {
			$file->putContent($newContent);
		} catch (\Throwable) {
			// Best-effort snapshot. Do not block trash action.
		}
	}

	public function restoreDeletedPadFromFile(File $file): void {
		if ($this->config->getAppValue('ownpad', 'ownpad_etherpad_enable', 'no') === 'no') {
			return;
		}
		if ($this->config->getAppValue('ownpad', 'ownpad_etherpad_useapi', 'no') === 'no') {
			return;
		}

		try {
			$content = (string)$file->getContent();
		} catch (\Throwable) {
			return;
		}

		$restoreHtml = $this->extractRestoreHtmlPayload($content);
		if ($restoreHtml === null) {
			return;
		}

		try {
			$url = $this->extractUrlFromContent($content);
		} catch (\Throwable) {
			return;
		}

		$padId = $this->extractPadIdFromUrl($url);
		if ($padId === null) {
			return;
		}

		$padState = $this->detectPadState($padId);
		if ($padState === null) {
			return;
		}

		if ($padState === false) {
			try {
				$this->etherpadCallApi('createPad', ['padID' => $padId]);
			} catch (\InvalidArgumentException $e) {
				if (stripos($e->getMessage(), 'already') === false) {
					return;
				}
			} catch (Exception) {
				return;
			}

			try {
				$this->etherpadCallApi('setHTML', ['padID' => $padId, 'html' => $restoreHtml], 'POST');
			} catch (Exception) {
				return;
			}
		}

		$cleanContent = $this->removeRestoreHtmlPayload($content);
		if ($cleanContent !== $content) {
			try {
				$file->putContent($cleanContent);
			} catch (\Throwable) {
				// Non-fatal; restored pad is already usable.
			}
		}

		$this->storePadUrlForFileId((int)$file->getId(), $url);
	}

	public function storePadUrlForFileId(int $fileId, string $url): void {
		if ($fileId <= 0 || $url === '') {
			return;
		}
		$this->config->setAppValue('ownpad', 'pad_url_' . $fileId, $url);
	}

	public function getPadUrlForFileId(int $fileId): ?string {
		if ($fileId <= 0) {
			return null;
		}
		$value = $this->config->getAppValue('ownpad', 'pad_url_' . $fileId, '');
		return $value === '' ? null : $value;
	}

	public function deletePadUrlForFileId(int $fileId): void {
		if ($fileId <= 0) {
			return;
		}
		$this->config->deleteAppValue('ownpad', 'pad_url_' . $fileId);
	}

	public function isDeleteOnTrashEnabled(): bool {
		return $this->config->getAppValue('ownpad', 'ownpad_delete_on_trash', 'no') === 'yes';
	}

	private function extractPadIdFromUrl(string $url): ?string {
		$host = rtrim($this->config->getAppValue('ownpad', 'ownpad_etherpad_host', ''), '/');
		if ($host === '') {
			return null;
		}

		$pattern = '#^' . preg_quote($host, '#') . '/p/([^/]+)$#';
		if (!preg_match($pattern, trim($url), $matches)) {
			return null;
		}

		return $matches[1] ?? null;
	}

	private function injectRestoreHtmlPayload(string $content, string $html): string {
		$normalized = str_replace("\r\n", "\n", $this->removeRestoreHtmlPayload($content));
		$payload = base64_encode($html);
		$wrappedPayload = rtrim(chunk_split($payload, 120, "\n"), "\n");
		$block = "\n\n" . self::RESTORE_HTML_BEGIN_MARKER . "\n" . $wrappedPayload . "\n" . self::RESTORE_HTML_END_MARKER . "\n";
		return rtrim($normalized, "\n") . $block;
	}

	private function extractRestoreHtmlPayload(string $content): ?string {
		$normalized = str_replace("\r\n", "\n", $content);
		$pattern = '/' . preg_quote(self::RESTORE_HTML_BEGIN_MARKER, '/') . "\n(.*?)\n" . preg_quote(self::RESTORE_HTML_END_MARKER, '/') . '/s';
		if (!preg_match($pattern, $normalized, $matches)) {
			return null;
		}

		$encoded = preg_replace('/\s+/', '', $matches[1]);
		if (!is_string($encoded) || $encoded === '') {
			return '';
		}

		$decoded = base64_decode($encoded, true);
		if ($decoded === false) {
			return null;
		}

		return $decoded;
	}

	private function removeRestoreHtmlPayload(string $content): string {
		$normalized = str_replace("\r\n", "\n", $content);
		$pattern = '/\n*' . preg_quote(self::RESTORE_HTML_BEGIN_MARKER, '/') . "\n.*?\n" . preg_quote(self::RESTORE_HTML_END_MARKER, '/') . '\n*/s';
		$cleaned = preg_replace($pattern, "\n", $normalized);
		$cleaned = is_string($cleaned) ? $cleaned : $normalized;
		return rtrim($cleaned) . "\n";
	}

	private function detectPadState(string $padId): ?bool {
		try {
			$this->getPadRevisionsCount($padId);
			return true;
		} catch (\InvalidArgumentException $e) {
			if (stripos($e->getMessage(), 'does not exist') !== false) {
				return false;
			}
			return null;
		} catch (Exception) {
			return null;
		}
	}

	/**
	 * Main entrypoint to call Etherpad API.
	 *
	 * This code is heavily inspired from tomnomnom’s PHP Etherpad
	 * client. Original source code is available here:
	 * https://github.com/tomnomnom/etherpad-lite-client
	 */
	private function etherpadCallApi($function, array $arguments = array(), $method = 'GET') {
		$params = array("http" => array("method" => $method, "ignore_errors" => true, "header" => "Content-Type:application/x-www-form-urlencoded"));

		if ($this->eplEnableOIDC) {
			$token = $this->getBearerToken();
			$params["http"]["header"] .= "\r\nAuthorization: Bearer {$token}";
		} else {
			$arguments["apikey"] = $this->eplApiKey;
		}

		$arguments = array_map(array($this, "etherpadConvertBools"), $arguments);
		$arguments = http_build_query($arguments, "", "&");
		$url = $this->eplHostApi."/".self::EPL_API_VERSION."/".$function;

		if ($method !== "POST") {
			$url .= "?".$arguments;
		} elseif ($method === "POST") {
			$params["http"]["content"] = $arguments;
		}

		$context = stream_context_create($params);
		$fp = fopen($url, "rb", false, $context);
		$result = $fp ? stream_get_contents($fp) : null;

		if(!$result) {
			throw new \UnexpectedValueException("Empty or No Response from the server");
		}

		$result = json_decode($result);

		if ($result === null) {
			throw new \UnexpectedValueException("JSON response could not be decoded");
		}

		if (!isset($result->code)) {
			throw new \RuntimeException("API response has no code");
		}
		if (!isset($result->message)) {
			throw new \RuntimeException("API response has no message");
		}
		if (!isset($result->data)) {
			$result->data = null;
		}

		switch ($result->code) {
			case self::EPL_CODE_OK:
				return $result->data;
			case self::EPL_CODE_INVALID_PARAMETERS:
			case self::EPL_CODE_INVALID_API_KEY:
				throw new \InvalidArgumentException($result->message);
			case self::EPL_CODE_INTERNAL_ERROR:
				throw new \RuntimeException($result->message);
			case self::EPL_CODE_INVALID_FUNCTION:
				throw new \BadFunctionCallException($result->message);
			default:
				throw new \RuntimeException("An unexpected error occurred whilst handling the response");
		}
	}

	protected function etherpadConvertBools($candidate) {
		if (is_bool($candidate)) {
			return $candidate? "true" : "false";
		}
		return $candidate;
	}


	private function getBearerToken() {
		$oidcUrl = $this->eplHost . "/oidc/token";
		$data = [
			"resource" => $this->eplHost . "/oidc/resource",
			"grant_type" => "client_credentials",
			"client_id" => $this->eplClientId,
			"client_secret" => $this->eplClientSecret,
		];
		$options = ["http" => ["method" => "POST",
			"ignore_errors" => true,
			"header" => "Content-Type:application/x-www-form-urlencoded",
			"content" => http_build_query($data)
		]];
		$context = stream_context_create($options);
		$result = file_get_contents($oidcUrl, false, $context);

		if ($result === false) {
			$l10n = \OC::$server->getL10N('ownpad');
			throw new OwnpadException($l10n->t('Unable to authenticate to Etherpad API'));
		}

		$result = json_decode($result);

		return $result->access_token;
	}
}
