<?php
namespace Solarfield\Pager;

interface PagerControllerPluginInterface {
	/**
	 * Can be used to fill in defaults or extra data for a pages list item data structure.
	 * @param array $aItem
	 * @return array
	 */
	public function normalizeStubPage($aItem);

	/**
	 * Can be used to fill in defaults or extra data for a full page data structure.
	 * @param array $aItem
	 * @return array
	 */
	public function normalizeFullPage($aItem);

	/**
	 * @return array
	 */
	public function getPagesLookup();

	/**
	 * @return array
	 */
	public function getPagesTree();

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
