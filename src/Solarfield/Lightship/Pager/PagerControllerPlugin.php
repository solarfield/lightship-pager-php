<?php
namespace Solarfield\Lightship\Pager;

use Solarfield\Ok\StructUtils;

abstract class PagerControllerPlugin extends \Solarfield\Lightship\ControllerPlugin implements PagerControllerPluginInterface {
	public function normalizePagesListItem($aItem) {
		return $aItem;
	}

	public function normalizeFullPage($aItem) {
		return $aItem;
	}

	public function getPagesMap() {
		static $pages;

		if ($pages === null) {
			$list = $this->getPagesList();

			foreach ($list as &$page) {
				//restructure any child pages in the index, to the top level
				if (array_key_exists('childPages', $page)) {
					foreach ($page['childPages'] as $childPage) {
						$childPage['_parentPageCode'] = $page['code'];
						array_push($list, $childPage);
					}
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
				$page = $this->normalizePagesListItem($page);
			}
			unset($page);

			$pages = [
				'lookup' => &$lookup,
				'tree' => &$tree,
			];
		}

		return $pages;
	}

	public function routeUrl($aUrl) {
		$info = null;

		//normalize slashes
		$rewriteUrl = '/' . $aUrl . '/';
		$rewriteUrl = preg_replace('/\/{2,}/', '/', $rewriteUrl);

		//normalize slashes
		$directoryRewriteUrl = '/' . $rewriteUrl . '/';
		$directoryRewriteUrl = preg_replace('/\/{2,}/', '/', $directoryRewriteUrl);

		$subRewriteUrl = null;

		//create a page lookup based with urls for keys, sorted in reverse
		$pages = array();
		foreach ($this->getPagesMap()['lookup'] as $page) {
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

	public function handleDoTask($aEvt) {
		$hints = $this->getHints();
		$model = $this->getModel();

		if ($hints->get('doLoadPages')) {
			$model->set('pagesMap', $this->getPagesMap());

			$currentPageCode = $hints->get('currentPage.code');
			if ($currentPageCode) {
				$model->set('currentPage', $this->normalizeFullPage($this->getFullPage($currentPageCode)));
			}
		}
	}

	public function handleProcessRoute($aEvt) {
		$info = null;

		$result = $this->routeUrl($aEvt->buffer['inputRoute']['nextRoute']);

		if ($result) {
			$aEvt->buffer['outputRoute'] = array(
				'moduleCode' => $result['page']['module']['code'],
				'nextRoute' => $result['nextUrl'],
			);

			$this->getHints()->set('currentPage.code', $result['page']['code']);
		}
	}

	public function __construct(\Solarfield\Batten\ControllerInterface $aController, $aComponentCode, $aInstallationCode) {
		parent::__construct($aController, $aComponentCode, $aInstallationCode);

		$aController->addEventListener('app-before-do-task', [$this, 'handleDoTask']);
		$aController->addEventListener('app-process-route', [$this, 'handleProcessRoute']);
	}
}
