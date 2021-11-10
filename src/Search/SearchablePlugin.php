<?php

declare(strict_types=1);

namespace Baraja\Cms\Search;


use Baraja\Plugin\Plugin;

interface SearchablePlugin extends Plugin
{
	/**
	 * Base related entity for this plugin (for ex. Article, Product, ...).
	 *
	 * @return class-string
	 */
	public function getBaseEntity(): string;

	/**
	 * Returns a list of columns to search.
	 * Use the syntax from the "baraja-core/doctrine-fulltext-search" package
	 * to define the selector and column names.
	 *
	 * @return array<int, string>
	 */
	public function getSearchColumns(): array;
}
