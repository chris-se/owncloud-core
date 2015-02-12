<?php
/**
 * Copyright (c) 2013 Thomas Tanghus (thomas@tanghus.net)
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

class Test_VObject extends \Test\TestCase {

	/**
	 * @var \Sabre\VObject\Component
	 */
	private $component;

	protected function setUp() {
		parent::setUp();

		$this->component = $this->getMockBuilder('\Sabre\VObject\Component')
			->disableOriginalConstructor()
			->getMock();
	}


	function testStringProperty() {
		$property = new \OC\VObject\StringProperty($this->component, 'SUMMARY', 'Escape;this,please');
		$this->assertEquals("SUMMARY:Escape\;this\,please\r\n", $property->serialize());
	}

	function testCompoundProperty() {

		$arr = array(
			'ABC, Inc.',
			'North American Division',
			'Marketing;Sales',
		);

		$property = new \OC\VObject\CompoundProperty($this->component, 'ORG');
		$property->setParts($arr);

		$this->assertEquals('ABC\, Inc.;North American Division;Marketing\;Sales', $property->getValue());
		$this->assertEquals('ORG:ABC\, Inc.;North American Division;Marketing\;Sales' . "\r\n", $property->serialize());
		$this->assertEquals(3, count($property->getParts()));
		$parts = $property->getParts();
		$this->assertEquals('Marketing;Sales', $parts[2]);
	}
}
