<?php

namespace onsa\FatSecretCrawler;

use onsa\FatSecretCrawler\FatSecretMeaseurement as Measurement;

class FatSecretIngredient
{
		public $prominent;
		public $brand;

		public $measurements = array();

		function __construct(string $prominent = '', string $brand = '') {
			$this->prominent = $prominent;
			$this->brand = $brand;
		}

		public function addMeasurement(float $amount = 0, string $unit = '', Measurement $measurement) {
			$measurement->amount = $amount;
			$measurement->unit = $unit;
			array_push($this->measurements, $measurement);
		}
}
