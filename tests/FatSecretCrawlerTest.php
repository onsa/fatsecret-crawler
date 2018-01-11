<?php

namespace onsa\FatSecretCrawler\Test;

use onsa\ReflectiveTestCase\TestCase;
use Goutte\Client;

use onsa\FatSecretCrawler\FatSecretCrawler;
use onsa\FatSecretCrawler\FatSecretIngredient as Ingredient;
use onsa\FatSecretCrawler\FatSecretMeasurement as Measurement;
use onsa\FatSecretCrawler\FatSecretMacro as Macro;

class FatSecretCrawlerTest extends TestCase
{
  /**
   * FatSecretCrawler should be created.
   *
   * @return object
   */
  public function testFatSecretCreated()
  {
			$fatSecret = new FatsecretCrawler('beans', 10);
      $this->assertTrue($fatSecret instanceof FatSecretCrawler);
			$this->assertObjectHasAttribute('pageCount', $fatSecret);
			$this->assertObjectHasAttribute('summaryCrawlers', $fatSecret);
			$this->assertObjectHasAttribute('firstSummaryUrl', $fatSecret);
			$this->assertEquals('http://www.fatsecret.co.uk/calories-nutrition/search?q=beans', $fatSecret->firstSummaryUrl);
			return $fatSecret;
  }

	/**
   * Method imperialise should return converted amounts only for imperial measurements.
   *
	 * @depends testFatSecretCreated
   * @return void
   */
	public function testImperialise(FatSecretCrawler $fatSecret)
	{
			//	set up test object
			$testObject1 = new Measurement(100, 'ml');
			$testObject1->addProperty('calorie', 1345);
			$testObject1->addProperty('fat', 35);
			$testObject1->addProperty('carbohydrate', 40);
			$testObject1->addProperty('sugar', 25);
			$testObject1->addProperty('protein', 50);
			//	call method to imperialise measurement
			$imperialisedTestObject1 = $this->invokeMethod($fatSecret, 'imperialise', array($testObject1));

			//	set up another test object
			$testObject2 = new Measurement(10, 'fl oz');
			$testObject2->addProperty('calorie', 1345);
			$testObject2->addProperty('fat', 35);
			$testObject2->addProperty('carbohydrate', 40);
			$testObject2->addProperty('protein', 50);
			//	call method to imperialise measurement
			$imperialisedTestObject2 = $this->invokeMethod($fatSecret, 'imperialise', array($testObject2));

			//	expect no change when metric volume given
			$this->assertEquals(1345, $imperialisedTestObject1->calorie->amount);
			$this->assertEquals(35, $imperialisedTestObject1->fat->amount);
			$this->assertEquals(40, $imperialisedTestObject1->carbohydrate->amount);
			$this->assertEquals(25, $imperialisedTestObject1->sugar->amount);
			$this->assertEquals(50, $imperialisedTestObject1->protein->amount);

			//	expect changes when imperial volume given
			$this->assertEquals(1399.9298, round($imperialisedTestObject2->calorie->amount, 4));
			$this->assertEquals(36.4294, round($imperialisedTestObject2->fat->amount, 4));
			$this->assertEquals(41.6336, round($imperialisedTestObject2->carbohydrate->amount, 4));
			$this->assertEquals(null, $imperialisedTestObject2->sugar);
			$this->assertEquals(52.042, round($imperialisedTestObject2->protein->amount, 4));
	}

	/**
	 * Method parseFraction should return float when fraction string passed and NaN when gibberish.
	 *
	 * @depends testFatSecretCreated
	 * @return void
	 */
	public function testParseFraction(FatSecretCrawler $fatSecret)
	{
		$fractionString = '1/2';
		$float = $this->invokeMethod($fatSecret, 'parseFraction', array($fractionString));
		$this->assertEquals(1 / 2, $float);

		$fractionString = '3/4';
		$float = $this->invokeMethod($fatSecret, 'parseFraction', array($fractionString));
		$this->assertEquals(3 / 4, $float);

		$fractionString = '7/8';
		$float = $this->invokeMethod($fatSecret, 'parseFraction', array($fractionString));
		$this->assertEquals(7 / 8, $float);

		$fractionString = 'd/f';
		$float = $this->invokeMethod($fatSecret, 'parseFraction', array($fractionString));
		$this->assertNan($float);
	}

	/**
   * Method standardise should return calories / (ml | g) for measurement.
   *
	 * @depends testFatSecretCreated
   * @return void
   */
	public function testStandardise(FatSecretCrawler $fatSecret)
	{
		$standard = $this->invokeMethod($fatSecret, 'standardise', array(52, new Measurement(100, 'g')));
		$this->assertEquals(0.52, $standard);
		$standard = $this->invokeMethod($fatSecret, 'standardise', array(54, new Measurement(100, 'ml')));
		$this->assertEquals(0.54, $standard);
		$standard = $this->invokeMethod($fatSecret, 'standardise', array(100, new Measurement(10, 'lb')));
		$this->assertEquals(0.022, $standard);
		$standard = $this->invokeMethod($fatSecret, 'standardise', array(16, new Measurement(1, 'fl oz')));
		$this->assertEquals(0.5631, $standard);
	}

