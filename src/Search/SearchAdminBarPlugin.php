<?php

declare(strict_types=1);

namespace Baraja\Cms\Search;


use Baraja\AdminBar\Plugin\Plugin;

final class SearchAdminBarPlugin implements Plugin
{
	public function render(): string
	{
		return '<div id="cmsGlobalSearch">
	<cms-global-search></cms-global-search>
</div>';
	}
}
