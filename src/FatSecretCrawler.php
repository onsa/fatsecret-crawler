<?php

namespace onsa\FatSecretCrawler;

use Goutte\Client;
use Symfony\Component\DomCrawler\Crawler;
use GuzzleHttp\Client as GuzzleClient;

use onsa\FatSecretCrawler\FatSecretIngredient as Ingredient;
use onsa\FatSecretCrawler\FatSecretMeasurement as Measurement;
use onsa\FatSecretCrawler\FatSecretMacro as Macro;

/** FatSecretCrawler crawls fatsecret.co.uk and returns ingredients with density (if calculable)
 *	and with multiple measurements (if available) in standard UK units.
 *
 * @author onsa
 * @version 1.0.0
 */
class FatSecretCrawler
{

	private $fatSecretURL = 'http://www.fatsecret.co.uk';
	private $searchURL = '/calories-nutrition/search?q=';

	private $recordUnit;

	private $measurements = [
		'volume' => [
			'cup', 'tbsp', 'tsp', 'l', 'ml', 'fl oz', 'pint'
		],
		'mass' => [
			'g', 'kg', 'oz', 'lb'
		]
	];

	private $imperialisation = [							//	coefficients for US volumes to yield UK volumes
		'cup' => 0.844682,
		'tbsp' => 0.832674,
		'tsp' => 0.832674,
		'l' => 1,
		'ml' => 1,
		'fl oz' => 1.04084,
		'pint' => 0.832674
	];

	private $standardisation = [
		'cup' => 284,							//	coefficients for UK volumes to yield ml
		'tbsp' => 17.75,
		'tsp' => 5.916666667,
		'l' => 1000,
		'ml' =>  1,
		'fl oz' => 28.413062500,
		'pint' => 568.261250000,
		'g' => 1,									//	coefficients for UK volumes to yield g
		'kg' => 1000,
		'oz' => 28.349523125,
		'lb' => 453.592370000
	];

	function __construct(string $searchTerm, int $hitCount) {

		$this->searchTerm = $searchTerm;
		$this->hitCount = $hitCount;																		//	store hit count and set remaining hit counter to the same value
		$this->remainingHits = $hitCount;
		$this->init();
	}

/*		HELPERS		*/

	/**
	 * Return imperial equivalent of US measurements.
	 *
	 * @param  Measurement  $measurementObject
	 * @return Measurement
	 */
	private function imperialise(Measurement $measurementObject) {
		if (array_search($measurementObject->unit, $this->measurements['volume']) !== false) {
			$measurementObject->calorie->amount = round($measurementObject->calorie->amount * $this->imperialisation[$measurementObject->unit], 4);
			if (isset($measurementObject->fat)) {
				$measurementObject->fat->amount = round($measurementObject->fat->amount * $this->imperialisation[$measurementObject->unit], 4);
			}
			if (isset($measurementObject->carbohydrate)) {
				$measurementObject->carbohydrate->amount = round($measurementObject->carbohydrate->amount * $this->imperialisation[$measurementObject->unit], 4);
			}
			if (isset($measurementObject->sugar)) {
				$measurementObject->sugar->amount = round($measurementObject->sugar->amount * $this->imperialisation[$measurementObject->unit], 4);
			}
			if (isset($measurementObject->protein)) {
				$measurementObject->protein->amount = round($measurementObject->protein->amount * $this->imperialisation[$measurementObject->unit], 4);
			}
		}
		return $measurementObject;
	}

	/**
	 * Parse fraction string and return its decimal value.
	 *
	 * @param  string  $fractionString
	 * @return float
	 */
	private function parseFraction(string $fractionString)
	{
		preg_match('/\d+\/\d+/', $fractionString, $fractionMatch);
		if (count($fractionMatch) === 0) {
			return acos(1.01);
		}
		$fraction = explode('/', $fractionMatch[0]);
		return floatval(intval(trim($fraction[0])) / intval(trim($fraction[1])));
	}

	/**
	 * Return calories / (cubic meter | kg) values for a measurement.
	 *
	 * @param  float  $calorie
	 * @param  Measurement  $measurementObject
	 * @return float
	 */
	private function standardise(float $calorie, Measurement $measurementObject)
	{
		if (!isset($this->standardisation[$measurementObject->unit])) {
			$standardCoefficient = $this->standardisation[substr($measurementObject->unit, 0, strlen($measurementObject->unit) - 1)];
		} else {
			$standardCoefficient = $this->standardisation[$measurementObject->unit];
		}
		return round(($calorie / $measurementObject->amount) / $standardCoefficient, 4);
	}

/*		PROCESSING OF DATA			*/

