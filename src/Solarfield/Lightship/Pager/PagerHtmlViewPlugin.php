<?php
namespace Solarfield\Lightship\Pager;

abstract class PagerHtmlViewPlugin extends \Lightship\HtmlViewPlugin implements PagerHtmlViewPluginInterface {
	public function resolveHints($aEv) {
		$hints = $this->getHints();
		$hints->set('doLoadPages', 1);
	}

	public function __construct(\Lightship\HtmlView $aView, $aComponentCode, $aInstallationCode) {
		parent::__construct($aView, $aComponentCode, $aInstallationCode);
		$aView->addEventListener('app-resolve-hints', [$this, 'resolveHints']);
	}
}
