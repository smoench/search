<?php

/**
 * This file is part of the RollerworksRecordFilterBundle.
 *
 * (c) Sebastiaan Stok <s.stok@rollerscapes.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Rollerworks\RecordFilterBundle\Tests;

use Rollerworks\RecordFilterBundle\Type\Decimal;

class DecimalTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @dataProvider getDataForSanitation
     */
    public function testSanitize($locale, $input, $expected, $expectFail = false)
    {
        if ($expectFail) {
            return;
        }

        \Locale::setDefault($locale);

        $type = new Decimal();

        $this->assertEquals($expected, $type->sanitizeString($input));
    }

    /**
     * @dataProvider getDataForSanitation
     */
    public function testValidation($locale, $input, $expected, $expectFail = false)
    {
        \Locale::setDefault($locale);

        $type = new Decimal();

        if ($expectFail) {
            $this->assertFalse($type->validateValue($input));
        } else {
            $this->assertTrue($type->validateValue($input));
        }
    }

    /**
     * @dataProvider getDataForCompare
     */
    public function testCompares($first, $second, $comparison = null)
    {
        $type = new Decimal();

        if ('==' === $comparison) {
            $this->assertTrue($type->isEquals($first, $second));
        } elseif ('!=' === $comparison) {
            $this->assertFalse($type->isEquals($first, $second));
        } else {
            $this->assertTrue($type->isLower($second, $first));
            $this->assertFalse($type->isLower($first, $second));

            $this->assertFalse($type->isHigher($second, $first));
            $this->assertTrue($type->isHigher($first, $second));
        }
    }

    /**
     * @dataProvider getDataForGetHigherValue
     */
    public function testGetHigherValue($input, $expected)
    {
        $type = new Decimal();
        $this->assertEquals($expected, $type->getHigherValue($input));
    }

    public static function getDataForSanitation()
    {
        return array(
            // $locale, $input, $expected, $expectFail
            array('nl_NL', '100,10', '100.10'),
            array('nl_NL', '100,10', '100.10'),

            array('en_US', '100.00', '100.00'),
            array('uz_Arab', '۵٫۵', '05.50'),
        );
    }

    public static function getDataForCompare()
    {
        return array(
            // $first (higher), $second (lower), $comparison
            array('100.00', '100.00',  '=='),
            array('100.10', '100.10',  '=='),
            array('100.10', '100.1',   '=='),
            array('100.00', '100.000', '=='),
            array('3000000000000000000000.00', '3000000000000000000000.000', '=='),
            array('200.00', '200.10',  '!='),
            array('200.00', '300.00',  '!='),
            array('3000000000000000000000.00', '2000000000000000000000.00',  '!='),

            array('300.00', '200.00'),
            array('300.00', '200.00'),
            array('200.1',  '200.01'),
            array('3000000000000000000000.00', '2000000000000000000000.00'),
        );
    }

    public static function getDataForGetHigherValue()
    {
        return array(
            // $input, $expected
            array('100.01', '100.02'),
            array('100.01', '100.02'),
            array('100.00', '100.01'),
            array('1000000000000000000000000000.00', '1000000000000000000000000000.01'),
        );
    }
}