	/**
	 * Clear raw measurements from raw summary data by removing vague units (e.g. serving).
	 *
	 */
	private function clearMeasurements() {
		foreach ($this->rawSummaryData as &$rawSummaryUnit) {
			foreach ($rawSummaryUnit->measurements as &$measurement) {
				if (array_search($measurement->unit, $this->measurements['volume']) === false &&
						array_search($measurement->unit, $this->measurements['mass']) === false) {
					$measurement = null;
				} else {
					$measurement = $this->imperialise($measurement);
				}
			}
			$rawSummaryUnit->measurements = array_values(array_filter($rawSummaryUnit->measurements));
		}
	}

/*		DENSITY CRAWLER		*/

	/**
	 * Return density of ingredient.
	 *
	 * @param  Measurement  $firstMeasurement
	 * @param  Measurement  $secondMeasurement
	 * @return float
	 */
	private function getDensity(Measurement $firstMeasurement, Measurement $secondMeasurement)
	{
		$firstUnitaryCalories = $this->standardise($firstMeasurement->calorie->amount, $firstMeasurement);
		$firstMeasurementType = $this->getMeasurementType($firstMeasurement->unit);
		$secondUnitaryCalories = $this->standardise($secondMeasurement->calorie->amount, $secondMeasurement);
		if ($firstMeasurementType === 'mass') {
			$density = round($secondUnitaryCalories / $firstUnitaryCalories, 4);
		} else {
			$density = round($firstUnitaryCalories / $secondUnitaryCalories, 4);
		}
		return $density;
	}

	/**
	 * Get density of ingredient.
	 *
	 * @param  Ingredient  $currentRecordObject
	 * @return float
	 */
	private function lookForDensity(Ingredient $currentRecordObject)
	{
		$density = null;
		if (count($currentRecordObject->measurements) > 1) {
			$firstMeasurement = $currentRecordObject->measurements[0];
			$firstMeasurementType = $this->getMeasurementType($firstMeasurement->unit);
			foreach($currentRecordObject->measurements as $measurement) {
				$measurementType = $this->getMeasurementType($measurement->unit);
				if ($measurementType !== null && $measurementType !== $firstMeasurementType) {
					$density = round($this->getDensity($firstMeasurement, $measurement), 4);
					break;
				}
			}
		}
		return $density;
	}

/*		SUGAR CRAWLER		*/

	/**
	 * Get sugar content for ingredient.
	 *
	 * @param  string $detailedUrl
	 * @return float
	 */
	private function getSugar(string $detailedUrl)
	{
		$detailedCrawler = $this->client->request('GET', $this->fatSecretURL . $detailedUrl);
		$detailRows = $detailedCrawler->filter('#content .nutpanel table tr');
		$crawlResultArray = array_values(
				array_filter(
					$detailRows->each(function (Crawler $detailRow) {
					$cells = $detailRow->filter('td')->each(function (Crawler $detailCell) {
						return $detailCell->text();
					});
					foreach ($cells as $cellIndex => $cell) {
						if($cell === 'Sugar') {
							return $cells[$cellIndex + 1];
						}
					}
				})
			)
		);
		$sugarText = count($crawlResultArray) > 0 ? $crawlResultArray[0] : '0 g';
		return $this->getMeasurementObject($sugarText, true);
	}

/*		MAIN CRAWLER		*/

	/**
	 * Separate amount from unit and return an object conatining both.
	 *
	 * @param  string  $measurementString
	 * @param  bool  $macro
	 * @return Measurement | Macro
	 */
	private function getMeasurementObject(string $measurementString, bool $macro) {
		$amount = $measurementString;
		$unit = '';
		if (strpos($measurementString, '/') !== false) {
			$amount = $this->parseFraction($measurementString);
			preg_match('/\/\d+\s*(\S*)/', $measurementString, $unitMatch);
			if (count($unitMatch) > 0) {
				$unit = $unitMatch[1];
			}
		} else {
			while(!is_numeric($amount)) {
				$amount = substr($amount, 0, strlen($amount) - 1);
				$unit = substr($measurementString, strlen($amount), 1) . $unit;
			}
		}
		if ($macro) {
			$measurementObject = new Macro(floatval($amount), trim($unit));
		} else {
			$measurementObject = new Measurement(floatval($amount), trim($unit));
		}
		return $measurementObject;
	}

