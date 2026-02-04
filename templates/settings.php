<?php
$l = \OC::$server->getL10N('ownpad');
?>
<div id="ownpad-settings"></div>
<div class="section" id="ownpad-sync-settings">
	<h2><?php p($l->t('Ownpad Pad Text to File')); ?></h2>
	<p><?php p($l->t('Copy Etherpad content as plain text into .pad files for Nextcloud search.')); ?></p>
	<div class="field">
		<input type="checkbox" id="ownpad-sync-enabled">
		<label for="ownpad-sync-enabled"><?php p($l->t('Sync while pad is open (and on close)')); ?></label>
	</div>
	<div class="field">
		<input type="checkbox" id="ownpad-sync-index-content">
		<label for="ownpad-sync-index-content"><?php p($l->t('Store pad content in file (indexable)')); ?></label>
	</div>
	<div class="field">
		<label for="ownpad-sync-interval"><?php p($l->t('Sync interval (seconds)')); ?></label>
		<input type="number" min="30" step="1" id="ownpad-sync-interval">
	</div>
	<button class="button" id="ownpad-sync-save"><?php p($l->t('Save')); ?></button>
	<span id="ownpad-sync-status" style="margin-left: 8px;"></span>
</div>