	/**
   * Method clearMeasurements should delete vague measurements.e.g. helping from raw summary data.
   *
	 * @depends testFatSecretCreated
   * @return void
   */
	public function testClearMeasurements(FatSecretCrawler $fatSecret)
	{
		//	set up raw summary data with an ingredient having two measurements
		$ingredient = new Ingredient('MockBean', 'MockMarket');
		$ingredient->measurements = array();

		$measurement1 = new Measurement(10, 'fl oz');
		$calorie = new Macro(132, 'kcal');
		$measurement1->calorie = $calorie;

		$measurement2 = new Measurement(3, 'helping');

		array_push($ingredient->measurements, $measurement1);
		array_push($ingredient->measurements, $measurement2);
		$fatSecret->rawSummaryData = array($ingredient);

		//	call method to clear raw summary data
			$this->invokeMethod($fatSecret, 'clearMeasurements');

		//	expect the number of measurements to be 1
		$this->assertEquals(1, count($fatSecret->rawSummaryData[0]->measurements));
		//	expect the retained measurement unit to be 'fl oz'
		$this->assertEquals('fl oz', $fatSecret->rawSummaryData[0]->measurements[0]->unit);
	}

	/**
	 * Method clearMeasurements should return Measurement or Macro object with properties amount and unit containing the right value.
	 *
	 * @depends testFatSecretCreated
	 * @return Measurement
	 */
	public function testgetMeasurementObject(FatSecretCrawler $fatSecret)
	{
		$measurementString = '13 kcal';

		$measurementObject = $this->invokeMethod($fatSecret, 'getMeasurementObject', array($measurementString, false));
		$this->assertInstanceOf(Measurement::class, $measurementObject);
		$this->assertObjectHasAttribute('amount', $measurementObject);
		$this->assertObjectHasAttribute('unit', $measurementObject);

		$macroString = '0.8 g';
		$macroObject = $this->invokeMethod($fatSecret, 'getMeasurementObject', array($macroString, true));
		$this->assertInstanceOf(Macro::class, $macroObject);

		$this->assertEquals(13, $measurementObject->amount);
		$this->assertEquals('kcal', $measurementObject->unit);

		return $measurementObject;
	}

	/**
	 * Method appendMacros should return a Measurement object appended with macros.
	 *
	 * @depends testFatSecretCreated
	 * @depends testgetMeasurementObject
	 * @return void
	 */
	public function testAppendMacros(FatsecretCrawler $fatSecret, Measurement $measurementObject)
	{
		$macroLine = 'Calories: 24kcal | Fat: 1.20g | Carbs: 1.40g | Prot: 2.00g';
		$measurementObject = $this->invokeMethod($fatSecret, 'appendMacros', array($measurementObject, $macroLine));
		$this->assertInstanceOf(Measurement::class, $measurementObject);
		$this->assertObjectHasAttribute('calorie', $measurementObject);
		$this->assertInstanceOf(Macro::class, $measurementObject->calorie);
		$this->assertObjectHasAttribute('fat', $measurementObject);
		$this->assertInstanceOf(Macro::class, $measurementObject->fat);
		$this->assertObjectHasAttribute('sugar', $measurementObject);
		$this->assertEquals(null, $measurementObject->sugar);
		$this->assertObjectHasAttribute('carbohydrate', $measurementObject);
		$this->assertInstanceOf(Macro::class, $measurementObject->carbohydrate);
		$this->assertObjectHasAttribute('protein', $measurementObject);
		$this->assertInstanceOf(Macro::class, $measurementObject->protein);
	}

	/**
	 * Method getAlternativeCalories should return a Measurement object with appended calorie object.
	 *
	 * @depends testFatSecretCreated
	 * @return void
	 */
	public function testGetAlternativeCalories(FatsecretCrawler $fatSecret)
	{
		$mixedSummaryLine = '1 cup - 103kcal';
		$measurementObject = $this->invokeMethod($fatSecret, 'getAlternativeCalories', array($mixedSummaryLine));
		$this->assertInstanceOf(Measurement::class, $measurementObject);
		$this->assertObjectHasAttribute('calorie', $measurementObject);
		$this->assertInstanceOf(Macro::class, $measurementObject->calorie);
		$this->assertEquals(103, $measurementObject->calorie->amount);
		$this->assertEquals('kcal', $measurementObject->calorie->unit);
	}

