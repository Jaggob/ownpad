<?php
/** @var array $_ */
/** @var OCP\IURLGenerator $urlGenerator */
$urlGenerator = $_['urlGenerator'];
$version = $_['ownpad_version'];
$url = $_['url'];
$title = $_['title'];
$syncEnabled = isset($_['syncUrl']);
if ($syncEnabled) {
	$file = $_['file'];
	$syncUrl = $_['syncUrl'];
	$syncIntervalSeconds = $_['syncIntervalSeconds'];
	$token = \OC::$server->getCsrfTokenManager()->getToken()->getEncryptedValue();
	$nonce = \OC::$server->getContentSecurityPolicyNonceManager()->getNonce();
}
?>
<!DOCTYPE html>
<html style="height: 100%;"
<?php if ($syncEnabled) { ?>
  data-ownpad-file="<?php p($file); ?>"
  data-ownpad-sync-url="<?php p($syncUrl); ?>"
  data-ownpad-sync-interval="<?php p($syncIntervalSeconds); ?>"
<?php } ?>
>
  <head>
<?php if ($syncEnabled) { ?>
    <meta name="requesttoken" content="<?php p($token); ?>">
<?php } ?>
    <link rel="stylesheet" href="<?php p($urlGenerator->linkTo('ownpad', 'css/ownpad.css')) ?>?v=<?php p($version) ?>"/>
  </head>
  <body style="margin: 0px; padding: 0px; overflow: hidden; bottom: 37px; top: 0px; left: 0px; right: 0px; position: absolute;">
    <div id="ownpad_bar">
      <span>Title</span><strong><?php p($title); ?></strong><span><a target="_parent" href="<?php p($url); ?>"><?php p($url); ?></a></span>
    </div>
    <iframe frameborder="0" id="ownpad_frame" style="overflow:hidden;width:100%;height:100%;display:block;background-color:white;" height="100%" width="100%" src="<?php p($url); ?>"></iframe>
<?php if ($syncEnabled) { ?>
    <script nonce="<?php p($nonce); ?>" src="<?php p($urlGenerator->linkTo('ownpad', 'js/ownpad-sync.js')) ?>?v=<?php p($version) ?>"></script>
<?php } ?>
  </body>
</html>
