<?php
namespace Solarfield\Pager;

use Solarfield\Batten\UnresolvedRouteException;
use Solarfield\Ok\StructUtils;

abstract class PagerControllerPlugin extends \Solarfield\Lightship\ControllerPlugin implements PagerControllerPluginInterface {
	private $pagesLookup;
	private $pagesTree;

	private $fullPage;
	private $fullPageCode;

	private function buildMaps() {
		$list = $this->loadStubPages();

		foreach ($list as &$page) {
			//restructure any child pages in the index, to the top level
			if (array_key_exists('childPages', $page)) {
				foreach ($page['childPages'] as $childPage) {
					$childPage['_parentPageCode'] = $page['code'];
					array_push($list, $childPage);
				}

				unset($page['childPages']);
			}

			//default some basic properties
			$page = array_replace([
				'code' => null,
				'title' => null,
				'slug' => null,
				'module' => null,
				'_parentPageCode' => null,
			], $page);
		}
		unset($page);

		//generate the lookup and tree
		$lookup = StructUtils::delegate($list, 'code');
		$tree = StructUtils::tree($list, 'code', '_parentPageCode', 'childPages', 'parentPage');

		foreach ($lookup as &$page) {
			unset($page['_parentPageCode']);

			//generate url
			$url = '';
			$tempPage = $page;
			do {
				if ($tempPage['slug']) {
					$url = $tempPage['slug'] . '/' . $url;
				}
			}
			while (($tempPage = $tempPage['parentPage']) != null);
			$page['url'] = '/' . $url;

			//normalize item
			$page = $this->normalizeStubPage($page);
		}
		unset($page);

		$this->pagesLookup = &$lookup;
		$this->pagesTree = &$tree;
	}

	/**
	 * @return array
	 */
	abstract protected function loadStubPages();

	/**
	 * @param string $aCode
	 * @return array
	 */
	protected function loadFullPage($aCode) {
		return null;
	}

	public function normalizeStubPage($aItem) {
		return array_replace([
			'code' => null,
			'title' => null,
			'slug' => null,
			'module' => null,
		], $aItem);
	}

	public function normalizeFullPage($aItem) {
		return $this->normalizeStubPage($aItem);
	}

	public function getStubPage($aCode) {
		$page = null;

		$lookup = $this->getPagesLookup();

		if (array_key_exists($aCode, $lookup)) {
			$page = $lookup[$aCode];
		}

		return $page;
	}

	public function getFullPage($aCode) {
		$code = (string)$aCode;

		if ($this->fullPageCode !== $code) {
			$fullPage = $this->loadFullPage($code);
			if (!$fullPage) $fullPage = $this->getStubPage($code);

			$this->fullPage = $fullPage ? $this->normalizeFullPage($fullPage) : null;
			$this->fullPageCode = $code;
		}

		return $this->fullPage;
	}

	public function getPagesLookup() {
		if ($this->pagesLookup === null) {
			$this->buildMaps();
		}

		return $this->pagesLookup;
	}

	public function getPagesTree() {
		if ($this->pagesTree === null) {
			$this->buildMaps();
		}

		return $this->pagesTree;
	}

	public function routeUrl($aUrl) {
		$info = null;

		//normalize slashes
		$rewriteUrl = '/' . $aUrl;
		$rewriteUrl = preg_replace('/\/{2,}/', '/', $rewriteUrl);

		//normalize slashes
		$directoryRewriteUrl = '/' . $rewriteUrl . '/';
		$directoryRewriteUrl = preg_replace('/\/{2,}/', '/', $directoryRewriteUrl);

		$subRewriteUrl = null;

		//create a page lookup based with urls for keys, sorted in reverse
		$pages = array();
		foreach ($this->getPagesLookup() as $page) {
			$pages[$page['url']] = $page;
		}
		krsort($pages);

		//find the first matching page
		$matchedPage = null;
		foreach ($pages as $page) {
			if (stripos($directoryRewriteUrl, $page['url']) === 0) {
				$matchedPage = $page;
				break;
			}
		}

		if ($matchedPage) {
			if (strcasecmp($directoryRewriteUrl, $matchedPage['url']) != 0) {
				$subRewriteUrl = preg_replace('/^' . preg_quote($matchedPage['url'], '/') . '/', '', $rewriteUrl);
			}

			$info = array(
				'page' => $matchedPage,
				'nextUrl' => $subRewriteUrl,
			);
		}

		return $info;
	}

	public function handleDoTask() {
		$hints = $this->getController()->getHints();
		$model = $this->getController()->getModel();

		if ($hints->get('pagerPlugin.doLoadPages')) {
			$model->set('pagerPlugin.pagesLookup', $this->getPagesLookup());
			$model->set('pagerPlugin.pagesTree', $this->getPagesTree());

			$currentPageCode = $hints->get('pagerPlugin.currentPage.code');
			if ($currentPageCode) {
				$model->set('pagerPlugin.currentPage', $this->getFullPage($currentPageCode));
			}
		}
	}

	public function handleProcessRoute($aEvt) {
		$info = null;

		$prefix = '';

		//if we already have a current page
		if (($currentPageCode = $this->getController()->getHints()->get('pagerPlugin.currentPage.code'))) {
			$currentPage = $this->getStubPage($currentPageCode);

			if (!$currentPage) throw new UnresolvedRouteException(
				"Was routed to page with code '$currentPageCode', but could not get stub."
			);

			//use its url as a prefix to the (sub)url being routed
			$prefix = $currentPage['url'];
		}

		$result = $this->routeUrl($prefix . $aEvt->buffer['inputRoute']['nextRoute']);

		if ($result) {
			$aEvt->buffer['outputRoute'] = array(
				'moduleCode' => $result['page']['module']['code'],
				'nextRoute' => $result['nextUrl'],
			);

			$this->getController()->getHints()->set('pagerPlugin.currentPage.code', $result['page']['code']);
		}
	}

	public function __construct(\Solarfield\Batten\ControllerInterface $aController, $aComponentCode) {
		parent::__construct($aController, $aComponentCode);

		$aController->addEventListener('before-do-task', [$this, 'handleDoTask']);
		$aController->addEventListener('process-route', [$this, 'handleProcessRoute']);
	}
}