	/**
	 * Method parseRestMixedSummaryLines should return array with Measurement objects.
	 *
	 * @depends testFatSecretCreated
	 * @return void
	 */
	public function testParseRestMixedSummaryLines(FatsecretCrawler $fatSecret)
	{
		$mixedSummaryLines = array(
			"\t\t\t\t\t\t\t\t\t\t\t\t\tOther sizes:\r",
			"\t\t\t\t\t\t\t\t\t\t\t\t\t1 cup - 103kcal\r",
			"\t\t\t\t\t\t\t\t\t\t\t, 1 serving - 103kcal\r",
			"\t\t\t\t\t\t\t\t\t\t\t, 100 g - 42kcal\r",
			"\t\t\t\t\t\t\t\t\t\t\t, more...\r",
			"\t\t\t\t\t\t\t\t\t\t   Nutrition Facts - Similar\r"
		);
		$alternativeMeasurementArray = $this->invokeMethod($fatSecret, 'parseRestMixedSummaryLines', array($mixedSummaryLines));
		$this->assertInternalType('array', $alternativeMeasurementArray);
		$this->assertEquals(3, count($alternativeMeasurementArray));
		foreach($alternativeMeasurementArray as $alternativeMeasurement) {
			$this->assertInstanceOf(Measurement::class, $alternativeMeasurement);
		}
	}

	/**
	 * Method parseFirstMixedSummaryLine should return array of Measurement object.
	 *
	 * @depends testFatSecretCreated
	 * @return void
	 */
	public function testParseFirstMixedSummaryLine(FatsecretCrawler $fatSecret)
	{
		$firstMixedSummaryLine = "\t\t\t\t\t\t\t\t\t\tper 100g - Calories: 24kcal | Fat: 1.20g | Carbs: 1.40g | Prot: 2.00g\r";
		$measurementArray = $this->invokeMethod($fatSecret, 'parseFirstMixedSummaryLine', array($firstMixedSummaryLine));
		$this->assertInternalType('array', $measurementArray);
		$this->assertEquals(1, count($measurementArray));
		$this->assertInstanceOf(Measurement::class, $measurementArray[0]);
	}

	/**
	 * Method extractMixedSummary should return array wtih all measurements included.
	 *
	 * @depends testFatSecretCreated
	 * @return void
	 */
	public function testExtractMixedSummary(FatsecretCrawler $fatSecret)
	{
		$mixedSummaryText =
		"
			\n
			\t\t\t\t\t\t\t\t\t\tper 1 fl oz - Calories: 13kcal | Fat: 0.51g | Carbs: 1.12g | Prot: 0.99g\n
			\t\t\t\t\t\t\t\t\t\t\n
			\t\t\t\t\t\t\t\t\t\t\t\t\tOther sizes:\n
			\t\t\t\t\t\t\t\t\t\t\t\t\t1 cup - 103kcal\n
			\t\t\t\t\t\t\t\t\t\t\t, 1 serving - 103kcal\n
			\t\t\t\t\t\t\t\t\t\t\t, 100 g - 42kcal\n
			\t\t\t\t\t\t\t\t\t\t\t\n
			\t\t\t\t\t\t\t\t\t\t\t, more...\n
			\t\t\t\t\t\t\t\t\t\t\n
			\t\t\t\t\t\t\t\t\t\t   Nutrition Facts - Similar\n
			\t\t\t\t\t\t\t\t\t
		";
		$measurementArray = $this->invokeMethod($fatSecret, 'extractMixedSummary', array($mixedSummaryText));
		$this->assertInternalType('array', $measurementArray);
		$this->assertEquals(4, count($measurementArray));
		foreach($measurementArray as $measurement) {
			$this->assertInstanceOf(Measurement::class, $measurement);
		}
	}

	/**
	 * Method getDensity should return type of measurement (volume or mass).
	 *
	 * @depends testFatSecretCreated
	 * @return void
	 */
	public function testGetDensity(FatsecretCrawler $fatSecret)
	{
		//	milk density
		$firstMeasurement = new Measurement(100, 'ml');
		$firstMeasurement->calorie = new Macro(52, 'kcal');
		$secondMeasurement = new Measurement(100, 'g');
		$secondMeasurement->calorie = new Macro(50, 'kcal');
		$density = $this->invokeMethod($fatSecret, 'getDensity', array($firstMeasurement, $secondMeasurement));
		$this->assertEquals(1.04, $density);

		//	flour density
		$firstMeasurement = new Measurement(1, 'oz');
		$firstMeasurement->calorie = new Macro(103, 'kcal');
		$secondMeasurement = new Measurement(1, 'tbsp');
		$secondMeasurement->calorie = new Macro(28, 'kcal');
		$density = $this->invokeMethod($fatSecret, 'getDensity', array($firstMeasurement, $secondMeasurement));
		$this->assertEquals(0.4342, $density);
	}

