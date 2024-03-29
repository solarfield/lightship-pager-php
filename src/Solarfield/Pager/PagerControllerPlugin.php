<?php
namespace Solarfield\Pager;

use Exception;
use Solarfield\Lightship\Errors\UnresolvedRouteException;
use Solarfield\Lightship\Events\ProcessRouteEvent;
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
				'slugMatchMode' => null, //'equal' (default), 'placeholder'
				'module' => null,
				'parentPageCode' => null,
			], $page);

			if (!$page['code']) throw new Exception(
				"Encountered page with no 'code'."
			);

			// set some properties which are always generated internally
			$page['parentPage'] = null;
			$page['url'] = null;
			$page['urlPattern'] = null;
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
			
			$tempPage = $page;
			do {
				if ($tempPage['slug']) {
					$url = $tempPage['slug'] . '/' . $url;
					
					if ($tempPage['slugMatchMode'] == 'placeholder') {
						array_unshift($page['slugMatches'], [
							'name' => $tempPage['slug'],
						]);
						
						$urlPattern = '([^\/]+)\/' . $urlPattern;
					}
					
					else {
						$urlPattern = preg_quote($tempPage['slug'], '/') . '\/' . $urlPattern;
					}
				}
			}
			while (($tempPage = $tempPage['parentPage']) != null);
			
			$page['url'] = '/' . $url;
			
			$urlPattern = '/^\/' . $urlPattern . '(.*)/i';
			$page['urlPattern'] = $urlPattern;

			
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
			// ignore un-routable pages
			if ($page['slugMatchMode'] != 'never') {
				$pages[$page['url']] = $page;
			}
		}
		krsort($pages);
		
		//find the first matching page
		$matchedPage = null;
		$matches = [];
		foreach ($pages as $page) {
			if (preg_match($page['urlPattern'], $directoryRewriteUrl, $matches)) {
				$matchedPage = $page;
				break;
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
				StructUtils::set($info['hints'], $slugMatch['name'], $matches[$i]);
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
		$route = $aEvt->getContext()->getRoute();

		if ($route->getNextStep() !== null) {
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

			$result = $this->routeUrl($prefix . $route->getNextStep());

			if ($result) {
				$newRoute = array(
					'moduleCode' => $result['page']['module']['code'],
					'nextStep' => $result['nextUrl'],
					'hints' => $result['hints'],
				);
				
				//set a hint for the new current page
				StructUtils::set($newRoute['hints'], 'pagerPlugin.currentPage.code', $result['page']['code']);
				
				$aEvt->getContext()->setRoute($newRoute);
			}
		}
	}

	public function __construct(\Solarfield\Lightship\ControllerInterface $aController, $aComponentCode) {
		parent::__construct($aController, $aComponentCode);

		$aController->addEventListener('do-task', [$this, 'handleDoTask']);
		$aController->addEventListener('process-route', [$this, 'handleProcessRoute']);
	}
}
