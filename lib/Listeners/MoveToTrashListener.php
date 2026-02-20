<?php
/**
 * Nextcloud - Ownpad
 *
 * This file is licensed under the Affero General Public License
 * version 3 or later. See the COPYING file.
 *
 * @copyright
 */

namespace OCA\Ownpad\Listeners;

use OCA\Ownpad\Service\OwnpadService;
use OCA\Ownpad\Service\PadBindingService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;

class MoveToTrashListener implements IEventListener {
	public function __construct(
		private OwnpadService $ownpadService,
		private PadBindingService $padBindingService,
	) {
	}

	public function handle(Event $event): void {
		if (!method_exists($event, 'getNode')) {
			return;
		}

		$node = $event->getNode();
		if (!$node instanceof File) {
			return;
		}

		$name = $node->getName();
		if (!str_ends_with(strtolower($name), '.pad')) {
			return;
		}

		$fileId = (int)$node->getId();
		$binding = $this->padBindingService->findActiveByFileId($fileId);
		if ($binding === null) {
			return;
		}
		$baseUrl = rtrim((string)($binding['base_url'] ?? ''), '/');
		$padId = (string)($binding['pad_id'] ?? '');
		if ($baseUrl === '' || $padId === '') {
			return;
		}
		$url = $baseUrl . '/p/' . rawurlencode($padId);

		if ($this->ownpadService->isDeleteOnTrashEnabled()) {
			$this->ownpadService->deletePadFromUrl($url);
		}
	}
}