	/**
	 * Take a measurement object and the corresponding macro string and append the object with parsed macro data.
	 *
	 * @param  Measurement  $measurementObject
	 * @param  string  $macroString
	 * @return Measurement
	 */
	private function appendMacros(Measurement $measurementObject, string $macroString)
	{
		$macroArray = explode(' | ', $macroString);
		foreach($macroArray as $macro) {
			$macroMeasurement = explode(': ', $macro);
			if ($macroMeasurement[0] === 'Calories') {
				$macroType = 'calorie';
			}
			if ($macroMeasurement[0] === 'Fat') {
				$macroType = 'fat';
			}
			if ($macroMeasurement[0] === 'Carbs') {
				$macroType = 'carbohydrate';
			}
			if ($macroMeasurement[0] === 'Prot') {
				$macroType = 'protein';
			}
			$measurementObject->{$macroType} = $this->getMeasurementObject($macroMeasurement[1], true);
		}
		return $measurementObject;
	}

	/**
	 * Take a summary line with 'other sizes' and return measurement object with calorie value.
	 *
	 * @param  string  $mixedSummaryLine
	 * @return Measurement
	 */
	private function getAlternativeCalories(string $mixedSummaryLine)
	{
		$splitMixedSummaryLine = explode(' - ', $mixedSummaryLine);
		$measurementObject = $this->getMeasurementObject($splitMixedSummaryLine[0], false);
		$calorieObject = $this->getMeasurementObject($splitMixedSummaryLine[1], true);
		$measurementObject->calorie = $calorieObject;
		return $measurementObject;
	}

	/**
	 * Take an array of summary lines, filter out non-parsable, call parsing on line with 'other sizes' and return array of measurement objects.
	 *
	 * @param  array  $mixedSummaryLines
	 * @return array
	 */
	private function parseRestMixedSummaryLines(array $mixedSummaryLines)
	{
		$alternativeMeasurementArray = [];
		foreach($mixedSummaryLines as $mixedSummaryLine) {
			if (
				strpos($mixedSummaryLine, 'Other sizes') !== false ||
				strpos($mixedSummaryLine, 'more...') !== false ||
				strpos($mixedSummaryLine, 'Nutrition Facts - Similar') !== false
			) {
				continue;
			} else {
				$clearedSummaryLine = trim(str_replace(',', '', $mixedSummaryLine));
				$alternativeMeasurementArray[] = $this->getAlternativeCalories($clearedSummaryLine);
			}
		}
		return $alternativeMeasurementArray;
	}

	/**
	 * Take first summary line, extract main (and alternative) measurement(s), append with macros and return parsed data.
	 *
	 * @param  string  $firstMixedSummaryLine
	 * @return array
	 */
	private function parseFirstMixedSummaryLine(string $firstMixedSummaryLine)
	{
		$firstMeasurementBorder = ' - ';
		//	look for alternative measurement between parantheses in first line & append macros
		preg_match('/\((\d+\.?\d*.*)\)/', $firstMixedSummaryLine, $paranthesesMatches, PREG_OFFSET_CAPTURE);
		if (count($paranthesesMatches) > 0) {
			$firstMeasurementBorder = '(';
			//	parse matched group (i.e. string between parantheses) & append macros
			$measurementArray[] = $this->appendMacros(
				$this->getMeasurementObject($paranthesesMatches[1][0], false),
				trim(explode(' - ', $firstMixedSummaryLine)[1])
			);
		}
		//	parse first measurement (before the opening paranthesis)
		$measurementArray[] = $this->appendMacros(
			$this->getMeasurementObject(
				trim(
					str_replace(
						'per', '',
						explode($firstMeasurementBorder, $firstMixedSummaryLine)[0]
					)
				), false
			),
			trim(explode(' - ', $firstMixedSummaryLine)[1])
		);
		return $measurementArray;
	}

	/**
	 * Take mixed summary text and return an array of measurement objects.
	 *
	 * @param  string  $mixedSummaryText
	 * @return array
	 */
	private function extractMixedSummary(string $mixedSummaryText)
	{
		//	split mixed summary lines in array
		$mixedSummaryLines = array_values(array_filter(
			explode(PHP_EOL, $mixedSummaryText),
			function($mixedSummaryLine) {
				return trim($mixedSummaryLine) !== '';
			}
		));

		$measurementArray = $this->parseFirstMixedSummaryLine(array_shift($mixedSummaryLines));
		$measurementArray = array_merge($measurementArray, $this->parseRestMixedSummaryLines($mixedSummaryLines));
		return $measurementArray;
	}

	/**
	 * Return type of measurement.
	 *
	 * @param  string  $measurementUnit
	 * @return string
	 */
	private function getMeasurementType(string $measurementUnit)
	{
		foreach($this->measurements as $measurementType => $measurementArray) {
			if (in_array($measurementUnit, $measurementArray)) {
				return $measurementType;
			}
		}
	}

