<?php
namespace Solarfield\Pager;

abstract class PagerHtmlViewPlugin extends \Solarfield\Lightship\HtmlViewPlugin implements PagerHtmlViewPluginInterface {
	public function resolveHints() {
		$hints = $this->getView()->getHints();
		$hints->set('pagerPlugin.doLoadPages', 1);
	}

	public function __construct(\Solarfield\Lightship\HtmlView $aView, $aComponentCode) {
		parent::__construct($aView, $aComponentCode);
		$aView->addEventListener('resolve-hints', [$this, 'resolveHints']);
	}
}
