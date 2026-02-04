<div id="ownpad-settings"></div>
<div class="section" id="ownpad-sync-settings">
	<h2>Ownpad Pad Sync</h2>
	<p>Synchronize Etherpad content into .pad files for Nextcloud search.</p>
	<div class="field">
		<input type="checkbox" id="ownpad-sync-enabled">
		<label for="ownpad-sync-enabled">Enable sync for opened pads</label>
	</div>
	<div class="field">
		<input type="checkbox" id="ownpad-sync-index-content">
		<label for="ownpad-sync-index-content">Write pad content into file (indexable)</label>
	</div>
	<div class="field">
		<label for="ownpad-sync-interval">Sync interval (seconds)</label>
		<input type="number" min="30" step="1" id="ownpad-sync-interval">
	</div>
	<button class="button" id="ownpad-sync-save">Save</button>
	<span id="ownpad-sync-status" style="margin-left: 8px;"></span>
</div>