	/**
	 * Loop through sub nodes of summary element and extract raw data.
	 *
	 * @param  \Symfony\Component\DomCrawler\Crawler  $summaryElement
	 * @return object
	 */
	private function crawlSummary(Crawler $summaryElement)
	{
		$classReference = $this;
		$this->currentRecordObject = new Ingredient();
		$summaryElementChildren = $summaryElement->children();
		$summaryElementChildren->each(function (Crawler $summaryElementChild) use ($classReference) {
			if ($summaryElementChild->attr('class') === 'prominent') {
				$classReference->currentRecordObject->prominent = trim($summaryElementChild->text());
			} elseif ($summaryElementChild->attr('class') === 'brand') {
				$classReference->currentRecordObject->brand = trim($summaryElementChild->text());
			} elseif($summaryElementChild->nodeName() === 'div') {
				//	extract data from summary page
				$classReference->currentRecordObject->measurements = $this->extractMixedSummary($summaryElementChild->text());
				//	crawl detailed page and extract sugar
				$anchors = $summaryElementChild->filter('a');
				$anchors->each(function (Crawler $anchor) use ($classReference) {
					if ($anchor->text() === 'Nutrition Facts') {
						$classReference->currentRecordObject->measurements[0]->sugar = $this->getSugar($anchor->attr('href'));
					}
				});
			}
		});
		$this->currentRecordObject->density = $this->lookForDensity($this->currentRecordObject);
		return $this->currentRecordObject;
	}

	/**
	 * Loop through summary page records and call for extraction of raw data.
	 *
	 * @param  \Symfony\Component\DomCrawler\Crawler  $crawler
	 * @return object
	 */
	private function crawlPage(Crawler $crawler)
	{
		$summaryElements = $crawler->filter('table.generic.searchResult tr td');
		return array_filter(
			$summaryElements->each(
				function(Crawler $summaryElement, $index) {
					if ($index < $this->remainingHits) {
						return $this->crawlSummary($summaryElement);
					}
				}
			),
			function($unfilteredResults) { return !is_null($unfilteredResults); }
		);
	}

	/**
	 * Execute search for each page found.
	 *
	 * @return array
	 */
	public function search()
	{
		//	crawl first summary page and store returned array;
		$this->rawSummaryData = $this->crawlPage($this->summaryCrawlers[0]);
		if ($this->pageCount > 0) {
			//	as long as page index is smaller than total record pages &
			//	as long as count of already extracted records is smaller than hit count -- loop through pages
			for ($page = 1; $page < $this->pageCount && count($this->rawSummaryData) < $this->hitCount; $page++) {
				$this->remainingHits = $this->hitCount - count($this->rawSummaryData);
				//	create new crawler
				$currentSummaryCrawler = $this->client->request('GET', $this->firstSummaryUrl . '&pg=' . $page);
				//	crawl next summary page and add returned array to raw summary data array
				$this->rawSummaryData = array_merge($this->rawSummaryData, $this->crawlPage($currentSummaryCrawler));
				//	store crawler
				$this->summaryCrawlers[] = $currentSummaryCrawler;
			}
		}
		//	get rid of vague measurements
		$this->clearMeasurements();
		return $this->rawSummaryData;
	}

/*		INIT		*/

	/**
	 * Get number of pages of found records.
	 *
	 * @param  \Symfony\Component\DomCrawler\Crawler  $crawler
	 * @return int
	 */
	private function getPageCount(Crawler $crawler)
	{
		if ($crawler->filter('div.searchNoResult')->count() === 0) {
			$recordCount = floatval(trim(explode('of', $crawler->filter('div.searchResultSummary')->text())[1]));
			return ceil($recordCount / 10);
		} else {
			return 0;
		}
	}

	/**
	 * Set up crawler for first summary page.
	 *
	 * @return  \Symfony\Component\DomCrawler\Crawler  $crawler
	 */
	private function setUpCrawler()
	{
		$this->client = new Client();																		//	create new client
		$guzzleClient = new GuzzleClient(array(													//	create new guzzle client
				'timeout' => 3																							//	with 3 seconds of timeout
		));
		$this->client->setClient($guzzleClient);															//	set guzzle client as main client
		return $this->client->request('GET', $this->firstSummaryUrl);					//	call first summary page and create crawler
	}

	/**
	 * Build search url to call.
	 *
	 * @param  string  $suffix
	 * @return string
	 */
	private function buildSearchUrl($suffix)
	{
		return $this->fatSecretURL . $this->searchURL . $suffix;
	}

	/**
	 * Compute class properties, initialise array for summary crawlers and store number of pages returned for current search.
	 *
	 */
	private function init()
	{
		$this->firstSummaryUrl = $this->buildSearchUrl($this->searchTerm);	//	create and store first summary url
		$crawler = $this->setUpCrawler();																		//	create first summary crawler
		$this->pageCount = $this->getPageCount($crawler);										//	get page count for current search
		$this->summaryCrawlers[] = $crawler;																//	store crawler in array
	}

}
