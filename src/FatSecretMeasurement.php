<?php

namespace onsa\FatSecretCrawler;

class FatSecretMeasurement
{
	public $amount;
	public $unit;
	public $calorie;
	public $carbohydrate;
	public $sugar;
	public $fat;
	public $protein;

	function __construct(float $amount, string $unit) {
		$this->amount = $amount;
		$this->unit = $unit;
	}

	public function addProperty(string $type, float $amount, string $unit = null) {
		$macro = new \stdClass();
		$macro->amount = $amount;
		if (isset($unit)) {
			$macro->unit = $unit;
		} else {
			$macro->unit = $type === 'calorie' ? 'kcal' : 'g';
		}
		$this->{$type} = $macro;
	}
}
