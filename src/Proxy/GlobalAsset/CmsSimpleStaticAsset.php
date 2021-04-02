<?php

declare(strict_types=1);

namespace Baraja\Cms\Proxy\GlobalAsset;


use Nette\Utils\Validators;

final class CmsSimpleStaticAsset implements CmsAsset
{
	public function __construct(
		private string $format,
		private string $url
	)
	{
		if (in_array($format, $supported = ['js', 'css'], true) === false) {
			throw new \InvalidArgumentException(
				'Format "' . $format . '" is not supported. '
				. 'Did you mean "' . implode('", "', $supported) . '"?',
			);
		}
		if (Validators::isUrl($url) === false) {
			throw new \InvalidArgumentException('URL "' . $url . '" is in invalid format.');
		}
	}


	public function getFormat(): string
	{
		return $this->format;
	}


	public function getUrl(): string
	{
		return $this->url;
	}
}
