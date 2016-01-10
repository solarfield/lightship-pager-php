<?php
namespace Solarfield\Lightship\Pager;

interface PagerControllerPluginInterface {
	/**
	 * @return array
	 */
	public function getPagesList();

	/**
	 * Can be used to fill in defaults or extra data for a pages list item data structure.
	 * @param array $aItem
	 * @return array
	 */
	public function normalizePagesListItem($aItem);

	/**
	 * Can be used to fill in defaults or extra data for a full page data structure.
	 * @param array $aItem
	 * @return array
	 */
	public function normalizeFullPage($aItem);

	/**
	 * @return array
	 */
	public function getPagesMap();

	/**
	 * @param string $aCode
	 * @return array
	 */
	public function getFullPage($aCode);

	/**
	 * @param string $aCode
	 * @return array|null
	 */
	public function getStubPage($aCode);

	/**
	 * @param string $aUrl
	 * @return array
	 */
	public function routeUrl($aUrl);
}