	/**
	 * Method getMeasurementType should return type of measurement (volume or mass).
	 *
	 * @depends testFatSecretCreated
	 * @return void
	 */
	public function testGetMeasurementType(FatsecretCrawler $fatSecret)
	{
		$measurementType = $this->invokeMethod($fatSecret, 'getMeasurementType', array('g'));
		$this->assertEquals('mass', $measurementType);
		$measurementType = $this->invokeMethod($fatSecret, 'getMeasurementType', array('fl oz'));
		$this->assertEquals('volume', $measurementType);
		$measurementType = $this->invokeMethod($fatSecret, 'getMeasurementType', array('helping'));
		$this->assertEquals(null, $measurementType);
	}

	/**
	 * Method lookForDensity should return array.
	 *
	 * @depends testFatSecretCreated
	 * @return void
	 */
	public function testLookForDensity(FatsecretCrawler $fatSecret)
	{
		//	test with different measurement types
		$recordObject = new Ingredient('MockBean', 'MockMarket');
		$measurement1 = new Measurement(1, 'cup');
		$measurement1->calorie = new Macro(1909, 'kcal');
		$recordObject->measurements[] = $measurement1;
		$measurement2 = new Measurement(100, 'g');
		$measurement2->calorie = new Macro(884, 'kcal');
		$recordObject->measurements[] = $measurement2;
		$density = $this->invokeMethod($fatSecret, 'lookForDensity', array($recordObject));
		$this->assertEquals(0.7604, $density);

		//	test with same measurement types
		$recordObject = new Ingredient('MockWheat');
		$measurement1 = new Measurement(10, 'fl oz');
		$measurement1->calorie = new Macro(100, 'kcal');
		$recordObject->measurements[] = $measurement1;
		$measurement2 = new Measurement(1, 'l');
		$measurement2->calorie = new Macro(1300, 'kcal');
		$recordObject->measurements[] = $measurement2;
		$density = $this->invokeMethod($fatSecret, 'lookForDensity', array($recordObject));
		$this->assertNull($density);
	}

	/**
	 * Method getSugar should return a Macro for sugar content.
	 *
	 * @depends testFatSecretCreated
	 * @return void
	 */
	public function testGetSugar(FatsecretCrawler $fatSecret)
	{
		//	test successfully extracted sugar
		$detailedUrl = '/calories-nutrition/morrisons/soya-drink/100g';
		$sugarObject = $this->invokeMethod($fatSecret, 'getSugar', array($detailedUrl));
		$this->assertInstanceOf(Macro::class, $sugarObject);
		$this->assertEquals(0.2, $sugarObject->amount);

		//	test lack of sugar data
		$detailedUrl = '/calories-nutrition/generic/baked-beans';
		$sugarObject = $this->invokeMethod($fatSecret, 'getSugar', array($detailedUrl));
		$this->assertInstanceOf(Macro::class, $sugarObject);
		$this->assertEquals(0, $sugarObject->amount);
	}

	/**
	 * Method crawlSummary should return array record with prominent, brand and an array of measurements.
	 *
	 * @depends testFatSecretCreated
	 * @return void
	 */
	public function testCrawlSummary(FatsecretCrawler $fatSecret)
	{
		$summaryElement = $fatSecret->summaryCrawlers[0]->filter('table.generic.searchResult tr td')->first();
		$recordObject = $this->invokeMethod($fatSecret, 'crawlSummary', array($summaryElement));
		$this->assertInstanceOf(Ingredient::class, $recordObject);
		$this->assertObjectHasAttribute('prominent', $recordObject);
		$this->assertObjectHasAttribute('brand', $recordObject);
		$this->assertObjectHasAttribute('measurements', $recordObject);
		$this->assertInternalType('array', $recordObject->measurements);
	}

	/**
	 * Method crawlPage should return array .
	 *
	 * @depends testFatSecretCreated
	 * @return void
	 */
	public function testCrawlPage(FatsecretCrawler $fatSecret)
	{
		$crawler = $fatSecret->summaryCrawlers[0];
		$rawSummaryData = $this->invokeMethod($fatSecret, 'crawlPage', array($crawler));
		$this->assertEquals(10, count($rawSummaryData));
		foreach($rawSummaryData as $rawSummaryUnit) {
			$this->assertInstanceOf(Ingredient::class, $rawSummaryUnit);
		}
	}

	/**
	 * Method search should return array.
	 *
	 * @return void
	 */
	public function testSearch()
	{
		$hitCountArray = [1, 5, 10, 13, 17];
		foreach($hitCountArray as $hitCount) {
			$fatSecret = new FatsecretCrawler('beans', $hitCount);
			$rawSummaryData = $this->invokeMethod($fatSecret, 'search');
			$this->assertEquals($hitCount, count($rawSummaryData));
		}
	}
}
