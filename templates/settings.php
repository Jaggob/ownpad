<?php
$l = \OC::$server->getL10N('ownpad');
?>
<div id="ownpad-settings"></div>
<div class="section" id="ownpad-sync-settings">
	<h2><?php p($l->t('Ownpad Pad Text to File')); ?></h2>
	<p><?php p($l->t('Synchronizes pad content when you open the pad, while it stays open, and once more when you close it. This enables Nextcloud search.')); ?></p>
	<div class="field">
		<input type="checkbox" id="ownpad-sync-enabled">
		<label for="ownpad-sync-enabled"><?php p($l->t('Sync pad content into file')); ?></label>
	</div>
	<div class="field">
		<label for="ownpad-sync-interval"><?php p($l->t('Sync interval (when pad is open)')); ?></label>
		<input type="number" min="30" step="1" id="ownpad-sync-interval" value="120">
	</div>
	<button class="button" id="ownpad-sync-save"><?php p($l->t('Save')); ?></button>
	<span id="ownpad-sync-status" style="margin-left: 8px;"></span>
</div>
