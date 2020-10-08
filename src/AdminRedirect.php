<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Nette\Utils\Validators;

final class AdminRedirect extends \RuntimeException
{

	/** @var string */
	private $url;


	public function __construct(string $url)
	{
		if (Validators::isUrl($url) === false) {
			throw new \InvalidArgumentException('URL "' . $url . '" is not in valid format.');
		}

		parent::__construct('Redirect to URL "' . $url . '"');
		$this->url = $url;
	}


	public static function url(string $url): void
	{
		throw new self($url);
	}


	/**
	 * @param mixed[] $params
	 */
	public static function link(string $route, array $params = []): void
	{
		throw new self(LinkGenerator::generateInternalLink($route, $params));
	}


	public function getUrl(): string
	{
		return $this->url;
	}
}
