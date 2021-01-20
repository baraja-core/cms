<?php

declare(strict_types=1);

namespace Baraja\Cms\Proxy\GlobalAsset;


interface CustomGlobalAssetManagerAccessor
{
	public function get(): CustomGlobalAssetManager;
}
