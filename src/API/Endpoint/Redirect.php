<?php

declare(strict_types=1);

namespace Baraja\Cms\Api\Endpoint;


class Redirect extends ApiEndpointException
{

	/**
	 * @var mixed[]
	 */
	private $data;

	/**
	 * @param mixed[] $data
	 */
	public function __construct(array $data)
	{
		parent::__construct('Redirect');
		$this->data = $data;
	}

	/**
	 * @param string $url
	 * @throws Redirect
	 */
	public static function url(string $url): void
	{
		throw new self([
			'url' => $url,
		]);
	}

	/**
	 * @param string $destination
	 * @param mixed[] $args
	 * @throws Redirect
	 */
	public static function link(string $destination, array $args = []): void
	{
		throw new self([
			'destination' => $destination,
			'args' => $args,
		]);
	}

	/**
	 * @return mixed[]
	 */
	public function getData(): array
	{
		return $this->data;
	}

}