<?php

declare(strict_types=1);

namespace Baraja\Cms\Proxy\GlobalAsset;


final class CustomGlobalAssetManager
{
	/** @var CmsAsset[] */
	private array $assets = [];


	public function addAsset(CmsAsset $asset): void
	{
		$this->assets[] = $asset;
	}


	/**
	 * @return string[]
	 */
	public function toArray(): array
	{
		$return = [];
		foreach ($this->assets as $asset) {
			$return[$asset->getUrl()] = $asset->getFormat();
		}

		return $return;
	}
}
