<?php

declare(strict_types=1);

namespace Baraja\Cms\Proxy\GlobalAsset;


interface CmsAsset
{
	public function getUrl(): string;

	public function getFormat(): string;
}
