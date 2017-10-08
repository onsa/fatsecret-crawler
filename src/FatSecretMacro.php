<?php

namespace onsa\FatSecretCrawler;

class FatSecretMacro
{
	public $amount;
	public $unit;

	function __construct(float $amount, string $unit) {
		$this->amount = $amount;
		$this->unit = $unit;
	}
}
