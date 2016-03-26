<?php
namespace Solarfield\Pager;

abstract class PagerHtmlViewPlugin extends \Solarfield\Lightship\HtmlViewPlugin implements PagerHtmlViewPluginInterface {
	public function resolveHints($aEv) {
		$hints = $this->getHints();
		$hints->set('doLoadPages', 1);
	}

	public function __construct(\Solarfield\Lightship\HtmlView $aView, $aComponentCode, $aInstallationCode) {
		parent::__construct($aView, $aComponentCode, $aInstallationCode);
		$aView->addEventListener('resolve-hints', [$this, 'resolveHints']);
	}
}