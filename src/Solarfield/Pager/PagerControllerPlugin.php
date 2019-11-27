<?php
namespace Solarfield\Pager;

use Solarfield\Batten\UnresolvedRouteException;
use Solarfield\Lightship\Events\ProcessRouteEvent;
use Solarfield\Ok\StructUtils;

abstract class PagerControllerPlugin extends \Solarfield\Lightship\ControllerPlugin implements PagerControllerPluginInterface {
	private $pagesLookup;
	private $pagesTree;

	private $fullPage;
	private $fullPageCode;

	private function buildMaps() {
		$lowValue = '0000000000';
		
		$list = $this->loadStubPages();

		foreach ($list as &$page) {
			//restructure any child pages in the index, to the top level
			if (array_key_exists('childPages', $page)) {
				foreach ($page['childPages'] as $childPage) {
					$childPage['parentPageCode'] = $page['code'];
					array_push($list, $childPage);
				}

				unset($page['childPages']);
			}

			//default some basic properties
			$page = array_replace([
				'code' => null,
				'title' => null,
				'slug' => null,
				'slugMatchMode' => null, //'equal' (default), 'placeholder', 'never'
				'module' => null,
				'parentPageCode' => null,
				'url' => null,
			], $page);

			// set some properties which are always generated internally
			$page['parentPage'] = null;
			$page['urlPattern'] = null;
			$page['urlSortValue'] = '';
			$page['slugMatches'] = [];

			// TODO: _parentPageCode is deprecated
			if (!$page['parentPageCode'] && array_key_exists('_parentPageCode', $page)) {
				$page['parentPageCode'] = $page['_parentPageCode'];
			}
			unset($page['_parentPageCode']);

			// allow shorthand moduleCode property
			if (!$page['module'] && array_key_exists('moduleCode', $page)) {
				$page['module'] = [
					'code' => $page['moduleCode'],
				];
			}
			unset($page['moduleCode']);
		}
		unset($page);

		//generate the lookup and tree
		$lookup = StructUtils::delegate($list, 'code');
		$tree = StructUtils::tree($list, 'code', 'parentPageCode', 'childPages', 'parentPage');

		foreach ($lookup as &$page) {
			unset($page['parentPageCode']);

			
			//generate url & regex used by routeUrl()
			
			$url = '';
			$urlPattern = '';
			$urlSortValue = '';
			
			$tempPage = $page;
			do {
				// if at any time we encounter a page with slugMatchMode=never,
				// clear the $urlPattern (marking it as un-routable), and exit.
				if ($tempPage['slugMatchMode'] == 'never') {
					$urlPattern = null;
					$urlSortValue = $lowValue;
					break;
				}
				
				if ($tempPage['slug']) {
					$url = $tempPage['slug'] . '/' . $url;
					
					if ($tempPage['slugMatchMode'] == 'placeholder') {
						array_unshift($page['slugMatches'], [
							'name' => $tempPage['slug'],
						]);
						
						$urlPattern = '([^\/]+)\/' . $urlPattern;
						$urlSortValue = $lowValue . '/' . $urlSortValue;
					}
					
					else {
						$urlPattern = preg_quote($tempPage['slug'], '/') . '\/' . $urlPattern;
						$urlSortValue = $tempPage['slug'] . '/' . $urlSortValue;
					}
				}
			}
			while (($tempPage = $tempPage['parentPage']) != null);
			
			// if the page does not already have a url (i.e. it was not explicitly specified in the the data)
			if ($page['url'] === null) {
				// set it to the generated url
				$page['url'] = '/' . $url;
			}
			
			// if we have a $urlPattern, set it on the page, for the router to use later
			if ($urlPattern !== null) {
				$urlPattern = '/^\/' . $urlPattern . '(.*)/i';
				$page['urlPattern'] = $urlPattern;
			}
			
			$page['urlSortValue'] = $urlSortValue;
			
			//normalize item
			$page = $this->normalizeStubPage($page);
			
			unset($page);
		}

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
		
		//create a page lookup based with urls for keys, sorted by urlSortValue descending
		$pages = array();
		foreach ($this->getPagesLookup() as $page) {
			$pages[$page['url']] = $page;
		}
		uasort($pages, function ($a, $b) {
			$s = $b['urlSortValue'] <=> $a['urlSortValue'];
			if ($s !== 0) return $s;
			
			return 0;
		});
		
		//find the first matching page
		$matchedPage = null;
		$matches = [];
		foreach ($pages as $page) {
			if ($page['urlPattern']) {
				if (preg_match($page['urlPattern'], $directoryRewriteUrl, $matches)) {
					$matchedPage = $page;
					break;
				}
			}
		}
		
		if ($matchedPage) {
			//remove first match, which is the entire url
			array_shift($matches);
			
			$info = [
				'page' => $matchedPage,
				'hints' => [],
				'nextUrl' => preg_replace($matchedPage['urlPattern'], '$' . count($matches), $rewriteUrl) ?: null,
			];
			
			//set hints from the slug placeholders and their associated values
			foreach ($matchedPage['slugMatches'] as $i => $slugMatch) {
				$info['hints'][$slugMatch['name']] = $matches[$i];
			}
		}
		
		return $info;
	}
	
	public function invalidateCache() {
		$this->pagesLookup = null;
		$this->pagesTree = null;
		$this->fullPage = null;
		$this->fullPageCode = null;
	}

	public function doLoadPages() {
		$model = $this->getController()->getModel();
		$hints = $this->getController()->getHints();

		$model->set('pagerPlugin.pagesLookup', $this->getPagesLookup());
		$model->set('pagerPlugin.pagesTree', $this->getPagesTree());

		$currentPageCode = $hints->get('pagerPlugin.currentPage.code');
		if ($currentPageCode) {
			$model->set('pagerPlugin.currentPage', $this->getFullPage($currentPageCode));
		}
	}

	public function handleDoTask() {
		$hints = $this->getController()->getHints();

		if ($hints->get('pagerPlugin.doLoadPages')) {
			$this->doLoadPages();
		}
	}

	public function handleProcessRoute(ProcessRouteEvent $aEvt) {
		$info = $aEvt->getRoute();

		if (($info['nextRoute']??null) !== null) {
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

			$result = $this->routeUrl($prefix . $info['nextRoute']);

			if ($result) {
				$aEvt->setRoute(array(
					'moduleCode' => $result['page']['module']['code'],
					'nextRoute' => $result['nextUrl'],
				));

				foreach ($result['hints'] as $k => $v) {
					$this->getController()->getHints()->set($k, $v);
				}
				
				$this->getController()->getHints()->set('pagerPlugin.currentPage.code', $result['page']['code']);
			}
		}
	}

	public function __construct(\Solarfield\Batten\ControllerInterface $aController, $aComponentCode) {
		parent::__construct($aController, $aComponentCode);

		$aController->addEventListener('do-task', [$this, 'handleDoTask']);
		$aController->addEventListener('process-route', [$this, 'handleProcessRoute']);
	}
}
