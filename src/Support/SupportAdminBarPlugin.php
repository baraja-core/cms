<?php

declare(strict_types=1);

namespace Baraja\Cms\Support;


use Baraja\AdminBar\Plugin\Plugin;

final class SupportAdminBarPlugin implements Plugin
{
	public function render(): string
	{
		return '<div id="cmsSupportPanel">
	<cms-support-admin-panel></cms-support-admin-panel>
</div>';
	}
}
